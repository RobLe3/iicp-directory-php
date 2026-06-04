<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeEvent;
use App\Services\NodeAddressObserver;
use App\Services\NodeEventLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P6-2.2b — NodeAddressObserver emits OPERATOR_OBSERVED when
 * the IICP-SEC-GEO-01 rule trips (private IP + non-local region).
 */
class NodeAddressObserverEmissionTest extends TestCase
{
    use RefreshDatabase;

    private NodeAddressObserver $obs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->obs = new NodeAddressObserver(new NodeEventLogger);
    }

    private function makeNode(string $region = 'eu-central'): Node
    {
        return Node::create([
            'id' => '550e8400-e29b-41d4-a716-'.str_pad(dechex(random_int(0, 0xFFFFFFFFFFFF)), 12, '0', STR_PAD_LEFT),
            'endpoint' => 'https://x.test',
            'region' => $region,
            'node_token_hash' => 'h',
            'max_concurrent' => 1,
            'tokens_per_min' => 100,
            'available' => true,
        ]);
    }

    public function test_emits_operator_observed_when_private_ip_with_public_region(): void
    {
        $node = $this->makeNode('eu-central');
        $this->obs->observe($node, '10.0.0.5', 'register');

        $events = NodeEvent::where('event_type', 'OPERATOR_OBSERVED')->where('node_id', $node->id)->get();
        $this->assertCount(1, $events);
        $ev = $events->first();
        $this->assertSame('private_ip_public_region', $ev->payload['observation_type']);
        $this->assertSame('medium', $ev->payload['severity']);
        $this->assertSame('IICP-SEC-GEO-01', $ev->payload['evidence']['rule_id']);
        $this->assertSame('eu-central', $ev->payload['evidence']['claimed']);
        $this->assertSame('NodeAddressObserver', $ev->payload['evidence']['source']);
    }

    public function test_no_emission_when_public_ip(): void
    {
        $node = $this->makeNode('eu-central');
        $this->obs->observe($node, '8.8.8.8', 'register');
        $this->assertSame(0, NodeEvent::where('event_type', 'OPERATOR_OBSERVED')->count());
    }

    public function test_no_emission_when_local_region(): void
    {
        $node = $this->makeNode('dev');
        $this->obs->observe($node, '10.0.0.5', 'heartbeat');
        $this->assertSame(0, NodeEvent::where('event_type', 'OPERATOR_OBSERVED')->count());
    }

    public function test_works_without_event_logger(): void
    {
        // Backwards compat: passing no logger keeps the soft-warning path working
        // without emitting events (used by isolated unit tests).
        $obs = new NodeAddressObserver;
        $node = $this->makeNode('eu-central');
        $obs->observe($node, '10.0.0.5', 'register');
        $this->assertSame(0, NodeEvent::where('event_type', 'OPERATOR_OBSERVED')->count());
    }
}

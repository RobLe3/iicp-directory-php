<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Services\NodeAddressObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase A.1 (blend §2.2 / issues 5.3 + 5.8) — node_address_history retention.
 *
 * Verifies the dual-bound policy: keep at most N=5 most-recent distinct IPs
 * per node OR rows newer than 30 days, whichever bounds first.
 */
class NodeAddressHistoryRetentionTest extends TestCase
{
    use RefreshDatabase;

    private NodeAddressObserver $obs;

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();
        $this->obs = new NodeAddressObserver;
        $this->node = Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440100',
            'endpoint' => 'https://retention.test',
            'region' => 'eu-central',
            'node_token_hash' => 'h',
            'max_concurrent' => 1,
            'tokens_per_min' => 100,
            'available' => true,
        ]);
    }

    public function test_first_observation_inserts_one_row(): void
    {
        $this->obs->observe($this->node, '8.8.8.8', 'register');
        $this->assertSame(1, DB::table('node_address_history')->where('node_id', $this->node->id)->count());
    }

    public function test_same_ip_does_not_insert_duplicate(): void
    {
        $this->obs->observe($this->node, '8.8.8.8', 'register');
        $this->obs->observe($this->node, '8.8.8.8', 'heartbeat');
        $this->obs->observe($this->node, '8.8.8.8', 'heartbeat');
        $this->assertSame(1, DB::table('node_address_history')->where('node_id', $this->node->id)->count());
    }

    public function test_distinct_ips_each_insert_one_row(): void
    {
        $this->obs->observe($this->node, '8.8.8.8', 'register');
        $this->obs->observe($this->node, '1.1.1.1', 'heartbeat');
        $this->obs->observe($this->node, '9.9.9.9', 'heartbeat');
        $this->assertSame(3, DB::table('node_address_history')->where('node_id', $this->node->id)->count());
    }

    public function test_count_cap_drops_oldest_when_more_than_n(): void
    {
        // Insert 7 distinct IPs — should retain exactly N=5 most recent
        foreach (['1.1.1.1', '2.2.2.2', '3.3.3.3', '4.4.4.4', '5.5.5.5', '6.6.6.6', '7.7.7.7'] as $ip) {
            $this->obs->observe($this->node, $ip, 'heartbeat');
        }
        $this->assertSame(
            NodeAddressObserver::ADDRESS_HISTORY_MAX_ROWS,
            DB::table('node_address_history')->where('node_id', $this->node->id)->count(),
        );
        // The 2 oldest (1.1.1.1, 2.2.2.2) should be gone; 5 most recent kept
        $remaining = DB::table('node_address_history')
            ->where('node_id', $this->node->id)
            ->pluck('ip_address')
            ->toArray();
        $this->assertNotContains('1.1.1.1', $remaining);
        $this->assertNotContains('2.2.2.2', $remaining);
        $this->assertContains('7.7.7.7', $remaining);
    }

    public function test_age_cap_drops_rows_older_than_30_days(): void
    {
        // Insert an old IP, then force its observed_at back 31 days
        $this->obs->observe($this->node, '10.0.0.1', 'register');
        DB::table('node_address_history')
            ->where('node_id', $this->node->id)
            ->where('ip_address', '10.0.0.1')
            ->update(['observed_at' => now()->subDays(31)]);

        // A new observation triggers prune
        $this->obs->observe($this->node, '20.0.0.1', 'heartbeat');

        $remaining = DB::table('node_address_history')
            ->where('node_id', $this->node->id)
            ->pluck('ip_address')
            ->toArray();
        $this->assertNotContains('10.0.0.1', $remaining, '31d-old row must be pruned by age cap');
        $this->assertContains('20.0.0.1', $remaining);
    }

    public function test_age_cap_preserves_rows_within_30_days(): void
    {
        $this->obs->observe($this->node, '10.0.0.1', 'register');
        // Age this row 29 days — still within window
        DB::table('node_address_history')
            ->where('node_id', $this->node->id)
            ->update(['observed_at' => now()->subDays(29)]);

        $this->obs->observe($this->node, '20.0.0.1', 'heartbeat');

        $remaining = DB::table('node_address_history')
            ->where('node_id', $this->node->id)
            ->pluck('ip_address')
            ->toArray();
        $this->assertContains('10.0.0.1', $remaining, '29d-old row must NOT be pruned (within 30d window)');
        $this->assertContains('20.0.0.1', $remaining);
    }

    public function test_prune_is_per_node(): void
    {
        // Other node's history must not be affected by retention on this node
        $otherNode = Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440101',
            'endpoint' => 'https://other.test',
            'region' => 'eu',
            'node_token_hash' => 'h',
            'max_concurrent' => 1,
            'tokens_per_min' => 100,
            'available' => true,
        ]);
        $this->obs->observe($otherNode, '99.99.99.99', 'register');

        // Now blow past the count cap on the primary node
        foreach (['1.1.1.1', '2.2.2.2', '3.3.3.3', '4.4.4.4', '5.5.5.5', '6.6.6.6', '7.7.7.7'] as $ip) {
            $this->obs->observe($this->node, $ip, 'heartbeat');
        }

        // Other node still has its one row
        $this->assertSame(1, DB::table('node_address_history')->where('node_id', $otherNode->id)->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeregisterTest extends TestCase
{
    use RefreshDatabase;

    private string $plainToken;

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['https://node.example.com/iicp/health' => Http::response('ok', 200)]);

        $this->plainToken = Str::random(40);

        $this->node = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash($this->plainToken, PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
        ]);
    }

    public function test_deregister_removes_node_and_returns_success(): void
    {
        $nodeId = $this->node->id;

        $this->withToken($this->plainToken)
            ->deleteJson('/api/v1/register', ['node_id' => $nodeId])
            ->assertStatus(200)
            ->assertJsonPath('deregistered', true);

        $this->assertNull(Node::find($nodeId), 'Node must be deleted after deregistration');
    }

    // IICP-E003: node_not_found branch — invalid token, middleware blocks but
    // controller defensive check covers the case where _authenticated_node is absent.
    public function test_deregister_with_no_token_returns_401(): void
    {
        $this->deleteJson('/api/v1/register', ['node_id' => $this->node->id])
            ->assertStatus(401);
    }

    public function test_deregister_with_wrong_token_returns_401(): void
    {
        $this->withToken('wrong-token-value-that-will-never-match')
            ->deleteJson('/api/v1/register', ['node_id' => $this->node->id])
            ->assertStatus(401);
    }

    // Deregister emits a DEREGISTER event log entry (Phase 6 prereq — spec §3.4).
    public function test_deregister_emits_event_log_entry(): void
    {
        $nodeId = $this->node->id;

        $this->withToken($this->plainToken)
            ->deleteJson('/api/v1/register', ['node_id' => $nodeId])
            ->assertStatus(200);

        $event = NodeEvent::where('node_id', $nodeId)
            ->where('event_type', 'DEREGISTER')
            ->first();

        $this->assertNotNull($event, 'DEREGISTER event must be logged before deletion');
        $this->assertSame('https://node.example.com', $event->payload['endpoint']);
        $this->assertSame('eu-central', $event->payload['region']);
    }
}

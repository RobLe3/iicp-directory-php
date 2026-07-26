<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2 mesh: POST /v1/peers gossip exchange (requires NodeTokenAuth).
 */
class PeersTest extends TestCase
{
    use RefreshDatabase;

    private string $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        // Caller node — JWT auth (NodeTokenAuth Phase 2 path)
        $caller = $this->makeNode();
        $this->jwt = app(JwtService::class)->issueNode($caller->id);
    }

    private function makeNode(array $overrides = []): Node
    {
        return Node::create(array_merge([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('test-token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now()->subSeconds(5),
            'observed_source_ip' => '127.0.0.1',
        ], $overrides));
    }

    public function test_peers_endpoint_returns_healthy_nodes(): void
    {
        // 3 additional nodes besides the caller
        $this->makeNode();
        $this->makeNode();
        $this->makeNode();

        $response = $this->withToken($this->jwt)->postJson('/api/v1/peers', []);

        $response->assertStatus(200);
        // Response includes all 4 nodes (caller included) or 3 (excludes caller) — both valid
        $this->assertGreaterThanOrEqual(3, $response->json('count'));
    }

    public function test_peers_endpoint_excludes_known_peers_from_response(): void
    {
        $nodeA = $this->makeNode();
        $nodeB = $this->makeNode();
        $nodeC = $this->makeNode();

        $response = $this->withToken($this->jwt)->postJson('/api/v1/peers', [
            'known_peers' => [$nodeA->id, $nodeB->id],
        ]);

        $response->assertStatus(200);
        $ids = array_column($response->json('peers'), 'node_id');
        $this->assertContains($nodeC->id, $ids);
        $this->assertNotContains($nodeA->id, $ids);
        $this->assertNotContains($nodeB->id, $ids);
    }

    public function test_peers_endpoint_excludes_expired_nodes(): void
    {
        $this->makeNode(['last_seen' => now()->subSeconds(120)]);

        $response = $this->withToken($this->jwt)->postJson('/api/v1/peers', []);

        $response->assertStatus(200);
        // Only the caller node (created in setUp with last_seen=now-5s) is returned
        $this->assertLessThanOrEqual(1, $response->json('count'));
    }

    public function test_peers_endpoint_rejects_invalid_node_ids_in_known_peers(): void
    {
        // Truly invalid: starts with a dash (not alphanumeric), exceeds 36 chars,
        // or contains forbidden characters like spaces.
        $this->withToken($this->jwt)
            ->postJson('/api/v1/peers', ['known_peers' => ['-invalid-start']])
            ->assertStatus(422);

        $this->withToken($this->jwt)
            ->postJson('/api/v1/peers', ['known_peers' => [str_repeat('a', 37)]])
            ->assertStatus(422);
    }

    public function test_peers_endpoint_requires_auth(): void
    {
        $this->postJson('/api/v1/peers', [])
            ->assertStatus(401);
    }
}

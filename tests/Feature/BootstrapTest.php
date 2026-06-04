<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BootstrapTest extends TestCase
{
    use RefreshDatabase;

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
            'last_seen' => now(),
            'observed_source_ip' => '127.0.0.1',
        ], $overrides));
    }

    public function test_bootstrap_returns_empty_list_when_no_healthy_nodes(): void
    {
        $response = $this->getJson('/api/v1/bootstrap');

        $response->assertStatus(200)
            ->assertJson(['peers' => [], 'count' => 0]);
    }

    public function test_bootstrap_returns_healthy_nodes(): void
    {
        $this->makeNode(['last_seen' => now()->subSeconds(30)]);
        $this->makeNode(['last_seen' => now()->subSeconds(10)]);

        $response = $this->getJson('/api/v1/bootstrap');

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('count'));
        $this->assertArrayHasKey('node_id', $response->json('peers.0'));
        $this->assertArrayHasKey('endpoint', $response->json('peers.0'));
        $this->assertArrayHasKey('region', $response->json('peers.0'));
        $this->assertArrayHasKey('last_seen', $response->json('peers.0'));
    }

    public function test_bootstrap_excludes_expired_nodes(): void
    {
        $this->makeNode(['last_seen' => now()->subSeconds(120)]);

        $response = $this->getJson('/api/v1/bootstrap');

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('count'));
    }

    public function test_bootstrap_excludes_unavailable_nodes(): void
    {
        $this->makeNode(['available' => false]);

        $response = $this->getJson('/api/v1/bootstrap');

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('count'));
    }

    public function test_bootstrap_respects_limit_parameter(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->makeNode(['last_seen' => now()->subSeconds($i)]);
        }

        $response = $this->getJson('/api/v1/bootstrap?limit=3');

        $response->assertStatus(200);
        $this->assertSame(3, $response->json('count'));
    }

    public function test_bootstrap_rejects_limit_above_max(): void
    {
        $this->getJson('/api/v1/bootstrap?limit=50')
            ->assertStatus(422);
    }
}

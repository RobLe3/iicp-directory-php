<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/v1/node/{id} — per-node health block (ADR-044 #372).
 *
 * Confirms the node-detail endpoint composes and exposes the per-node health
 * vector and surfaces exposure_mode (the field stored but previously never
 * serialized — the WQ-PM-DIR-002 live-verify gap).
 */
class NodeDetailHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_node_detail_includes_health_block(): void
    {
        $node = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('tok', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'public_reachable' => true,
            'reputation_score' => 0.95,
            'tasks_total' => 100,
            'tasks_failed' => 0,
            'avg_latency_ms' => 40.0,
            'exposure_mode' => 'ipv4_public_direct',
        ]);

        $response = $this->getJson("/api/v1/node/{$node->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'health' => ['score', 'label', 'observed', 'components' => [
                    'liveness', 'reachability', 'latency', 'success_rate', 'reputation',
                ], 'evaluated_at'],
            ])
            ->assertJsonPath('health.label', 'healthy')
            ->assertJsonPath('exposure_mode', 'ipv4_public_direct');
    }

    public function test_offline_node_detail_reports_offline_health(): void
    {
        $node = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('tok', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now()->subMinutes(10),
            'public_reachable' => true,
        ]);

        $this->getJson("/api/v1/node/{$node->id}")
            ->assertStatus(200)
            ->assertJsonPath('health.label', 'offline')
            ->assertJsonPath('health.score', 0);
    }
}

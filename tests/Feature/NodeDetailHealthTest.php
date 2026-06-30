<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use App\Models\TelemetryProbe;
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

    private function seedReachabilityProbe(string $nodeId, float $latencyMs = 40.0): void
    {
        TelemetryProbe::create([
            'probe_token_id' => null,
            'node_id' => $nodeId,
            'run_id' => (string) Str::uuid(),
            'probe_id' => 'node-detail-health',
            'probe_type' => 'reachability',
            'test_id' => 'REACH-01',
            'level' => 'MUST',
            'passed' => true,
            'latency_ms' => $latencyMs,
            'detail' => 'test',
            'metadata' => [],
            'probed_at' => now(),
        ]);
    }

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
        $this->seedReachabilityProbe($node->id, 40.0);

        $response = $this->getJson("/api/v1/node/{$node->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'health' => ['score', 'label', 'observed', 'confidence', 'evidence_level', 'latency_ms_basis', 'components' => [
                    'liveness', 'reachability', 'latency', 'uptime', 'stability', 'freshness',
                ], 'evaluated_at'],
                'directory_observed_reachable',
                'route_evidence',
                'routing_hint',
                'browser_usable',
                'performance' => ['task_latency_ms', 'task_latency_ms_basis', 'health_impact'],
                'capability_summary' => ['model_count_registered', 'model_count_live', 'model_family_count', 'modalities', 'quality_evidence'],
            ])
            ->assertJsonPath('health.label', 'healthy')
            ->assertJsonPath('health.confidence', 'high')
            ->assertJsonPath('health.latency_ms_basis', 'directory_probe')
            ->assertJsonPath('performance.self_reported_lifetime_latency_ms', 40)
            ->assertJsonPath('performance.health_impact', 'separate_from_operational_health')
            ->assertJsonPath('exposure_mode', 'ipv4_public_direct')
            ->assertJsonPath('route_evidence', 'directory_observed')
            ->assertJsonPath('routing_hint', 'https_direct')
            ->assertJsonPath('browser_usable', true);
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

    // #492 + updater-baseline hardening — health must not use reputation/task-success
    // as inputs, but a brand-new node with no latency evidence is capped below
    // "healthy" until evidence arrives. It remains reachable with low confidence.
    public function test_new_reachable_node_with_no_latency_evidence_is_degraded_low_confidence(): void
    {
        $node = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://fresh.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('tok2', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'public_reachable' => true,
            // No tasks_total, no reputation_score — brand-new node
        ]);

        $response = $this->getJson("/api/v1/node/{$node->id}");

        $response->assertStatus(200)
            ->assertJsonPath('health.label', 'degraded')
            ->assertJsonPath('health.score', 84)
            ->assertJsonPath('health.confidence', 'low')
            ->assertJsonPath('health.latency_ms_basis', 'none');
    }
}

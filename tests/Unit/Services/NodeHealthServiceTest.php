<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit\Services;

use App\Models\Node;
use App\Services\NodeHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NodeHealthService — ADR-044, Phase A (#372).
 *
 * Verifies the per-node health vector (offline gate, reachability tiers,
 * neutral no-data defaults) and the mesh aggregate (median, distribution,
 * insufficient_sample floor, unavailable on empty).
 */
class NodeHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private NodeHealthService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new NodeHealthService;
    }

    private function makeNode(array $overrides = []): Node
    {
        return Node::create(array_merge([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('tok', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'public_reachable' => true,
        ], $overrides));
    }

    public function test_healthy_node_scores_high(): void
    {
        $node = $this->makeNode([
            'public_reachable' => true,
            'avg_latency_ms' => 40.0,
            'tasks_total' => 100,
            'tasks_failed' => 0,
            'reputation_score' => 0.95,
        ]);

        $h = $this->svc->forNode($node);

        $this->assertSame('healthy', $h['label']);
        $this->assertGreaterThanOrEqual(85, $h['score']);
        $this->assertSame(1.0, $h['components']['liveness']);
    }

    public function test_offline_node_is_gated_to_zero(): void
    {
        $node = $this->makeNode(['last_seen' => now()->subMinutes(10)]);

        $h = $this->svc->forNode($node);

        $this->assertSame('offline', $h['label']);
        $this->assertSame(0, $h['score']);
        $this->assertNull($h['components']['reachability']);
    }

    public function test_internal_only_node_reachability_drags_score(): void
    {
        $internal = $this->svc->forNode($this->makeNode([
            'public_reachable' => false,
            'relay_capable' => false,
            'avg_latency_ms' => 40.0,
            'tasks_total' => 100,
            'tasks_failed' => 0,
            'reputation_score' => 0.95,
        ]));
        $public = $this->svc->forNode($this->makeNode([
            'public_reachable' => true,
            'avg_latency_ms' => 40.0,
            'tasks_total' => 100,
            'tasks_failed' => 0,
            'reputation_score' => 0.95,
        ]));

        $this->assertSame(0.0, $internal['components']['reachability']);
        $this->assertLessThan($public['score'], $internal['score']);
    }

    public function test_relay_capable_internal_node_gets_partial_reachability(): void
    {
        $h = $this->svc->forNode($this->makeNode([
            'public_reachable' => false,
            'relay_capable' => true,
        ]));

        $this->assertSame(0.5, $h['components']['reachability']);
    }

    public function test_no_data_node_uses_neutral_defaults(): void
    {
        // Never-served node: tasks default 0, latency columns default 0.0,
        // reputation defaults 0.5 — all of which mean "no data" → neutral 0.5.
        $h = $this->svc->forNode($this->makeNode([
            'tasks_total' => 0,
        ]));

        $this->assertSame(0.5, $h['components']['latency']);
        $this->assertSame(0.5, $h['components']['success_rate']);
        $this->assertSame(0.5, $h['components']['reputation']);
        $this->assertFalse($h['observed']);
    }

    public function test_mesh_health_unavailable_when_no_active_nodes(): void
    {
        $mesh = $this->svc->meshHealth(new Collection);

        $this->assertSame(0.0, $mesh['score']); // DIR-STATS-01: must be float[0,1], not null
        $this->assertSame('unavailable', $mesh['label']);
        $this->assertSame(0, $mesh['sample']);
    }

    public function test_mesh_health_below_min_sample_flags_insufficient(): void
    {
        $nodes = collect([
            $this->makeNode(['reputation_score' => 0.95, 'tasks_total' => 10, 'avg_latency_ms' => 40]),
            $this->makeNode(['reputation_score' => 0.95, 'tasks_total' => 10, 'avg_latency_ms' => 40]),
        ]);

        $mesh = $this->svc->meshHealth($nodes);

        $this->assertSame('insufficient_sample', $mesh['label']);
        $this->assertSame(2, $mesh['sample']);
        $this->assertNotNull($mesh['score']); // score still computed, just flagged
    }

    public function test_mesh_health_median_and_distribution(): void
    {
        // Two healthy, one critical → median is healthy, distribution counts both.
        $nodes = collect([
            $this->makeNode(['public_reachable' => true, 'reputation_score' => 0.95, 'tasks_total' => 100, 'tasks_failed' => 0, 'avg_latency_ms' => 40]),
            $this->makeNode(['public_reachable' => true, 'reputation_score' => 0.95, 'tasks_total' => 100, 'tasks_failed' => 0, 'avg_latency_ms' => 40]),
            $this->makeNode(['public_reachable' => false, 'relay_capable' => false, 'reputation_score' => 0.1, 'tasks_total' => 100, 'tasks_failed' => 90, 'avg_latency_ms' => 480]),
        ]);

        $mesh = $this->svc->meshHealth($nodes);

        $this->assertSame(3, $mesh['sample']);
        $this->assertSame('active_provider_nodes', $mesh['basis']);
        $this->assertSame(2, $mesh['distribution']['healthy']);
        $this->assertSame(1, $mesh['distribution']['critical']);
        $this->assertGreaterThanOrEqual($mesh['p10'], $mesh['score']); // median ≥ p10
        $this->assertSame('healthy', $mesh['label']);
    }
}

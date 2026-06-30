<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit\Services;

use App\Models\Node;
use App\Models\NodeEvent;
use App\Models\TelemetryProbe;
use App\Services\NodeEventLogger;
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

    private function seedLifecycleEvent(string $nodeId, string $type, int $tsMs): void
    {
        NodeEvent::create([
            'event_id' => (string) Str::uuid(),
            'seq' => (NodeEvent::max('seq') ?? 0) + 1,
            'event_type' => $type,
            'node_id' => $nodeId,
            'ts_ms' => $tsMs,
            'payload' => [],
            'prev_hash' => NodeEventLogger::GENESIS_ROOT,
            'signature' => null,
        ]);
    }

    private function seedReachabilityProbe(string $nodeId, bool $passed, int $minutesAgo = 1, float $latencyMs = 100): void
    {
        TelemetryProbe::create([
            'probe_token_id' => null,
            'node_id' => $nodeId,
            'run_id' => (string) Str::uuid(),
            'probe_id' => 'reach-test',
            'probe_type' => 'reachability',
            'test_id' => 'REACH-01',
            'level' => 'MUST',
            'passed' => $passed,
            'latency_ms' => $latencyMs,
            'detail' => 'test',
            'metadata' => [],
            'probed_at' => now()->subMinutes($minutesAgo),
        ]);
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
        $this->seedReachabilityProbe($node->id, true, latencyMs: 40);

        $h = $this->svc->forNode($node);

        $this->assertSame('healthy', $h['label']);
        $this->assertGreaterThanOrEqual(85, $h['score']);
        $this->assertSame(1.0, $h['components']['liveness']);
        $this->assertSame('directory_probe', $h['latency_ms_basis']);
        $this->assertSame('high', $h['confidence']);
    }

    public function test_slow_task_latency_does_not_degrade_fast_operational_health(): void
    {
        $node = $this->makeNode([
            'public_reachable' => true,
            'avg_latency_ms_recent' => 900.0,
            'avg_latency_ms' => 750.0,
        ]);
        $this->seedReachabilityProbe($node->id, true, latencyMs: 2);

        $h = $this->svc->forNode($node);

        $this->assertSame('healthy', $h['label']);
        $this->assertSame('directory_probe', $h['latency_ms_basis']);
        $this->assertGreaterThan(0.99, $h['components']['latency']);
        $this->assertSame('high', $h['confidence']);
    }

    public function test_task_latency_is_ignored_when_operational_latency_is_missing(): void
    {
        $h = $this->svc->forNode($this->makeNode([
            'public_reachable' => true,
            'avg_latency_ms_recent' => 900.0,
            'avg_latency_ms' => 750.0,
        ]));

        $this->assertSame(0.5, $h['components']['latency']);
        $this->assertSame('none', $h['latency_ms_basis']);
        $this->assertSame('low', $h['confidence']);
        $this->assertSame(84, $h['score']);
    }

    public function test_offline_node_is_gated_to_zero(): void
    {
        $node = $this->makeNode(['last_seen' => now()->subMinutes(10)]);

        $h = $this->svc->forNode($node);

        $this->assertSame('offline', $h['label']);
        $this->assertSame(0, $h['score']);
        $this->assertSame('none', $h['confidence']);
        $this->assertSame('missing', $h['evidence_level']);
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

    public function test_relay_capable_node_gets_full_reachability_in_phase_a(): void
    {
        // Behavior: relay_capable nodes score 1.0 in Phase A.
        // This test fails if the fix is reverted.
        $h = $this->svc->forNode($this->makeNode([
            'public_reachable' => false,
            'relay_capable' => true,
        ]));

        $this->assertSame(1.0, $h['components']['reachability']);
        $this->assertSame('degraded', $h['label']);
        $this->assertSame(84, $h['score']);
    }

    public function test_exposure_mode_node_gets_full_reachability_in_phase_a(): void
    {
        // Behavior: nodes with a named ADR-043 exposure_mode (e.g. IPv6 behind
        // firewall, CGNAT, relay_required) score 1.0 reachability in Phase A —
        // they self-attest a routable serving surface.  With W_REACHABILITY=0.70
        // a 0.0 score would make them "critical" even though they ARE discoverable.
        // This is the root cause of the June-11 mesh_health critical regression.
        // Test fails if the exposure_mode check is removed.
        $h = $this->svc->forNode($this->makeNode([
            'public_reachable' => false,
            'relay_capable' => false,
            'exposure_mode' => 'ipv6_direct_firewall_required',
        ]));

        $this->assertSame(1.0, $h['components']['reachability']);
        $this->assertSame('degraded', $h['label']);
        $this->assertSame(84, $h['score']);
    }

    public function test_no_data_node_uses_neutral_defaults(): void
    {
        // Never-served node: no latency data → neutral 0.5. Success_rate and
        // reputation were removed from the formula by #492 (ADR-044 amendment).
        $h = $this->svc->forNode($this->makeNode());

        $this->assertSame(0.5, $h['components']['latency']);
        $this->assertFalse($h['observed']);
        $this->assertSame('low', $h['confidence']);
        $this->assertSame('self_attested', $h['evidence_level']);
        $this->assertSame('none', $h['latency_ms_basis']);
        $this->assertSame('degraded', $h['label']);
        $this->assertSame(84, $h['score']);
    }

    public function test_confirmed_dead_endpoint_overrides_self_attested_reachability(): void
    {
        $h = $this->svc->forNode($this->makeNode([
            'public_reachable' => true,
            'relay_capable' => true,
            'exposure_mode' => 'relay_required',
            'endpoint_verified_dead_at' => now()->subMinutes(5),
        ]));

        $this->assertSame(0.0, $h['components']['reachability']);
        $this->assertSame('directory_observed', $h['evidence_level']);
        $this->assertSame('critical', $h['label']);
        $this->assertLessThan(40, $h['score']);
    }

    public function test_active_provider_nodes_excludes_confirmed_dead_endpoint(): void
    {
        $public = $this->makeNode([
            'public_reachable' => true,
            'status' => 'active',
        ]);
        $dead = $this->makeNode([
            'public_reachable' => true,
            'status' => 'active',
            'endpoint_verified_dead_at' => now()->subMinutes(5),
        ]);

        $ids = $this->svc->activeProviderNodes()->pluck('id')->all();

        $this->assertContains($public->id, $ids);
        $this->assertNotContains($dead->id, $ids);
    }

    public function test_health_components_keep_uptime_and_stability_null_without_signed_lifecycle_evidence(): void
    {
        $h = $this->svc->forNode($this->makeNode());

        $this->assertNull($h['components']['uptime']);
        $this->assertNull($h['components']['stability']);
    }

    public function test_health_components_include_verified_uptime_and_stability_when_lifecycle_evidence_exists(): void
    {
        $node = $this->makeNode(['avg_latency_ms' => 40.0]);
        $startMs = (int) (microtime(true) * 1000) - 3_600_000;
        $this->seedLifecycleEvent($node->id, 'REGISTER', $startMs);
        $this->seedReachabilityProbe($node->id, true, latencyMs: 40);

        $h = $this->svc->forNode($node);

        $this->assertIsFloat($h['components']['uptime']);
        $this->assertIsFloat($h['components']['stability']);
        $this->assertGreaterThan(0.98, $h['components']['uptime']);
        $this->assertGreaterThan(0.98, $h['components']['stability']);
        $this->assertSame('healthy', $h['label'], 'uptime/stability are visible components only; they must not change the health score yet');
    }

    public function test_stability_component_penalizes_recent_flapping(): void
    {
        $stable = $this->makeNode(['avg_latency_ms' => 40.0]);
        $flappy = $this->makeNode(['avg_latency_ms' => 40.0]);
        $base = (int) (microtime(true) * 1000) - 3_600_000;

        $this->seedLifecycleEvent($stable->id, 'REGISTER', $base);
        $this->seedReachabilityProbe($stable->id, true, latencyMs: 40);

        $this->seedLifecycleEvent($flappy->id, 'REGISTER', $base);
        $this->seedLifecycleEvent($flappy->id, 'EVICT', $base + 1_000_000);
        $this->seedLifecycleEvent($flappy->id, 'REACTIVATE', $base + 1_200_000);
        $this->seedLifecycleEvent($flappy->id, 'EVICT', $base + 2_000_000);
        $this->seedLifecycleEvent($flappy->id, 'REACTIVATE', $base + 2_200_000);
        $this->seedReachabilityProbe($flappy->id, true, latencyMs: 40);

        $stableHealth = $this->svc->forNode($stable);
        $flappyHealth = $this->svc->forNode($flappy);

        $this->assertLessThan($stableHealth['components']['stability'], $flappyHealth['components']['stability']);
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
            tap($this->makeNode(['public_reachable' => true, 'reputation_score' => 0.95, 'tasks_total' => 100, 'tasks_failed' => 0, 'avg_latency_ms' => 40]), fn (Node $n) => $this->seedReachabilityProbe($n->id, true, latencyMs: 40)),
            tap($this->makeNode(['public_reachable' => true, 'reputation_score' => 0.95, 'tasks_total' => 100, 'tasks_failed' => 0, 'avg_latency_ms' => 40]), fn (Node $n) => $this->seedReachabilityProbe($n->id, true, latencyMs: 40)),
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

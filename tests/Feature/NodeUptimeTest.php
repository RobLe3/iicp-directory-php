<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeEvent;
use App\Services\LivenessMonitor;
use App\Services\NodeEventLogger;
use App\Services\UptimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Behavior tests for node uptime tracking (#508).
 *
 * Each test fails if the fix is reverted:
 * - EVICT is not emitted → uptime_from_closed_sessions returns 0 instead of > 0
 * - REACTIVATE is not emitted → a revived node's new session is ignored
 * - UptimeService is not wired into NodeController → /api/v1/node/{id} has no uptime key
 */
class NodeUptimeTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ────────────────────────────────────────────────────────────────

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
            'status' => 'active',
            'last_seen' => now(),
        ], $overrides));
    }

    private function seedEvent(string $nodeId, string $type, int $tsMs): void
    {
        NodeEvent::create([
            'event_id' => (string) Str::uuid(),
            'seq' => NodeEvent::max('seq') + 1,
            'event_type' => $type,
            'node_id' => $nodeId,
            'ts_ms' => $tsMs,
            'payload' => [],
            'prev_hash' => NodeEventLogger::GENESIS_ROOT,
            'signature' => null,
        ]);
    }

    // ── UptimeService unit tests ────────────────────────────────────────────────

    public function test_uptime_service_computes_closed_session(): void
    {
        $node = $this->makeNode();
        $service = new UptimeService;

        $start = (int) (microtime(true) * 1000) - 3_600_000; // 1 hour ago
        $end = $start + 3_600_000;                            // exactly 1 hour

        $this->seedEvent($node->id, 'REGISTER', $start);
        $this->seedEvent($node->id, 'EVICT', $end);

        $uptime = $service->uptimeForNode($node->id);

        $this->assertSame(3600, $uptime['cumulative_seconds']);
        $this->assertSame(0, $uptime['current_session_seconds']);
        $this->assertSame(3600, $uptime['total_seconds']);
        $this->assertSame(1, $uptime['sessions_count']);
        $this->assertSame($start, $uptime['first_seen_ms']);
    }

    public function test_uptime_service_accumulates_multiple_sessions(): void
    {
        $node = $this->makeNode();
        $service = new UptimeService;
        $base = 1_000_000_000_000; // arbitrary ms timestamp

        $this->seedEvent($node->id, 'REGISTER', $base);
        $this->seedEvent($node->id, 'EVICT', $base + 3_600_000);      // 1 h session
        $this->seedEvent($node->id, 'REACTIVATE', $base + 7_200_000);
        $this->seedEvent($node->id, 'EVICT', $base + 10_800_000);     // another 1 h

        $uptime = $service->uptimeForNode($node->id);

        $this->assertSame(7200, $uptime['cumulative_seconds']);
        $this->assertSame(2, $uptime['sessions_count']);
    }

    public function test_uptime_service_counts_open_session(): void
    {
        $node = $this->makeNode();
        $service = new UptimeService;

        // REGISTER 10 minutes ago, no EVICT yet
        $startMs = (int) (microtime(true) * 1000) - 600_000;
        $this->seedEvent($node->id, 'REGISTER', $startMs);

        $uptime = $service->uptimeForNode($node->id);

        $this->assertSame(0, $uptime['cumulative_seconds']);
        $this->assertSame(0, $uptime['sessions_count']);
        // Open session should be ~600s; allow ±5s tolerance for timing jitter
        $this->assertGreaterThanOrEqual(595, $uptime['current_session_seconds']);
        $this->assertLessThanOrEqual(605, $uptime['current_session_seconds']);
    }

    public function test_uptime_service_returns_zeros_for_no_events(): void
    {
        $node = $this->makeNode();
        $service = new UptimeService;

        $uptime = $service->uptimeForNode($node->id);

        $this->assertSame(0, $uptime['cumulative_seconds']);
        $this->assertSame(0, $uptime['current_session_seconds']);
        $this->assertSame(0, $uptime['total_seconds']);
        $this->assertSame(0, $uptime['sessions_count']);
        $this->assertNull($uptime['first_seen_ms']);
    }

    public function test_uptime_score_returns_null_without_signed_lifecycle_evidence(): void
    {
        $node = $this->makeNode();

        $this->assertNull((new UptimeService)->uptimeScoreForNode($node->id));
    }

    public function test_uptime_score_reflects_signed_online_ratio(): void
    {
        $node = $this->makeNode();
        $service = new UptimeService;
        $startMs = (int) (microtime(true) * 1000) - 7_200_000; // observed for 2h
        $this->seedEvent($node->id, 'REGISTER', $startMs);
        $this->seedEvent($node->id, 'EVICT', $startMs + 3_600_000); // online for 1h

        $score = $service->uptimeScoreForNode($node->id);

        $this->assertIsFloat($score);
        $this->assertGreaterThanOrEqual(0.49, $score);
        $this->assertLessThanOrEqual(0.51, $score);
    }

    public function test_stability_score_returns_null_without_signed_lifecycle_evidence(): void
    {
        $node = $this->makeNode();

        $this->assertNull((new UptimeService)->stabilityScoreForNode($node->id));
    }

    public function test_stability_score_penalizes_recent_evictions(): void
    {
        $stable = $this->makeNode();
        $flappy = $this->makeNode();
        $service = new UptimeService;
        $base = (int) (microtime(true) * 1000) - 3_600_000;

        $this->seedEvent($stable->id, 'REGISTER', $base);

        $this->seedEvent($flappy->id, 'REGISTER', $base);
        $this->seedEvent($flappy->id, 'EVICT', $base + 1_000_000);
        $this->seedEvent($flappy->id, 'REACTIVATE', $base + 1_200_000);
        $this->seedEvent($flappy->id, 'EVICT', $base + 2_000_000);
        $this->seedEvent($flappy->id, 'REACTIVATE', $base + 2_200_000);

        $this->assertLessThan(
            $service->stabilityScoreForNode($stable->id),
            $service->stabilityScoreForNode($flappy->id),
        );
    }

    // ── Duration gate progress fields ─────────────────────────────────────────
    // These fields show how far toward the next rank's UPTIME DURATION threshold.
    // Rank assignment is operator-scoped (operators table); these are informational only.

    public function test_duration_gate_no_uptime_points_toward_mesh_serf(): void
    {
        $node = $this->makeNode();
        $uptime = (new UptimeService)->uptimeForNode($node->id);

        // No gate met yet
        $this->assertNull($uptime['met_duration_gate_rank']);
        $this->assertNull($uptime['met_duration_gate_key']);
        // Next gate is Mesh Serf
        $this->assertSame(1, $uptime['next_duration_gate_rank']);
        $this->assertSame('mesh_serf', $uptime['next_duration_gate_key']);
        $this->assertSame('Mesh Serf', $uptime['next_duration_gate_title']);
        $this->assertSame(604_800, $uptime['next_duration_gate_seconds']);
        $this->assertSame(0.0, $uptime['duration_progress_pct']);
        // Crucially: no rank label — ranks are operator-scoped, not node-scoped
        $this->assertArrayNotHasKey('rank_tier', $uptime);
        $this->assertArrayNotHasKey('progress_pct', $uptime);
    }

    public function test_duration_gate_halfway_to_mesh_serf(): void
    {
        $node = $this->makeNode();
        $service = new UptimeService;
        $base = 1_000_000_000_000;
        $halfwayMs = (int) (604_800 / 2 * 1000); // 3.5 days in ms

        $this->seedEvent($node->id, 'REGISTER', $base);
        $this->seedEvent($node->id, 'EVICT', $base + $halfwayMs);

        $uptime = $service->uptimeForNode($node->id);

        $this->assertNull($uptime['met_duration_gate_rank']);
        $this->assertSame('mesh_serf', $uptime['next_duration_gate_key']);
        $this->assertSame(50.0, $uptime['duration_progress_pct']);
    }

    public function test_duration_gate_mesh_serf_met_points_to_local_daemon(): void
    {
        $node = $this->makeNode();
        $service = new UptimeService;
        $base = 1_000_000_000_000;
        $sevenDaysMs = 604_800 * 1000;

        $this->seedEvent($node->id, 'REGISTER', $base);
        $this->seedEvent($node->id, 'EVICT', $base + $sevenDaysMs);

        $uptime = $service->uptimeForNode($node->id);

        // Met: Mesh Serf duration gate
        $this->assertSame(1, $uptime['met_duration_gate_rank']);
        $this->assertSame('mesh_serf', $uptime['met_duration_gate_key']);
        // Next: Local Daemon duration gate
        $this->assertSame(2, $uptime['next_duration_gate_rank']);
        $this->assertSame('local_daemon', $uptime['next_duration_gate_key']);
        $this->assertSame(2_592_000, $uptime['next_duration_gate_seconds']);
        $this->assertSame(0.0, $uptime['duration_progress_pct']);
    }

    public function test_duration_gate_all_met_returns_100_and_null_next(): void
    {
        $node = $this->makeNode();
        $service = new UptimeService;
        $base = 1_000_000_000_000;
        $ninetyDaysMs = 7_776_000 * 1000;

        $this->seedEvent($node->id, 'REGISTER', $base);
        $this->seedEvent($node->id, 'EVICT', $base + $ninetyDaysMs);

        $uptime = $service->uptimeForNode($node->id);

        $this->assertSame(6, $uptime['met_duration_gate_rank']);
        $this->assertSame('mesh_guardian', $uptime['met_duration_gate_key']);
        $this->assertNull($uptime['next_duration_gate_rank']);
        $this->assertNull($uptime['next_duration_gate_key']);
        $this->assertNull($uptime['next_duration_gate_title']);
        $this->assertNull($uptime['next_duration_gate_seconds']);
        $this->assertSame(100.0, $uptime['duration_progress_pct']);
    }

    // ── LivenessMonitor EVICT emission ─────────────────────────────────────────

    public function test_expire_stale_nodes_emits_evict_event(): void
    {
        $monitor = app(LivenessMonitor::class);

        $node = $this->makeNode([
            'status' => 'active',
            'available' => true,
            'last_seen' => now()->subSeconds(120), // stale
        ]);

        $this->assertSame(0, NodeEvent::where('event_type', 'EVICT')->count());

        $expired = $monitor->expireStaleNodes();

        $this->assertSame(1, $expired);
        $this->assertSame(1, NodeEvent::where('event_type', 'EVICT')
            ->where('node_id', $node->id)
            ->count());
    }

    public function test_expire_stale_nodes_evict_payload_has_required_fields(): void
    {
        $monitor = app(LivenessMonitor::class);

        $this->makeNode([
            'status' => 'active',
            'available' => true,
            'last_seen' => now()->subSeconds(120),
        ]);

        $monitor->expireStaleNodes();

        $event = NodeEvent::where('event_type', 'EVICT')->first();
        $this->assertNotNull($event);
        $this->assertSame('heartbeat_expiry', $event->payload['reason']);
        $this->assertArrayHasKey('last_seen_ms', $event->payload);
        $this->assertArrayHasKey('dormant_since_ms', $event->payload);
    }

    public function test_expire_stale_nodes_emits_evict_for_each_expired_node(): void
    {
        $monitor = app(LivenessMonitor::class);

        foreach (range(1, 3) as $i) {
            $this->makeNode([
                'endpoint' => "https://node{$i}.example.com",
                'status' => 'active',
                'available' => true,
                'last_seen' => now()->subSeconds(120),
            ]);
        }

        $expired = $monitor->expireStaleNodes();

        $this->assertSame(3, $expired);
        $this->assertSame(3, NodeEvent::where('event_type', 'EVICT')->count());
    }

    public function test_non_stale_node_does_not_get_evict_event(): void
    {
        $monitor = app(LivenessMonitor::class);

        $this->makeNode([
            'status' => 'active',
            'available' => true,
            'last_seen' => now()->subSeconds(30), // fresh
        ]);

        $expired = $monitor->expireStaleNodes();

        $this->assertSame(0, $expired);
        $this->assertSame(0, NodeEvent::where('event_type', 'EVICT')->count());
    }

    // ── HeartbeatController REACTIVATE emission ────────────────────────────────

    public function test_heartbeat_from_dormant_node_emits_reactivate(): void
    {
        Http::fake(['https://node.example.com/iicp/health' => Http::response('ok', 200)]);

        $token = Str::random(40);
        $node = $this->makeNode([
            'node_token_hash' => password_hash($token, PASSWORD_BCRYPT),
            'status' => 'dormant',
            'available' => false,
            'dormant_since' => now()->subMinutes(5),
        ]);

        $this->assertSame(0, NodeEvent::where('event_type', 'REACTIVATE')->count());

        $this->withToken($token)
            ->postJson('/api/v1/heartbeat', ['node_id' => $node->id])
            ->assertStatus(200);

        $this->assertSame(1, NodeEvent::where('event_type', 'REACTIVATE')
            ->where('node_id', $node->id)
            ->count());

        $event = NodeEvent::where('event_type', 'REACTIVATE')->first();
        $this->assertArrayHasKey('dormant_since_ms', $event->payload);
        $this->assertArrayHasKey('dormancy_duration_seconds', $event->payload);
    }

    public function test_heartbeat_from_active_node_does_not_emit_reactivate(): void
    {
        Http::fake(['https://node.example.com/iicp/health' => Http::response('ok', 200)]);

        $token = Str::random(40);
        $node = $this->makeNode([
            'node_token_hash' => password_hash($token, PASSWORD_BCRYPT),
            'status' => 'active',
            'available' => true,
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/heartbeat', ['node_id' => $node->id])
            ->assertStatus(200);

        $this->assertSame(0, NodeEvent::where('event_type', 'REACTIVATE')->count());
    }

    // ── NodeController uptime field in API response ─────────────────────────────

    public function test_node_detail_endpoint_includes_uptime_field(): void
    {
        $node = $this->makeNode();
        $startMs = (int) (microtime(true) * 1000) - 7_200_000; // 2 h ago
        $this->seedEvent($node->id, 'REGISTER', $startMs);
        $this->seedEvent($node->id, 'EVICT', $startMs + 3_600_000); // 1 h session

        $response = $this->getJson("/api/v1/node/{$node->id}")->assertStatus(200);

        $this->assertArrayHasKey('uptime', $response->json());
        $uptime = $response->json('uptime');
        $this->assertArrayHasKey('cumulative_seconds', $uptime);
        $this->assertArrayHasKey('current_session_seconds', $uptime);
        $this->assertArrayHasKey('total_seconds', $uptime);
        $this->assertArrayHasKey('sessions_count', $uptime);
        $this->assertArrayHasKey('first_seen_ms', $uptime);
        $this->assertSame(3600, $uptime['cumulative_seconds']);
    }

    // ── #503 — operator_verified field in node detail API response ───────────

    public function test_node_detail_includes_operator_verified_false_when_anonymous(): void
    {
        $node = $this->makeNode(['operator_verified' => false]);

        $response = $this->getJson("/api/v1/node/{$node->id}")->assertStatus(200);

        $this->assertArrayHasKey('operator_verified', $response->json());
        $this->assertFalse($response->json('operator_verified'));
    }

    public function test_node_detail_includes_operator_verified_true_when_key_backed(): void
    {
        $node = $this->makeNode(['operator_verified' => true]);

        $response = $this->getJson("/api/v1/node/{$node->id}")->assertStatus(200);

        $this->assertTrue($response->json('operator_verified'));
    }
}

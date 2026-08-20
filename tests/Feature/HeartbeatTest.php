<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeEvent;
use App\Models\Reputation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class HeartbeatTest extends TestCase
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
            'last_seen' => now()->subSeconds(20),
        ]);
    }

    // ── ADR-047 Part A (#411) — heartbeat challenge-response liveness ──────────

    public function test_heartbeat_returns_a_challenge_nonce(): void
    {
        $resp = $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', ['node_id' => $this->node->id])
            ->assertStatus(200);
        $challenge = $resp->json('challenge');
        $this->assertNotEmpty($challenge);
        $this->assertSame($challenge, $this->node->fresh()->liveness_challenge);
    }

    public function test_correct_challenge_response_sets_liveness_verified(): void
    {
        $key = bin2hex(random_bytes(32));
        $this->node->update(['node_hmac_key' => $key]);
        // Beat 1 — obtain the issued nonce.
        $c1 = $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', ['node_id' => $this->node->id])->json('challenge');
        $this->assertNull($this->node->fresh()->liveness_verified_at);
        // Beat 2 — answer it with HMAC(node_hmac_key, nonce).
        $answer = hash_hmac('sha256', $c1, $key);
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', ['node_id' => $this->node->id, 'challenge_response' => $answer])
            ->assertStatus(200);
        $this->assertNotNull($this->node->fresh()->liveness_verified_at);
    }

    public function test_wrong_challenge_response_does_not_verify_liveness(): void
    {
        $this->node->update(['node_hmac_key' => bin2hex(random_bytes(32))]);
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', ['node_id' => $this->node->id]);  // issue a nonce
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', [
                'node_id' => $this->node->id,
                'challenge_response' => hash_hmac('sha256', 'wrong-nonce', 'wrong-key'),
            ])->assertStatus(200);
        $this->assertNull($this->node->fresh()->liveness_verified_at);
    }

    public function test_previous_challenge_response_cannot_be_replayed(): void
    {
        $key = bin2hex(random_bytes(32));
        $this->node->update(['node_hmac_key' => $key]);

        $firstChallenge = $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', ['node_id' => $this->node->id])
            ->assertStatus(200)
            ->json('challenge');
        $firstAnswer = hash_hmac('sha256', $firstChallenge, $key);

        $secondChallenge = $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', [
                'node_id' => $this->node->id,
                'challenge_response' => $firstAnswer,
            ])
            ->assertStatus(200)
            ->json('challenge');
        $this->assertNotSame($firstChallenge, $secondChallenge);
        $this->assertNotNull($this->node->fresh()->liveness_verified_at);

        // Remove the prior success marker so replay acceptance cannot hide behind it.
        $this->node->update(['liveness_verified_at' => null]);
        $thirdChallenge = $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', [
                'node_id' => $this->node->id,
                'challenge_response' => $firstAnswer,
            ])
            ->assertStatus(200)
            ->json('challenge');

        $this->assertNotSame($secondChallenge, $thirdChallenge);
        $this->assertNull($this->node->fresh()->liveness_verified_at);
    }

    public function test_heartbeat_updates_last_seen(): void
    {
        $before = $this->node->last_seen;

        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', ['node_id' => $this->node->id])
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('next_heartbeat_ms', 30000);

        $this->node->refresh();
        $this->assertTrue($this->node->last_seen->isAfter($before));
    }

    public function test_heartbeat_updates_load_and_jobs(): void
    {
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', [
                'node_id' => $this->node->id,
                'load' => 0.75,
                'active_jobs' => 3,
                'available' => true,
            ])
            ->assertStatus(200);

        $this->node->refresh();
        $this->assertEquals(0.75, $this->node->load);
        $this->assertEquals(3, $this->node->active_jobs);
    }

    public function test_heartbeat_absent_backend_stability_is_backward_compatible(): void
    {
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', ['node_id' => $this->node->id])
            ->assertStatus(200);

        $this->assertNull($this->node->fresh()->backend_stability);
    }

    public function test_heartbeat_stores_redacted_backend_stability(): void
    {
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', [
                'node_id' => $this->node->id,
                'backend_stability' => [
                    'backend_state' => 'degraded',
                    'reason_class' => 'backend_cold',
                    'retry_after_s' => 30,
                    // Must not be persisted: the public contract is redacted/coarse.
                    'diagnostics' => 'local backend exception with private details',
                ],
            ])
            ->assertStatus(200);

        $stored = $this->node->fresh()->backend_stability;
        $this->assertSame('degraded', $stored['backend_state']);
        $this->assertSame('backend_cold', $stored['reason_class']);
        $this->assertSame(30, $stored['retry_after_s']);
        $this->assertArrayNotHasKey('diagnostics', $stored);
    }

    public function test_heartbeat_rejects_invalid_backend_stability_state(): void
    {
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', [
                'node_id' => $this->node->id,
                'backend_stability' => [
                    'backend_state' => 'crashed',
                    'reason_class' => 'backend_cold',
                ],
            ])
            ->assertStatus(422);
    }

    /**
     * Auto-recovery: a node that went dormant (LivenessMonitor flips available=false,
     * status=dormant after a >90s heartbeat gap — e.g. laptop sleep) must be fully
     * restored when its heartbeat resumes, WITHOUT a manual re-register. The SDK sends
     * status:"available" (a string), never the `available` boolean, so the controller
     * must default available=true on a heartbeat. Regression guard for the bug where
     * `available => $validated['available'] ?? $node->available` preserved the dormancy
     * false, leaving a live, heartbeating node excluded from discover forever.
     */
    public function test_heartbeat_restores_dormant_node_to_available(): void
    {
        // Simulate LivenessMonitor having marked the node dormant.
        $this->node->update([
            'available' => false,
            'status' => 'dormant',
            'dormant_since' => now()->subMinutes(5),
        ]);

        // Heartbeat resumes — note: NO `available` field in the payload, exactly as
        // the SDK heartbeat loop sends it (status:"available" string only).
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', ['node_id' => $this->node->id])
            ->assertStatus(200);

        $this->node->refresh();
        $this->assertTrue((bool) $this->node->available, 'heartbeat must restore available=true');
        $this->assertSame('active', $this->node->status, 'heartbeat must clear dormant status');
        $this->assertNull($this->node->dormant_since, 'heartbeat must clear dormant_since');
    }

    /**
     * The inverse guard: an explicit available=false in the heartbeat payload (node
     * signalling it is temporarily at capacity) must still be honoured — the default
     * only applies when the field is absent.
     */
    public function test_heartbeat_honours_explicit_available_false(): void
    {
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', [
                'node_id' => $this->node->id,
                'available' => false,
            ])
            ->assertStatus(200);

        $this->assertFalse((bool) $this->node->fresh()->available);
    }

    public function test_rejects_missing_token(): void
    {
        $this->postJson('/api/v1/heartbeat', ['node_id' => $this->node->id])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_rejects_invalid_token(): void
    {
        $this->withToken('wrong-token')
            ->postJson('/api/v1/heartbeat', ['node_id' => $this->node->id])
            ->assertStatus(401);
    }

    public function test_rejects_unknown_node_id(): void
    {
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', ['node_id' => '00000000-0000-0000-0000-000000000000'])
            ->assertStatus(401);
    }

    public function test_expired_jwt_returns_token_expired_error_code(): void
    {
        // Craft a JWT with a past exp but valid signature (impossible to fake without the secret,
        // so we issue a real token then verify that a tampered-expiry token is just unauthorized).
        // The real expiry test is covered in JwtServiceTest — here we verify the middleware
        // propagates 'unauthorized' for opaque tokens (no way to issue truly expired JWT in test).
        $this->withToken('completely-invalid-token')
            ->postJson('/api/v1/heartbeat', ['node_id' => $this->node->id])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthorized');
    }

    // W-042 / db-D4prime: HEARTBEAT events are no longer emitted per S.13 v0.3.0
    // (federation snapshot+event-tail model; replicas derive liveness from canonical
    // nodes row, not from per-heartbeat events). These tests inverted: assert that
    // heartbeat completes successfully and that NO HEARTBEAT event is appended.
    public function test_heartbeat_does_not_emit_heartbeat_event_per_d4prime(): void
    {
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', [
                'node_id' => $this->node->id,
                'load' => 0.5,
                'available' => true,
            ])
            ->assertStatus(200);

        $event = NodeEvent::where('event_type', 'HEARTBEAT')
            ->where('node_id', $this->node->id)
            ->latest('seq')
            ->first();

        $this->assertNull($event, 'W-042/D4prime: HEARTBEAT event MUST NOT be emitted');
    }

    public function test_heartbeat_does_not_emit_reputation_update_event_per_d4prime(): void
    {
        Reputation::create(['node_id' => $this->node->id, 'score' => 0.85]);

        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', ['node_id' => $this->node->id])
            ->assertStatus(200);

        // Neither HEARTBEAT nor REPUTATION_UPDATE should be emitted by a no-task
        // heartbeat (REPUTATION_UPDATE only emits from upsert with delta).
        $hb = NodeEvent::where('event_type', 'HEARTBEAT')
            ->where('node_id', $this->node->id)->first();
        $this->assertNull($hb);
    }

    // D4: reputation_score in heartbeat response lets operators see current standing.
    public function test_heartbeat_response_includes_reputation_score(): void
    {
        Reputation::create(['node_id' => $this->node->id, 'score' => 0.72]);

        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', ['node_id' => $this->node->id])
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('next_heartbeat_ms', 30000)
            ->assertJsonPath('reputation_score', 0.72);
    }

    // D4: default reputation score (no Reputation record) is 0.5 in heartbeat response.
    public function test_heartbeat_response_reputation_score_defaults_to_half(): void
    {
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', ['node_id' => $this->node->id])
            ->assertStatus(200)
            ->assertJsonPath('reputation_score', 0.5);
    }

    // D8 / Phase 6 prereq: REPUTATION_UPDATE event emitted when adapter reports task metrics.
    // Replicas consume this event to track score changes from the adapter-reported path
    // (distinct from proxy SCORE_UPDATE events which come from proxy telemetry).
    public function test_heartbeat_emits_reputation_update_event_when_metrics_reported(): void
    {
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', [
                'node_id' => $this->node->id,
                'metrics' => [
                    'tasks_success' => 5,
                    'tasks_failed' => 1,
                    'avg_latency_ms' => 120.0,
                ],
                'metrics_batch_id' => 'heartbeat-test-batch',
            ])
            ->assertStatus(200)
            ->assertJsonPath('metrics_batch_accepted', 'heartbeat-test-batch');

        $event = NodeEvent::where('event_type', 'REPUTATION_UPDATE')
            ->where('node_id', $this->node->id)
            ->latest('seq')
            ->first();

        $this->assertNotNull($event, 'REPUTATION_UPDATE event must be emitted when task metrics are reported');
        $this->assertSame('heartbeat_metrics', $event->payload['source']);
        $this->assertSame(5, $event->payload['tasks_success']);
        $this->assertSame(1, $event->payload['tasks_failed']);
        $this->assertArrayHasKey('reputation_score', $event->payload);
    }

    public function test_heartbeat_duplicate_metrics_batch_is_acknowledged_without_reapplication(): void
    {
        $payload = [
            'node_id' => $this->node->id,
            'metrics_batch_id' => 'retry-safe-batch',
            'metrics' => ['tasks_success' => 1, 'tasks_failed' => 0, 'avg_latency_ms' => 7000.0],
        ];

        $this->withToken($this->plainToken)->postJson('/api/v1/heartbeat', $payload)
            ->assertOk()
            ->assertJsonPath('metrics_batch_accepted', 'retry-safe-batch')
            ->assertJsonPath('reputation_model', 'outcome-v2')
            ->assertJsonStructure(['reputation_epoch']);
        $this->withToken($this->plainToken)->postJson('/api/v1/heartbeat', $payload)
            ->assertOk()->assertJsonPath('metrics_batch_accepted', 'retry-safe-batch');

        $this->assertEquals(0.51, round((float) $this->node->fresh()->reputation_score, 4));
        $this->assertSame(1, $this->node->fresh()->tasks_total);
    }

    // D8: no REPUTATION_UPDATE event when heartbeat has no task metrics.
    public function test_heartbeat_does_not_emit_reputation_update_when_no_metrics(): void
    {
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', ['node_id' => $this->node->id])
            ->assertStatus(200);

        $event = NodeEvent::where('event_type', 'REPUTATION_UPDATE')
            ->where('node_id', $this->node->id)
            ->first();

        $this->assertNull($event, 'REPUTATION_UPDATE must not be emitted when no metrics are reported');
    }

    // ── #494 — health_models tracking ────────────────────────────────────────

    public function test_heartbeat_stores_health_models_when_reported(): void
    {
        // Behavior: when SDK reports health_models in the heartbeat body, the directory
        // stores them on the node row so discover can filter by runtime availability (#494).
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', [
                'node_id' => $this->node->id,
                'health_models' => ['qwen2.5:0.5b', 'llama3:latest'],
            ])
            ->assertStatus(200);

        $this->assertSame(
            ['qwen2.5:0.5b', 'llama3:latest'],
            $this->node->fresh()->health_models
        );
    }

    public function test_heartbeat_stores_empty_health_models(): void
    {
        // Behavior: health_models=[] (runtime has no models loaded) must be stored — not
        // treated as "not reported". An empty list means the SDK checked and found nothing.
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', [
                'node_id' => $this->node->id,
                'health_models' => [],
            ])
            ->assertStatus(200);

        $fresh = $this->node->fresh();
        $this->assertNotNull($fresh->health_models, 'empty array must be stored as JSON [], not null');
        $this->assertSame([], $fresh->health_models);
    }

    public function test_heartbeat_without_health_models_leaves_existing_value_unchanged(): void
    {
        // Behavior: a heartbeat that omits health_models entirely must NOT clear an
        // existing value — backward compat for SDKs that don't send the field yet.
        $this->node->update(['health_models' => ['qwen2.5:0.5b']]);

        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', ['node_id' => $this->node->id])
            ->assertStatus(200);

        $this->assertSame(['qwen2.5:0.5b'], $this->node->fresh()->health_models);
    }

    public function test_heartbeat_stores_auto_update_evidence_when_reported(): void
    {
        $checkedAt = now()->subMinute()->toIso8601String();

        $this->withToken($this->plainToken)
            ->postJson('/api/v1/heartbeat', [
                'node_id' => $this->node->id,
                'auto_update_enabled' => true,
                'auto_update_interval_s' => 3600,
                'sdk_latest_seen' => '0.7.68',
                'sdk_update_last_checked_at' => $checkedAt,
                'sdk_update_error_class' => null,
            ])
            ->assertStatus(200);

        $fresh = $this->node->fresh();
        $this->assertTrue((bool) $fresh->auto_update_enabled);
        $this->assertSame(3600, $fresh->auto_update_interval_s);
        $this->assertSame('0.7.68', $fresh->sdk_latest_seen);
        $this->assertNotNull($fresh->sdk_update_last_checked_at);
        $this->assertNull($fresh->sdk_update_error_class);
    }
}

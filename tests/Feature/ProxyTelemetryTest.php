<?php

namespace Tests\Feature;

use App\Http\Controllers\TelemetryController;
use App\Models\Node;
use App\Models\ProxyTelemetry;
use App\Models\Reputation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #112: POST /v1/telemetry — proxy-observed metrics ingestion.
 *
 * Covers: valid report accepted, unsigned report rejected (401),
 * duplicate within 60s time bucket deduplicated, EMA aggregation.
 */
class ProxyTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private string $plainToken = 'proxy-test-token-32bytes-xxxxxxxx';

    // Separate proxy_token for POST /v1/telemetry auth (#114 Sybil gate)
    private string $plainProxyToken = 'proxy-test-ptoken-32bytes-xxxxxxx';

    /**
     * Create a proxy node. By default it is eligible for Sybil quorum (age ≥QUORUM_MIN_AGE_DAYS,
     * reputation ≥QUORUM_MIN_REPUTATION). Pass $fresh=true for RT-03b tests that need a new node.
     */
    private function makeProxy(array $overrides = [], bool $fresh = false): Node
    {
        $proxy = Node::create(array_merge([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://proxy.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash($this->plainToken, PASSWORD_BCRYPT),
            'proxy_token_hash' => password_hash($this->plainProxyToken, PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'reputation_score' => 0.6, // >= QUORUM_MIN_REPUTATION (0.55)
        ], $overrides));

        if (! $fresh) {
            // Backdate to satisfy minimum age for quorum eligibility (RT-03b, #382)
            DB::table('nodes')->where('id', $proxy->id)->update([
                'created_at' => now()->subDays(TelemetryController::QUORUM_MIN_AGE_DAYS + 1),
            ]);
        }

        return $proxy;
    }

    private function makeTargetNode(): Node
    {
        return Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://target.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('target-tok', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
        ]);
    }

    /**
     * Pre-seed distinct proxy reports that satisfy the Sybil quorum gate (≥3 distinct proxies).
     * RT-03b (#382): each seeded proxy is a registered node ≥QUORUM_MIN_AGE_DAYS old with
     * reputation ≥QUORUM_MIN_REPUTATION so the independence requirements are met.
     */
    private function seedProxyQuorum(string $nodeId, int $count = 2): void
    {
        for ($i = 0; $i < $count; $i++) {
            $proxyId = (string) Str::uuid();
            Node::create([
                'id' => $proxyId,
                'endpoint' => "https://seed-proxy-{$i}.example.com",
                'region' => 'eu-central',
                'node_token_hash' => password_hash('seed-tok', PASSWORD_BCRYPT),
                'max_concurrent' => 1,
                'tokens_per_min' => 1000,
                'available' => true,
                'reputation_score' => 0.6, // >= QUORUM_MIN_REPUTATION (0.55)
            ]);
            // Backdate created_at to satisfy minimum age requirement
            DB::table('nodes')->where('id', $proxyId)->update([
                'created_at' => now()->subDays(TelemetryController::QUORUM_MIN_AGE_DAYS + 1),
            ]);
            ProxyTelemetry::create([
                'node_id' => $nodeId,
                'proxy_node_id' => $proxyId,
                'time_bucket' => 0,
                'latency_ms_observed' => 100.0,
                'tokens_observed' => 512,
                'status' => 'success',
                'qos_met' => true,
            ]);
        }
    }

    private function validPayload(string $nodeId, string $proxyNodeId): array
    {
        return [
            'node_id' => $nodeId,
            'proxy_node_id' => $proxyNodeId,
            'latency_ms_observed' => 150.0,
            'tokens_observed' => 1024,
            'status' => 'success',
            'qos_advertised' => 'interactive',
            'qos_met' => true,
        ];
    }

    // Valid report from authenticated proxy is accepted
    public function test_valid_telemetry_report_accepted(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        $r = $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', $this->validPayload($target->id, $proxy->id));

        $r->assertStatus(202)
            ->assertJson(['accepted' => true]);

        $this->assertDatabaseHas('proxy_telemetry', [
            'node_id' => $target->id,
            'proxy_node_id' => $proxy->id,
            'status' => 'success',
        ]);
    }

    // Unsigned request (no Bearer token) returns 401
    public function test_unsigned_report_rejected_with_401(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        $r = $this->postJson('/api/v1/telemetry', $this->validPayload($target->id, $proxy->id));

        $r->assertStatus(401);
        $this->assertDatabaseMissing('proxy_telemetry', ['node_id' => $target->id]);
    }

    // Invalid token returns 401
    public function test_invalid_token_returns_401(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        $r = $this->withToken('wrong-token')
            ->postJson('/api/v1/telemetry', $this->validPayload($target->id, $proxy->id));

        $r->assertStatus(401);
    }

    // #114: node_token (not proxy_token) is rejected — only proxy_token accepted for telemetry
    public function test_node_token_rejected_for_proxy_telemetry(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        // Use the node_token (plainToken) instead of proxy_token — must be rejected
        $r = $this->withToken($this->plainToken)
            ->postJson('/api/v1/telemetry', $this->validPayload($target->id, $proxy->id));

        $r->assertStatus(401);
        $this->assertDatabaseMissing('proxy_telemetry', ['node_id' => $target->id]);
    }

    // Duplicate within same 60s time bucket is deduplicated
    public function test_duplicate_within_time_bucket_deduplicated(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        $payload = $this->validPayload($target->id, $proxy->id);

        $r1 = $this->withToken($this->plainProxyToken)->postJson('/api/v1/telemetry', $payload);
        $r1->assertStatus(202)->assertJson(['accepted' => true]);

        $r2 = $this->withToken($this->plainProxyToken)->postJson('/api/v1/telemetry', $payload);
        $r2->assertStatus(200)->assertJson(['accepted' => false, 'reason' => 'duplicate']);

        $this->assertSame(1, ProxyTelemetry::where('node_id', $target->id)->count());
    }

    // EMA aggregation updates observed_latency_ms on reputation record (quorum met)
    public function test_telemetry_aggregates_observed_latency_via_ema(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        // Seed 2 distinct proxies so the quorum of 3 is met on this report
        $this->seedProxyQuorum($target->id);

        $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', array_merge(
                $this->validPayload($target->id, $proxy->id),
                ['latency_ms_observed' => 200.0]
            ))
            ->assertStatus(202);

        $rep = Reputation::where('node_id', $target->id)->first();
        $this->assertNotNull($rep);
        $this->assertEqualsWithDelta(200.0, $rep->observed_latency_ms, 0.01);
    }

    // Second report in a new bucket triggers EMA update (quorum met across both buckets)
    public function test_second_report_new_bucket_updates_ema(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        // Seed 2 distinct proxies so quorum of 3 is met on both reports
        $this->seedProxyQuorum($target->id);

        // First report — sets initial observed_latency_ms = 100.0
        $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', array_merge(
                $this->validPayload($target->id, $proxy->id),
                ['latency_ms_observed' => 100.0]
            ))
            ->assertStatus(202);

        // Manually shift time bucket in DB to allow second report
        ProxyTelemetry::where('node_id', $target->id)->update(['time_bucket' => 0]);

        $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', array_merge(
                $this->validPayload($target->id, $proxy->id),
                ['latency_ms_observed' => 200.0]
            ))
            ->assertStatus(202);

        // EMA: 0.1 * 200 + 0.9 * 100 = 110
        $rep = Reputation::where('node_id', $target->id)->first();
        $this->assertEqualsWithDelta(110.0, $rep->observed_latency_ms, 0.1);
    }

    // NodeController GET /v1/node/{id} exposes observed_latency_ms (quorum met)
    public function test_node_show_exposes_observed_latency(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        // Seed 2 distinct proxies so quorum of 3 is met
        $this->seedProxyQuorum($target->id);

        $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', array_merge(
                $this->validPayload($target->id, $proxy->id),
                ['latency_ms_observed' => 175.5]
            ));

        $r = $this->getJson('/api/v1/node/'.$target->id);
        $r->assertStatus(200)
            ->assertJsonPath('observed_latency_ms', 175.5);
    }

    // Validation: missing required fields returns 422
    public function test_missing_required_field_returns_422(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        $r = $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', [
                'node_id' => $target->id,
                'proxy_node_id' => $proxy->id,
                // missing latency_ms_observed, tokens_observed, status, qos_met
            ]);

        $r->assertStatus(422);
    }

    // Status must be one of success, failure, timeout
    public function test_invalid_status_returns_422(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        $r = $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', array_merge(
                $this->validPayload($target->id, $proxy->id),
                ['status' => 'unknown']
            ));

        $r->assertStatus(422);
    }

    // D8 / Phase 6 prereq: SCORE_UPDATE event emitted after proxy-observed EMA update
    // Re-targeted from W-042/D4prime: SCORE_UPDATE event is removed; assert that
    // a telemetry report with quorum met updates reputations.observed_latency_ms (EMA).
    public function test_telemetry_with_quorum_updates_observed_latency(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        // Seed 2 distinct proxies to reach quorum of 3 when this proxy reports
        $this->seedProxyQuorum($target->id);

        $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', array_merge(
                $this->validPayload($target->id, $proxy->id),
                ['latency_ms_observed' => 300.0]
            ))
            ->assertStatus(202);

        $rep = Reputation::where('node_id', $target->id)->first();
        $this->assertNotNull($rep, 'Reputation row must exist after telemetry report');
        // First EMA value with quorum met = the observed value itself (no prior)
        $this->assertEqualsWithDelta(300.0, $rep->observed_latency_ms, 0.1,
            'observed_latency_ms must be set to the first EMA value when quorum is met');
        // No SCORE_UPDATE event emitted (db-D4prime)
        $this->assertDatabaseMissing('node_events', ['event_type' => 'SCORE_UPDATE', 'node_id' => $target->id]);
    }

    // Sybil quorum gate (#114): EMA not applied when fewer than 3 distinct proxies
    public function test_quorum_below_threshold_ema_not_applied(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        // Only 1 distinct proxy — below quorum of 3
        $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', array_merge(
                $this->validPayload($target->id, $proxy->id),
                ['latency_ms_observed' => 200.0]
            ))
            ->assertStatus(202);

        $rep = Reputation::where('node_id', $target->id)->first();
        $this->assertNotNull($rep);
        $this->assertNull($rep->observed_latency_ms, 'EMA must not update when quorum is not met');
    }

    // Sybil quorum gate (#114): EMA applied when exactly 3 distinct proxies have reported
    public function test_quorum_met_at_three_proxies_applies_ema(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        // 2 pre-existing distinct proxy reports; this request is the 3rd → quorum met
        $this->seedProxyQuorum($target->id);

        $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', array_merge(
                $this->validPayload($target->id, $proxy->id),
                ['latency_ms_observed' => 150.0]
            ))
            ->assertStatus(202);

        $rep = Reputation::where('node_id', $target->id)->first();
        $this->assertNotNull($rep);
        // First EMA value = latency_ms_observed (no prior value to blend)
        $this->assertEqualsWithDelta(150.0, $rep->observed_latency_ms, 0.01);
    }

    // T4.3 OUTLIER_WEIGHT (#187): seed N observations from a specific proxy at a given latency
    private function seedProxyHistory(string $nodeId, string $proxyNodeId, float $latencyMs, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            ProxyTelemetry::create([
                'node_id' => $nodeId,
                'proxy_node_id' => $proxyNodeId,
                'time_bucket' => $i + 1,
                'latency_ms_observed' => $latencyMs,
                'tokens_observed' => 512,
                'status' => 'success',
                'qos_met' => true,
            ]);
        }
    }

    // T4.3 OUTLIER_WEIGHT (#187): proxy with ≥10 obs reporting >3× median has outlier_weight=0.1,
    // which sets effectiveAlpha = 0.1 * 0.1 = 0.01. Re-targeted from SCORE_UPDATE event
    // (W-042/D4prime) to assert canonical reputations.observed_latency_ms effect.
    public function test_outlier_proxy_with_sufficient_observations_is_down_weighted(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        // 9 prior honest reports from this proxy at 100ms + 2 quorum proxies → quorum met, median≈100ms
        $this->seedProxyHistory($target->id, $proxy->id, 100.0, 9);
        $this->seedProxyQuorum($target->id);

        // First seed the EMA with an honest observation to give it a non-null starting value
        Reputation::firstOrCreate(
            ['node_id' => $target->id],
            ['score' => 0.5, 'tasks_total' => 0, 'tasks_failed' => 0, 'completed_tasks_count' => 0, 'avg_latency_ms' => 0.0, 'observed_latency_ms' => 100.0],
        );

        // Report 400ms (4× median) → ≥10 obs from this proxy, 400 > 3×100 → outlier_weight=0.1
        // effectiveAlpha = 0.1 * 0.1 = 0.01 → new_ema ≈ 0.01*400 + 0.99*100 = 4 + 99 = 103
        $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', array_merge(
                $this->validPayload($target->id, $proxy->id),
                ['latency_ms_observed' => 400.0]
            ))
            ->assertStatus(202);

        $rep = Reputation::where('node_id', $target->id)->first();
        $this->assertNotNull($rep);
        // outlier_weight=0.1 → effectiveAlpha=0.01 → new≈103. Without down-weighting
        // (effectiveAlpha=0.1) it would be ≈136. Assert the down-weighted value is used.
        $this->assertNotNull($rep->observed_latency_ms, 'EMA must update when quorum is met');
        $this->assertLessThan(110.0, $rep->observed_latency_ms,
            'Outlier down-weighting (effectiveAlpha=0.01) must produce a value < 110 (vs unweighted ~136)');
        $this->assertGreaterThan(100.0, $rep->observed_latency_ms,
            'EMA must increase slightly (outlier at 400ms still nudges it up)');
    }

    // T4.3 OUTLIER_WEIGHT (#187): new proxy with <10 obs is never flagged regardless of report value
    public function test_new_proxy_below_min_observations_not_flagged_as_outlier(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        // Seed prior EMA + quorum so the EMA update path fires
        Reputation::firstOrCreate(
            ['node_id' => $target->id],
            ['score' => 0.5, 'tasks_total' => 0, 'tasks_failed' => 0, 'completed_tasks_count' => 0, 'avg_latency_ms' => 0.0, 'observed_latency_ms' => 100.0],
        );
        $this->seedProxyQuorum($target->id);

        // First-ever report from this proxy (proxyObsCount=1 after create) — far outlier value
        $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', array_merge(
                $this->validPayload($target->id, $proxy->id),
                ['latency_ms_observed' => 5000.0]
            ))
            ->assertStatus(202);

        $rep = Reputation::where('node_id', $target->id)->first();
        $this->assertNotNull($rep);
        // proxyObsCount=1 < 10 → outlier check skipped → outlierWeight=1.0 → effectiveAlpha=0.1
        // new_ema = 0.1*5000 + 0.9*100 = 590ms (vs down-weighted: 0.01*5000+0.99*100 = 149ms)
        $this->assertGreaterThan(300.0, $rep->observed_latency_ms,
            'New proxy with <10 obs must use full α=0.1 (outlier check requires ≥10 obs)');
        $this->assertLessThan(700.0, $rep->observed_latency_ms,
            'EMA must be a blended value (not the raw reported value)');
    }

    // T4.3 OUTLIER_WEIGHT (#187): honest proxy with high noise (within 3× median) not flagged
    public function test_honest_high_noise_proxy_not_flagged_as_outlier(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        // 9 prior reports at 100ms + 2 quorum → proxyObsCount=10 after new report, median≈100ms
        $this->seedProxyHistory($target->id, $proxy->id, 100.0, 9);
        $this->seedProxyQuorum($target->id);

        // Seed prior EMA so the full-α vs down-weighted distinction is observable
        Reputation::firstOrCreate(
            ['node_id' => $target->id],
            ['score' => 0.5, 'tasks_total' => 0, 'tasks_failed' => 0, 'completed_tasks_count' => 0, 'avg_latency_ms' => 0.0, 'observed_latency_ms' => 100.0],
        );

        // 140ms = 1.4× median — within the 3× threshold → outlierWeight=1.0 → full α=0.1
        $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', array_merge(
                $this->validPayload($target->id, $proxy->id),
                ['latency_ms_observed' => 140.0]
            ))
            ->assertStatus(202);

        $rep = Reputation::where('node_id', $target->id)->first();
        $this->assertNotNull($rep);
        // full α=0.1: new_ema = 0.1*140 + 0.9*100 = 104ms
        // down-weighted α=0.01 (false positive): 0.01*140 + 0.99*100 = 100.4ms
        $this->assertEqualsWithDelta(104.0, $rep->observed_latency_ms, 1.0,
            'Honest proxy within 3× median must use full α=0.1 (no false positive down-weighting)');
    }

    // Sybil quorum gate (#114): observed_latency_ms stays null below quorum, is set when quorum met
    public function test_quorum_gate_controls_observed_latency_update(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();

        // First report: 1 distinct proxy — quorum not met
        $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', $this->validPayload($target->id, $proxy->id))
            ->assertStatus(202);

        $rep = Reputation::where('node_id', $target->id)->first();
        $this->assertNotNull($rep);
        $this->assertNull($rep->observed_latency_ms, 'observed_latency_ms must stay null below quorum');

        // Add 2 more distinct proxies; allow the same proxy to re-report in a new time bucket
        $this->seedProxyQuorum($target->id);
        ProxyTelemetry::where('proxy_node_id', $proxy->id)->update(['time_bucket' => 0]);

        $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', $this->validPayload($target->id, $proxy->id))
            ->assertStatus(202);

        $rep->refresh();
        $this->assertNotNull($rep->observed_latency_ms,
            'observed_latency_ms must be set when quorum is met (≥3 distinct proxies)');
    }

    // TRACE-22 (ADR-014): accepted report returns 202 with accepted=true; duplicate returns 200 with accepted=false
    public function test_trace22_duplicate_returns_200_not_202(): void
    {
        $proxy = $this->makeProxy();
        $target = $this->makeTargetNode();
        $payload = $this->validPayload($target->id, $proxy->id);

        $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', $payload)
            ->assertStatus(202)
            ->assertJson(['accepted' => true]);

        // Second report in the same 60s time_bucket — must be deduped
        $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', $payload)
            ->assertStatus(200)
            ->assertJson(['accepted' => false, 'reason' => 'duplicate']);
    }

    /**
     * RT-03b (#382): freshly registered proxy nodes must NOT count toward Sybil quorum.
     * Attack: register 3 nodes in seconds from same IP, use them to spoof latency scores.
     * Mitigation: proxies must be ≥QUORUM_MIN_AGE_DAYS old to count toward quorum.
     */
    public function test_rt03b_fresh_proxy_nodes_do_not_count_toward_quorum(): void
    {
        $target = $this->makeTargetNode();

        // Seed 2 valid (old, reputable) proxy reports
        $this->seedProxyQuorum($target->id, 2);

        // Register a fresh proxy (0 days old) — should NOT count toward quorum
        $freshProxy = $this->makeProxy(['id' => (string) Str::uuid()], fresh: true);
        // fresh=true: created_at is now() (0 days old, below QUORUM_MIN_AGE_DAYS)

        // Fresh proxy submits a report
        ProxyTelemetry::create([
            'node_id' => $target->id,
            'proxy_node_id' => $freshProxy->id,
            'time_bucket' => 99,
            'latency_ms_observed' => 100.0,
            'tokens_observed' => 512,
            'status' => 'success',
            'qos_met' => true,
        ]);

        // Recount via the HTTP endpoint with fresh proxy as reporter
        $rep = Reputation::firstOrCreate(
            ['node_id' => $target->id],
            ['score' => 0.5, 'tasks_total' => 0, 'tasks_failed' => 0, 'completed_tasks_count' => 0, 'avg_latency_ms' => 0.0],
        );

        $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', $this->validPayload($target->id, $freshProxy->id));

        // With 2 valid + 1 fresh (ineligible), effective quorum = 2 < 3 → EMA NOT applied
        $rep->refresh();
        $this->assertNull($rep->observed_latency_ms,
            'RT-03b: fresh proxy (0 days old) must not count toward quorum; EMA must not be applied with only 2 eligible proxies');
    }

    /** RT-03b: quorum IS met when 3 eligible (old + reputable) proxies report. */
    public function test_rt03b_eligible_proxies_satisfy_quorum(): void
    {
        $target = $this->makeTargetNode();

        // Seed 2 eligible proxies
        $this->seedProxyQuorum($target->id, 2);

        // Register a 3rd eligible proxy (old enough + reputable enough)
        $proxy = $this->makeProxy(['reputation_score' => 0.6]);
        DB::table('nodes')->where('id', $proxy->id)->update([
            'created_at' => now()->subDays(TelemetryController::QUORUM_MIN_AGE_DAYS + 1),
        ]);

        $rep = Reputation::firstOrCreate(
            ['node_id' => $target->id],
            ['score' => 0.5, 'tasks_total' => 0, 'tasks_failed' => 0, 'completed_tasks_count' => 0, 'avg_latency_ms' => 0.0],
        );

        $this->withToken($this->plainProxyToken)
            ->postJson('/api/v1/telemetry', $this->validPayload($target->id, $proxy->id))
            ->assertStatus(202);

        $rep->refresh();
        $this->assertNotNull($rep->observed_latency_ms,
            'RT-03b: 3 eligible proxies must satisfy quorum and apply EMA');
    }
}

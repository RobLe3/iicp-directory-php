<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Http\Controllers\StatsController;
use App\Jobs\AggregateProbeMetricsJob;
use App\Models\Node;
use App\Models\NodeHealthObservation;
use App\Models\TelemetryProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/v1/stats — public stats endpoint (StatsController).
 *
 * Covers: credit_schedule field (#305), server block, probes block.
 * CORC D6 — Credit/Billing Implementation: rate schedule must be discoverable
 * by clients without reading the spec (research/credit-rate-calibration/01).
 */
class StatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_stats_returns_200_with_server_and_probes_blocks(): void
    {
        $response = $this->getJson('/api/v1/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'server', 'probes', 'resilience',
                'dispatch_discovery_adoption' => [
                    'basis', 'window_days', 'ticketed_requests',
                    'legacy_route_discovery_requests', 'public_view_requests',
                    'ticketed_share', 'retention_days', 'contains_caller_identifiers',
                    'measurement_valid_since', 'measurement_days_observed',
                    'measurement_window_complete', 'minimum_sample_requests',
                    'sample_eligible', 'cutover_share_threshold',
                    'cutover_sustained_days', 'cutover_eligible', 'measurement_limits',
                ],
            ]);
    }

    public function test_stats_includes_credit_schedule_field(): void
    {
        $response = $this->getJson('/api/v1/stats');

        $response->assertStatus(200)
            ->assertJsonPath('credit_schedule.formula', 'ceil(output_tokens / tokens_per_credit) × tier_weight × node_multiplier')
            ->assertJsonPath('credit_schedule.tokens_per_credit', 1000)
            ->assertJsonPath('credit_schedule.burn_rate_pct', 2);
    }

    public function test_mesh_health_federated_null_without_observations(): void
    {
        // ADR-048 (#374): non-federated directory — no HEALTH events applied yet, so the
        // federation aggregate is null and single-directory mesh_health stays authoritative.
        $response = $this->getJson('/api/v1/stats');

        $response->assertStatus(200)
            ->assertJsonPath('mesh_health_federated', null)
            ->assertJsonStructure(['mesh_health']);
    }

    public function test_mesh_health_federated_present_once_health_applied(): void
    {
        // ADR-048 (#374): three evaluators agree node-x is healthy → majority aggregate.
        foreach (['e1', 'e2', 'e3'] as $e) {
            NodeHealthObservation::create([
                'node_id' => 'node-x',
                'evaluator_did' => "did:web:$e",
                'score' => 0.90,
                'evaluated_at_ms' => 1_700_000_000_000,
            ]);
        }

        $response = $this->getJson('/api/v1/stats');

        $response->assertStatus(200)
            ->assertJsonPath('mesh_health_federated.sample', 1)
            ->assertJsonPath('mesh_health_federated.basis', 'federated_union');
    }

    public function test_credit_schedule_contains_all_tier_weights(): void
    {
        $response = $this->getJson('/api/v1/stats');

        $tiers = $response->json('credit_schedule.tier_weights');

        $this->assertIsArray($tiers);
        $this->assertArrayHasKey('sub_1b', $tiers);
        $this->assertArrayHasKey('7b', $tiers);
        $this->assertArrayHasKey('13b', $tiers);
        $this->assertArrayHasKey('30b', $tiers);
        $this->assertArrayHasKey('70b', $tiers);
        $this->assertArrayHasKey('100b_plus', $tiers);

        // Verify reference rate: 7B tier weight = 1.0 (base rate)
        $this->assertEquals(1.0, $tiers['7b']);
        // Verify sub_1b is the cheapest tier
        $this->assertLessThan($tiers['7b'], $tiers['sub_1b']);
        // Verify tier ordering: sub_1b < 7b < 13b < 30b < 70b < 100b_plus
        $this->assertLessThan($tiers['13b'], $tiers['7b']);
        $this->assertLessThan($tiers['30b'], $tiers['13b']);
        $this->assertLessThan($tiers['70b'], $tiers['30b']);
        $this->assertLessThan($tiers['100b_plus'], $tiers['70b']);
    }

    public function test_credit_schedule_evaluation_grant_is_correct(): void
    {
        $response = $this->getJson('/api/v1/stats');

        $response->assertStatus(200)
            ->assertJsonPath('credit_schedule.evaluation_grant.credits', 5)
            ->assertJsonPath('credit_schedule.evaluation_grant.interval_seconds', 21600);
    }

    public function test_stats_server_block_includes_active_nodes(): void
    {
        $response = $this->getJson('/api/v1/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'server' => [
                    'version',
                    'active_nodes',
                    'public_routable_nodes',
                    'heartbeating_nodes',
                    'limited_reach_nodes',
                    'key_ready_nodes',
                    'downlevel_nodes',
                    'internal_nodes',
                    'stale_active_nodes',
                    'uptime_seconds',
                ],
                'resilience' => [
                    'active_window_s',
                    'recent_window_s',
                    'visible_nodes_now',
                    'heartbeating_nodes_now',
                    'recovering_nodes_now',
                    'relay_available_now',
                    'relay_capable_nodes_now',
                    'last_relay_seen_at',
                    'recovery_window',
                ],
            ]);
    }

    public function test_stats_public_document_does_not_leak_route_endpoints_or_full_uuid(): void
    {
        $node = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://associated-green-levy-lesser.trycloudflare.com',
            'transport_endpoint' => 'iicpsec://associated-green-levy-lesser.trycloudflare.com',
            'transport_method' => 'external_tunnel',
            'transport_metadata' => ['detection_log_tail' => ['rung 5: quick tunnel']],
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'public_reachable' => true,
            'status' => 'active',
            'last_seen' => now(),
            'sdk_language' => 'rust',
            'sdk_version' => '0.7.82',
        ]);

        $content = $this->getJson('/api/v1/stats')->assertOk()->getContent();

        $this->assertStringNotContainsString($node->id, $content);
        $this->assertStringNotContainsString('associated-green-levy-lesser.trycloudflare.com', $content);
        $this->assertStringNotContainsString('iicpsec://', $content);
        $this->assertStringNotContainsString('transport_endpoint', $content);
        $this->assertStringNotContainsString('transport_metadata', $content);
    }

    public function test_empty_public_stats_snapshot_expires_quickly_when_nodes_reappear(): void
    {
        $this->getJson('/api/v1/stats')
            ->assertStatus(200)
            ->assertJsonPath('server.active_nodes', 0);

        $this->travel(6)->seconds();

        Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://recovering-node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'public_reachable' => true,
            'status' => 'active',
            'last_seen' => now(),
        ]);

        $this->getJson('/api/v1/stats')
            ->assertStatus(200)
            ->assertJsonPath('server.active_nodes', 1);
    }

    public function test_warm_stats_cache_does_not_pin_empty_mesh_for_full_warm_ttl(): void
    {
        $this->artisan('iicp:warm-stats-cache')
            ->assertSuccessful();

        $this->travel(6)->seconds();

        Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://warm-recovering-node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'public_reachable' => true,
            'status' => 'active',
            'last_seen' => now(),
        ]);

        $this->getJson('/api/v1/stats')
            ->assertStatus(200)
            ->assertJsonPath('server.active_nodes', 1);
    }

    /**
     * active_nodes MUST mirror /v1/discover exactly — a node that is available=true
     * and recently-seen but NOT status='active' (e.g. mid-dormancy: LivenessMonitor
     * sets status='dormant' before the next sweep flips available) is excluded by
     * discover, so stats must not count it. Regression guard for the stats/discover
     * filter divergence where active_nodes omitted the status='active' clause and
     * overcounted relative to what discover returns.
     */
    public function test_active_nodes_excludes_dormant_status_to_match_discover(): void
    {
        // available=true + recently-seen + reachable, but status='dormant'.
        Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://dormant.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'public_reachable' => true,
            'status' => 'dormant',
            'last_seen' => now(),
        ]);

        $response = $this->getJson('/api/v1/stats')->assertStatus(200);
        $this->assertSame(0, $response->json('server.active_nodes'),
            'a status!=active node must not be counted in active_nodes (discover excludes it)');
    }

    /**
     * active_nodes is the public discoverable serving set, not merely "recently
     * heartbeating rows". A confirmed-dead endpoint is hidden from normal
     * discover, so stats must not continue to claim it as public-active.
     */
    public function test_active_nodes_excludes_confirmed_dead_endpoint_to_match_discover(): void
    {
        Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://dead-tunnel.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'public_reachable' => true,
            'status' => 'active',
            'last_seen' => now(),
            'endpoint_verified_dead_at' => now()->subMinutes(5),
        ]);

        $response = $this->getJson('/api/v1/stats')->assertStatus(200);

        $this->assertSame(0, $response->json('server.active_nodes'),
            'a confirmed-dead endpoint must not be counted in public active_nodes (discover excludes it)');
        $this->assertSame(1, $response->json('server.internal_nodes'),
            'hidden-but-heartbeating nodes remain visible as internal/diagnostic active rows');
    }

    public function test_stats_exposes_unambiguous_node_count_buckets(): void
    {
        $mk = function (array $overrides = []) {
            return Node::create(array_merge([
                'id' => (string) Str::uuid(),
                'endpoint' => 'https://node.example.com',
                'region' => 'eu-central',
                'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
                'max_concurrent' => 4,
                'tokens_per_min' => 10000,
                'available' => true,
                'status' => 'active',
                'last_seen' => now(),
                'sdk_language' => 'rust',
                'sdk_version' => '0.7.68',
                'cx_public_key' => ['algorithm' => 'X25519', 'key' => 'abc'],
            ], $overrides));
        };

        $mk(['public_reachable' => true]);
        $mk([
            'public_reachable' => false,
            'exposure_mode' => 'relay_required',
            'sdk_language' => 'python',
            'sdk_version' => '0.7.62',
            'cx_public_key' => null,
        ]);
        $mk([
            'endpoint' => 'http://[2001:db8::1]:9484',
            'public_reachable' => false,
            'exposure_mode' => null,
        ]);
        $mk([
            'endpoint' => 'http://[2001:db8::2]:9484',
            'public_reachable' => false,
            'exposure_mode' => null,
            'sdk_version' => '0.7.69',
        ]);

        $response = $this->getJson('/api/v1/stats')->assertStatus(200);

        $this->assertSame(2, $response->json('server.active_nodes'));
        $this->assertSame(2, $response->json('server.public_routable_nodes'));
        $this->assertSame(4, $response->json('server.heartbeating_nodes'));
        $this->assertSame(2, $response->json('server.limited_reach_nodes'));
        $this->assertSame(3, $response->json('server.key_ready_nodes'));
        $this->assertSame(1, $response->json('server.downlevel_nodes'));
        $this->assertSame('heartbeating_nodes', $response->json('sdk_adoption.basis'));
        $this->assertSame(4, $response->json('sdk_adoption.total_heartbeating'));
    }

    public function test_stats_resilience_marks_recovery_window_without_hiding_limits(): void
    {
        Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://ready.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'public_reachable' => true,
            'relay_capable' => false,
            'status' => 'active',
            'last_seen' => now(),
        ]);
        Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://cooldown.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'public_reachable' => true,
            'relay_capable' => true,
            'status' => 'active',
            'last_seen' => now(),
            'endpoint_verified_dead_at' => now()->subMinute(),
        ]);
        Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://recent-relay.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'public_reachable' => true,
            'relay_capable' => true,
            'status' => 'active',
            'last_seen' => now()->subSeconds(150),
        ]);

        $response = $this->getJson('/api/v1/stats')->assertStatus(200);

        $this->assertSame(1, $response->json('resilience.visible_nodes_now'));
        $this->assertSame(2, $response->json('resilience.heartbeating_nodes_now'));
        $this->assertSame(1, $response->json('resilience.limited_reach_nodes_now'));
        $this->assertSame(3, $response->json('resilience.recent_nodes'));
        $this->assertSame(2, $response->json('resilience.recovering_nodes_now'));
        $this->assertFalse($response->json('resilience.relay_available_now'));
        $this->assertTrue($response->json('resilience.recovery_window'));
        $this->assertNotNull($response->json('resilience.last_relay_seen_at'));
    }

    /*
     * #335 — stale_active_nodes counts available=true rows with last_seen >24h.
     * Healthy steady-state value is 0. The post-deploy integrity gate alerts
     * when this is non-zero (indicates NodeLifecycleCommand stopped firing).
     */
    public function test_stats_stale_active_nodes_is_integer_and_zero_in_empty_state(): void
    {
        $response = $this->getJson('/api/v1/stats');
        $response->assertStatus(200);
        $val = $response->json('server.stale_active_nodes');
        $this->assertIsInt($val);
        $this->assertGreaterThanOrEqual(0, $val);
    }

    public function test_stats_includes_mesh_health_block(): void
    {
        $response = $this->getJson('/api/v1/stats');

        $response->assertStatus(200)
            ->assertJsonStructure(['mesh_health' => ['score', 'label', 'window']]);
    }

    /*
     * ADR-044 (#372) — mesh_health is a node-aggregate (median over active
     * provider nodes) with distribution + sample, not a directory-infra blend.
     */
    public function test_stats_mesh_health_is_node_aggregate(): void
    {
        $response = $this->getJson('/api/v1/stats');

        $response->assertStatus(200)
            ->assertJsonStructure(['mesh_health' => ['score', 'label', 'mean', 'p10', 'distribution', 'sample', 'basis', 'window']])
            ->assertJsonPath('mesh_health.basis', 'active_provider_nodes');

        // Empty test DB → no active nodes → unavailable, sample 0.
        $response->assertJsonPath('mesh_health.label', 'unavailable')
            ->assertJsonPath('mesh_health.sample', 0);
    }

    /*
     * ADR-044 (#372) — directory-infrastructure signals (discover latency,
     * conformance, REACH reachability) live in their own directory_health block,
     * no longer conflated into mesh_health.
     */
    public function test_stats_includes_directory_health_block(): void
    {
        $response = $this->getJson('/api/v1/stats');

        $response->assertStatus(200)
            ->assertJsonStructure(['directory_health' => ['score', 'label', 'window']]);
    }

    public function test_stats_aggregate_includes_task_success_rate(): void
    {
        $response = $this->getJson('/api/v1/stats');

        $response->assertStatus(200)
            ->assertJsonStructure(['probes' => ['aggregate_24h' => ['task_success_rate_pct']]]);
    }

    /*
     * #338 — top_failures array is always present in conformance_24h, even
     * when empty. Lets operators triage the failing probes without log diving.
     */
    public function test_stats_conformance_includes_top_failures_array(): void
    {
        $response = $this->getJson('/api/v1/stats');

        $response->assertStatus(200);
        $top = $response->json('probes.conformance_24h.top_failures');
        $this->assertIsArray($top, 'conformance_24h.top_failures must be an array');
        // In the empty test DB, no probes have run → top_failures is []
        $this->assertCount(0, $top);
    }

    public function test_stats_top_failures_surfaces_failing_probes(): void
    {
        // Create a ProbeToken first (FK target)
        $tokenId = \DB::table('probe_tokens')->insertGetId([
            'token_hash' => hash('sha256', 'test-token'),
            'label' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $now = now();
        for ($i = 0; $i < 3; $i++) {
            \DB::table('iicp_telemetry_probes')->insert([
                'probe_token_id' => $tokenId,
                'run_id' => 'r'.$i,
                'probe_id' => 'p'.$i,
                'probe_type' => 'conformance',
                'test_id' => 'DIR-STATS-01',
                'level' => 'MUST',
                'passed' => false,
                'latency_ms' => 100,
                'probed_at' => $now,
            ]);
        }
        \DB::table('iicp_telemetry_probes')->insert([
            'probe_token_id' => $tokenId,
            'run_id' => 'rok',
            'probe_id' => 'pok',
            'probe_type' => 'conformance',
            'test_id' => 'DIR-STATS-01',
            'level' => 'MUST',
            'passed' => true,
            'latency_ms' => 100,
            'probed_at' => $now,
        ]);

        $response = $this->getJson('/api/v1/stats');
        $top = $response->json('probes.conformance_24h.top_failures');
        $this->assertNotEmpty($top);
        $this->assertSame('DIR-STATS-01', $top[0]['test_id']);
        $this->assertSame(3, $top[0]['failed']);
        $this->assertSame(1, $top[0]['passed']);
        $this->assertSame(4, $top[0]['total']);
        $this->assertEqualsWithDelta(0.75, $top[0]['fail_rate'], 0.001);
    }

    /**
     * #508 latency decomposition: the aggregate job must split DIR-DISC-01
     * samples into app-processing (query_ms), CDN-edge (cf HIT) and CDN→origin
     * (cf EXPIRED/MISS) percentiles, and directory_health must score on the
     * directory's own processing time — not the CDN transport path the 5-min
     * probe cadence systematically worst-cases. Fails if the score basis
     * regresses to wall latency while query data exists.
     */
    public function test_directory_health_scores_on_query_latency_not_cdn_transport(): void
    {
        // 4 probe samples: origin-pull walls ~330ms, one edge HIT 25ms; the app
        // itself spent ~30ms on each (query_ms).
        foreach ([[330, 'EXPIRED', 28, 'miss'], [340, 'EXPIRED', 31, 'hit'], [320, 'MISS', 30, 'miss'], [25, 'HIT', 32, 'hit']] as [$wall, $cf, $q, $originCache]) {
            TelemetryProbe::create([
                'probe_token_id' => null,
                'node_id' => null,
                'run_id' => 'run-agg',
                'probe_id' => 'reach',
                'probe_type' => 'conformance',
                'test_id' => 'DIR-DISC-01',
                'level' => 'MUST',
                'passed' => true,
                'latency_ms' => $wall,
                'detail' => 'test',
                'metadata' => [
                    'cf_cache_status' => $cf,
                    'directory_query_ms' => $q,
                    'directory_origin_cache_state' => $originCache,
                ],
                'probed_at' => now()->subMinutes(5),
            ]);
        }

        (new AggregateProbeMetricsJob)->handle();
        Cache::forget('stats.public');

        $body = $this->getJson('/api/v1/stats')->assertOk()->json();

        $agg = $body['probes']['aggregate_24h'];
        $this->assertEqualsWithDelta(31, $agg['discover_query_p50_ms'], 2.0, 'app-processing p50');
        $this->assertEqualsWithDelta(31, $agg['discover_query_cache_hit_p50_ms'], 1.0, 'origin cache-hit app p50');
        $this->assertEqualsWithDelta(28, $agg['discover_query_cache_miss_p50_ms'], 1.0, 'origin cache-miss app p50');
        $this->assertNull($agg['discover_query_cache_bypass_p50_ms'], 'no bypass sample');
        $this->assertEqualsWithDelta(25, $agg['discover_edge_p50_ms'], 1.0, 'edge-HIT p50');
        $this->assertEqualsWithDelta(330, $agg['discover_origin_p50_ms'], 15.0, 'origin-pull p50');

        $dh = $body['directory_health'];
        $this->assertSame('query', $dh['latency_basis']);
        // query p50 ~31ms ≤ 50ms → latency component 1.0; with full conformance
        // the label must be healthy — NOT impaired-by-CDN-transport.
        $this->assertSame('healthy', $dh['label']);
        $this->assertEqualsWithDelta(330, $dh['discover_origin_p50_ms'], 15.0, 'origin figure stays exposed');
    }

    /** Without metadata-bearing samples the score falls back to wall latency (basis=wall). */
    public function test_directory_health_falls_back_to_wall_basis(): void
    {
        TelemetryProbe::create([
            'probe_token_id' => null,
            'node_id' => null,
            'run_id' => 'run-agg2',
            'probe_id' => 'reach',
            'probe_type' => 'conformance',
            'test_id' => 'DIR-DISC-01',
            'level' => 'MUST',
            'passed' => true,
            'latency_ms' => 300,
            'detail' => 'test',
            'probed_at' => now()->subMinutes(5),
        ]);

        (new AggregateProbeMetricsJob)->handle();
        Cache::forget('stats.public');

        $dh = $this->getJson('/api/v1/stats')->assertOk()->json()['directory_health'];
        $this->assertSame('wall', $dh['latency_basis']);
    }

    public function test_stats_exposes_uncached_directory_implementation_timing_separately(): void
    {
        TelemetryProbe::create([
            'probe_token_id' => null,
            'node_id' => null,
            'run_id' => 'run-cache-bypass',
            'probe_id' => 'reach',
            'probe_type' => 'conformance',
            'test_id' => 'DIR-DISC-01',
            'level' => 'MUST',
            'passed' => true,
            'latency_ms' => 220,
            'detail' => 'test',
            'metadata' => [
                'cf_cache_status' => 'MISS',
                'directory_query_ms' => 19,
                'directory_origin_cache_state' => 'bypass',
            ],
            'probed_at' => now()->subMinutes(5),
        ]);

        (new AggregateProbeMetricsJob)->handle();
        Cache::forget('stats.public');

        $aggregate = $this->getJson('/api/v1/stats')->assertOk()->json('probes.aggregate_24h');
        $this->assertEquals(19.0, $aggregate['discover_query_cache_bypass_p50_ms']);
    }

    /**
     * GAPS-020 measurement-defect cutoff: latency samples recorded before
     * 2026-06-11T11:25Z carried the probe's own client-construction overhead
     * (~70-450ms inflation) and must NOT pollute latency percentiles. The rows
     * stay (conformance counting untouched); only latency aggregation skips them.
     */
    public function test_pre_cutoff_inflated_latency_samples_excluded(): void
    {
        // One absurd pre-cutoff sample + two honest post-cutoff samples.
        foreach ([
            ['2026-06-11 09:00:00', 99999.0],
            [now()->subMinutes(10), 30.0],
            [now()->subMinutes(5), 34.0],
        ] as [$at, $lat]) {
            TelemetryProbe::create([
                'probe_token_id' => null,
                'node_id' => null,
                'run_id' => 'run-cutoff',
                'probe_id' => 'reach',
                'probe_type' => 'conformance',
                'test_id' => 'DIR-DISC-01',
                'level' => 'MUST',
                'passed' => true,
                'latency_ms' => $lat,
                'detail' => 'test',
                'probed_at' => $at,
            ]);
        }

        (new AggregateProbeMetricsJob)->handle();
        Cache::forget('stats.public');

        $agg = $this->getJson('/api/v1/stats')->assertOk()->json()['probes']['aggregate_24h'];
        $this->assertNotNull($agg['discover_p95_ms']);
        $this->assertLessThan(100, $agg['discover_p95_ms'],
            'pre-cutoff inflated sample must not drive the p95');
    }

    /** @test #531 — /stats exposes sdk_version + sdk_language adoption distribution */
    public function test_stats_includes_sdk_adoption_distribution(): void
    {
        $mk = function (string $lang, string $ver) {
            Node::create([
                'id' => (string) Str::uuid(),
                'endpoint' => 'https://node.example.com',
                'region' => 'eu-central',
                'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
                'max_concurrent' => 4,
                'tokens_per_min' => 10000,
                'available' => true,
                'public_reachable' => true,
                'status' => 'active',
                'last_seen' => now(),
                'sdk_language' => $lang,
                'sdk_version' => $ver,
            ]);
        };
        $mk('rust', '0.7.58');
        $mk('rust', '0.7.58');
        $mk('python', '0.7.57');

        $resp = $this->getJson('/api/v1/stats')->assertStatus(200)
            ->assertJsonStructure(['sdk_adoption' => ['total_active', 'by_language', 'by_version']]);
        $this->assertSame(3, $resp->json('sdk_adoption.total_active'));
        $this->assertSame(2, $resp->json('sdk_adoption.by_language.rust'));
        $this->assertSame(1, $resp->json('sdk_adoption.by_language.python'));
        $byVersion = $resp->json('sdk_adoption.by_version');
        $this->assertSame(2, $byVersion['0.7.58']);

        // Regression: the value MUST be plain arrays, not Collections — a cached
        // Collection comes back as __PHP_Incomplete_Class in the serializing
        // prod cache and breaks the public JSON. Assert it survives a
        // serialize/unserialize round-trip as an array (the cache condition).
        $adoption = app(StatsController::class)->build()['sdk_adoption'];
        $roundTripped = unserialize(serialize($adoption));
        $this->assertIsArray($roundTripped['by_language']);
        $this->assertIsArray($roundTripped['by_version']);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
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
            ->assertJsonStructure(['server', 'probes']);
    }

    public function test_stats_includes_credit_schedule_field(): void
    {
        $response = $this->getJson('/api/v1/stats');

        $response->assertStatus(200)
            ->assertJsonPath('credit_schedule.formula', 'ceil(output_tokens / tokens_per_credit) × tier_weight × node_multiplier')
            ->assertJsonPath('credit_schedule.tokens_per_credit', 1000)
            ->assertJsonPath('credit_schedule.burn_rate_pct', 2);
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
                    'internal_nodes',
                    'stale_active_nodes',
                    'uptime_seconds',
                ],
            ]);
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
}

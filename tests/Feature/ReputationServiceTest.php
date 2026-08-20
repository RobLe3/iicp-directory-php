<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Services\ReputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REP-01..REP-03: Normative reputation delta rules — spec §11.2 / conformance-test-suite §13.6 (#113).
 */
class ReputationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReputationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReputationService::class);
    }

    private function makeNode(): Node
    {
        return Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://rep-test.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('tok', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
        ]);
    }

    // REP-01: a successful outcome increases score; latency is separate evidence.
    public function test_rep01_success_within_budget_increases_score(): void
    {
        $node = $this->makeNode();
        $this->service->upsert($node->id, tasksSuccess: 1, tasksFailed: 0, avgLatencyMs: 500.0);

        $rep = $node->reputation()->first();
        $this->assertGreaterThan(0.5, $rep->score, 'REP-01: score must exceed initial 0.5 after success within budget');
    }

    // REP-02: failed task decreases score
    public function test_rep02_failed_task_decreases_score(): void
    {
        $node = $this->makeNode();
        $this->service->upsert($node->id, tasksSuccess: 0, tasksFailed: 1, avgLatencyMs: 0.0);

        $rep = $node->reputation()->first();
        $this->assertLessThan(0.5, $rep->score, 'REP-02: score must fall below initial 0.5 after failure');
    }

    // REP-03: score never falls below 0.0 (clamp lower bound)
    public function test_rep03_score_clamped_at_zero(): void
    {
        $node = $this->makeNode();
        // Apply 100 failures — without clamping this would go far negative
        for ($i = 0; $i < 20; $i++) {
            $this->service->upsert($node->id, tasksSuccess: 0, tasksFailed: 5, avgLatencyMs: 0.0);
        }

        $rep = $node->reputation()->first();
        $this->assertGreaterThanOrEqual(0.0, $rep->score, 'REP-03: score must never go below 0.0');
    }

    // REP-03 upper bound: score never exceeds 1.0
    public function test_rep03_score_clamped_at_one(): void
    {
        $node = $this->makeNode();
        // Apply 200 fast successes — without clamping this would exceed 1.0
        for ($i = 0; $i < 40; $i++) {
            $this->service->upsert($node->id, tasksSuccess: 5, tasksFailed: 0, avgLatencyMs: 100.0);
        }

        $rep = $node->reputation()->first();
        $this->assertLessThanOrEqual(1.0, $rep->score, 'REP-03: score must never exceed 1.0');
    }

    public function test_slow_success_increases_outcome_reputation(): void
    {
        $node = $this->makeNode();
        $this->service->upsert($node->id, tasksSuccess: 1, tasksFailed: 0, avgLatencyMs: 5000.0);

        $rep = $node->reputation()->first();
        $this->assertEquals(0.51, round($rep->score, 4));
    }

    public function test_moderately_slow_success_increases_outcome_reputation(): void
    {
        $node = $this->makeNode();
        $this->service->upsert($node->id, tasksSuccess: 1, tasksFailed: 0, avgLatencyMs: 3000.0);

        $rep = $node->reputation()->first();
        $this->assertEquals(0.51, round($rep->score, 4));

    }

    public function test_metrics_batch_is_applied_at_most_once(): void
    {
        $node = $this->makeNode();
        $this->assertTrue($this->service->upsert($node->id, 1, 0, 5000.0, 'batch-a'));
        $this->assertFalse($this->service->upsert($node->id, 1, 0, 5000.0, 'batch-a'));

        $rep = $node->reputation()->first();
        $this->assertEquals(0.51, round($rep->score, 4));
        $this->assertSame(1, $rep->tasks_total);
    }

    public function test_canonical_outcome_v2_fixture_is_pinned_to_php_cases(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('parity/reputation-outcome-v2.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $cases = array_column($fixture['cases'], null, 'name');

        $this->assertSame('outcome-v2', $fixture['reputation_model']);
        $this->assertSame(0.51, $cases['successful_slow_unknown_qos']['expected']['score']);
        $this->assertSame(0.48, $cases['mixed_batch']['expected']['score']);
        $this->assertSame(0.6, $cases['positive_cap']['expected']['score']);
        $this->assertSame(1, $cases['duplicate_batch']['expected']['applied_batches']);
        $this->assertFalse($cases['legacy_missing_model']['expected']['may_label_outcome_v2']);
    }

    // Multiple successes accumulate deltas correctly
    public function test_multiple_successes_accumulate(): void
    {
        $node = $this->makeNode();
        $this->service->upsert($node->id, tasksSuccess: 5, tasksFailed: 0, avgLatencyMs: 100.0);

        $rep = $node->reputation()->first();
        // 5 × 0.01 = +0.05 → 0.55
        $this->assertEquals(0.55, round($rep->score, 4));
    }

    // Mixed batch: successes and failures in same heartbeat
    public function test_mixed_batch_applies_net_delta(): void
    {
        $node = $this->makeNode();
        // 2 successes (+0.02) + 1 failure (−0.05) = net −0.03 → 0.47
        $this->service->upsert($node->id, tasksSuccess: 2, tasksFailed: 1, avgLatencyMs: 500.0);

        $rep = $node->reputation()->first();
        $this->assertEquals(0.47, round($rep->score, 4));
    }

    /**
     * RT-01b (#381): per-node hourly velocity ceiling.
     * Successive heartbeats within the 1h window must be capped at MAX_HOURLY_REPUTATION_GAIN=0.20.
     */
    public function test_rt01b_hourly_velocity_ceiling_caps_gain(): void
    {
        $node = $this->makeNode();

        // Apply 10 heartbeats, each with +0.10 (MAX_POSITIVE_DELTA_PER_HEARTBEAT)
        // Without velocity ceiling: 10 × 0.10 = +1.0 → score=1.0 in 10 calls
        for ($i = 0; $i < 10; $i++) {
            $this->service->upsert($node->id, tasksSuccess: 10, tasksFailed: 0, avgLatencyMs: 100.0);
        }

        $node->refresh();
        $maxExpected = 0.5 + ReputationService::MAX_HOURLY_REPUTATION_GAIN;
        $this->assertLessThanOrEqual(
            $maxExpected + 0.001,
            (float) $node->reputation_score,
            sprintf(
                'RT-01b: score must not exceed initial+0.20 within 1h (got %.4f, max %.4f)',
                $node->reputation_score,
                $maxExpected
            )
        );
    }

    /** Shared pre-normative RT-01b fixture stays aligned with the local service contract. */
    public function test_rt01b_shared_hourly_velocity_fixture_matches_service_contract(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('parity/reputation-hourly-velocity-v0.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('0.1.0-draft', $fixture['fixture_version']);
        $this->assertSame(['php', 'rust'], $fixture['scope']['implementation_flavors']);
        $this->assertSame('disposable_mysql', $fixture['scope']['database_mode']);
        $this->assertSame(ReputationService::MAX_HOURLY_REPUTATION_GAIN, $fixture['inputs']['maximum_hourly_positive_gain']);
        $this->assertSame(4, $fixture['inputs']['workers']);
        $this->assertSame(10, $fixture['inputs']['tasks_success_per_worker']);
        $this->assertSame(0.7, $fixture['expected']['concurrent_score']);
        $this->assertSame(0.2, $fixture['expected']['concurrent_hourly_gain']);
        $this->assertSame(40, $fixture['expected']['concurrent_tasks_total']);
        $this->assertSame(3599, $fixture['expected']['same_window_age_seconds']);
        $this->assertSame(3600, $fixture['expected']['next_window_age_seconds']);
        $this->assertSame(0.85, $fixture['expected']['final_score_after_reload_and_negative']);
    }

    /** RT-01b: velocity window resets after 1 hour (allows another 0.20 gain cycle). */
    public function test_rt01b_velocity_window_resets_after_one_hour(): void
    {
        $node = $this->makeNode();

        // Saturate the velocity window
        for ($i = 0; $i < 5; $i++) {
            $this->service->upsert($node->id, tasksSuccess: 10, tasksFailed: 0, avgLatencyMs: 100.0);
        }

        // Simulate window expiry by back-dating rep_hourly_window_start
        $node->rep_hourly_window_start = now()->subHours(2);
        $node->save();

        // One more heartbeat — window expired, should allow gain again
        $scoreBefore = (float) $node->fresh()->reputation_score;
        $this->service->upsert($node->id, tasksSuccess: 10, tasksFailed: 0, avgLatencyMs: 100.0);
        $scoreAfter = (float) $node->fresh()->reputation_score;

        $this->assertGreaterThan($scoreBefore, $scoreAfter,
            'RT-01b: after 1h window expiry, a new heartbeat must be able to increase score');
    }

    public function test_rt01b_window_boundary_is_closed_at_3600_seconds(): void
    {
        $anchor = now()->startOfSecond();
        $this->travelTo($anchor);
        $node = $this->makeNode();

        $this->service->upsert($node->id, tasksSuccess: 10, tasksFailed: 0, avgLatencyMs: 100.0);
        $this->service->upsert($node->id, tasksSuccess: 10, tasksFailed: 0, avgLatencyMs: 100.0);

        $node->forceFill(['rep_hourly_window_start' => $anchor->copy()->subSeconds(3599)])->save();
        $this->service->upsert($node->id, tasksSuccess: 10, tasksFailed: 0, avgLatencyMs: 100.0);
        $this->assertSame(0.7, (float) $node->fresh()->reputation_score);

        $node->forceFill(['rep_hourly_window_start' => $anchor->copy()->subSeconds(3600)])->save();
        $this->service->upsert($node->id, tasksSuccess: 10, tasksFailed: 0, avgLatencyMs: 100.0);
        $this->assertSame(0.8, (float) $node->fresh()->reputation_score);
        $this->assertSame(0.1, (float) $node->fresh()->rep_hourly_gain);
    }

    public function test_rt01b_negative_delta_does_not_consume_or_restore_positive_budget(): void
    {
        $node = $this->makeNode();

        $this->service->upsert($node->id, tasksSuccess: 10, tasksFailed: 0, avgLatencyMs: 100.0);
        $this->service->upsert($node->id, tasksSuccess: 0, tasksFailed: 1, avgLatencyMs: 0.0);

        $node->refresh();
        $this->assertSame(0.1, (float) $node->rep_hourly_gain);
        $this->assertSame(0.55, (float) $node->reputation_score);

        app()->forgetInstance(ReputationService::class);
        app(ReputationService::class)->upsert(
            $node->id,
            tasksSuccess: 10,
            tasksFailed: 0,
            avgLatencyMs: 100.0,
        );

        $node->refresh();
        $this->assertSame(0.2, (float) $node->rep_hourly_gain);
        $this->assertSame(0.65, (float) $node->reputation_score);
    }
}

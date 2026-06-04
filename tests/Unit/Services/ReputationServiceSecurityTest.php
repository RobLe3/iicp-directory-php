<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ReputationService;
use Tests\TestCase;

/**
 * RT-01 security regression tests (#375).
 * Verifies that a single heartbeat cannot inflate reputation from 0.5 → 1.0.
 */
class ReputationServiceSecurityTest extends TestCase
{
    private ReputationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReputationService::class);
    }

    /** RT-01: 100 claimed tasks in one heartbeat must not max out reputation. */
    public function test_single_heartbeat_with_100_tasks_does_not_reach_max_score(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('computeDelta');
        $method->setAccessible(true);

        // Attack vector: tasks_success=100, avg_latency_ms=0 → uncapped would be +1.0
        $delta = $method->invoke($this->service, 100, 0, 0.0);

        // RT-01 fix: delta capped at MAX_POSITIVE_DELTA_PER_HEARTBEAT (0.10)
        $this->assertLessThanOrEqual(0.10, $delta,
            'Single heartbeat delta must not exceed 0.10 (prevents instant reputation max)');

        // Starting from 0.5, a single malicious heartbeat cannot reach 1.0
        $newScore = min(1.0, 0.5 + $delta);
        $this->assertLessThan(1.0, $newScore,
            'Score must not reach 1.0 from a single heartbeat with self-reported tasks');
    }

    /** Legitimate low-throughput node still earns reputation normally. */
    public function test_single_task_earns_expected_delta(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('computeDelta');
        $method->setAccessible(true);

        // 1 task, good latency → DELTA_SUCCESS = 0.01
        $delta = $method->invoke($this->service, 1, 0, 500.0);
        $this->assertEquals(0.01, $delta);
    }

    /** RT-01: even very high task counts (1000) are capped at 0.10 positive delta. */
    public function test_inflated_task_count_capped_at_max_delta(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('computeDelta');
        $method->setAccessible(true);

        $delta = $method->invoke($this->service, 1000, 0, 0.0);
        $this->assertLessThanOrEqual(0.10, $delta);
    }

    /** Negative delta (failures) is not affected by the positive cap. */
    public function test_failure_delta_not_capped(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('computeDelta');
        $method->setAccessible(true);

        // 10 failures → delta = 10 * -0.05 = -0.50 — must still apply fully
        $delta = $method->invoke($this->service, 0, 10, 0.0);
        $this->assertEquals(-0.50, $delta);
    }
}

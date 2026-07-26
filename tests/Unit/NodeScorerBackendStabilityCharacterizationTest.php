<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Models\Node;
use App\Services\BackendStabilityPolicy;
use App\Services\NodeScorer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NodeScorerBackendStabilityCharacterizationTest extends TestCase
{
    #[DataProvider('missingReports')]
    public function test_missing_reports_remain_unknown(mixed $report): void
    {
        $node = new Node;
        $node->backend_stability = $report;

        $this->assertSame([
            'backend_state' => 'unknown',
            'reason_class' => 'not_reported',
            'routing_guard' => 'none',
            'evidence' => 'not_reported',
            'retry_after_s' => null,
            'drain_until' => null,
            'summary' => 'Backend stability has not been reported yet.',
        ], NodeScorer::backendStability($node));
    }

    public static function missingReports(): iterable
    {
        yield 'null' => [null];
        yield 'empty array' => [[]];
    }

    #[DataProvider('reportedStates')]
    public function test_reported_states_preserve_routing_and_summary(
        array $report,
        string $state,
        string $reason,
        string $guard,
        string $summary,
    ): void {
        $node = new Node;
        $node->backend_stability = $report;

        $normalized = NodeScorer::backendStability($node);

        $this->assertSame($state, $normalized['backend_state']);
        $this->assertSame($reason, $normalized['reason_class']);
        $this->assertSame($guard, $normalized['routing_guard']);
        $this->assertSame('self_reported', $normalized['evidence']);
        $this->assertSame($summary, $normalized['summary']);
    }

    public static function reportedStates(): iterable
    {
        yield 'ready' => [
            ['backend_state' => 'ok', 'reason_class' => 'ok'],
            'ok',
            'ok',
            'none',
            'Backend reports ready for new work.',
        ];
        yield 'degraded cold' => [
            ['backend_state' => 'degraded', 'reason_class' => 'backend_cold'],
            'degraded',
            'backend_cold',
            'observe_only',
            'Backend is reachable but cold; first request may warm it up.',
        ];
        yield 'draining' => [
            ['backend_state' => 'draining', 'reason_class' => 'backend_loading'],
            'draining',
            'backend_loading',
            'avoid_for_admission',
            'Backend is draining; discovery should avoid assigning new work for now.',
        ];
    }

    public function test_invalid_tokens_and_optional_integers_keep_current_coercion(): void
    {
        $node = new Node;
        $node->backend_stability = [
            'backend_state' => 'invented',
            'reason_class' => 42,
            'retry_after_s' => '-9',
            'drain_until' => '17.9',
        ];

        $this->assertSame([
            'backend_state' => 'degraded',
            'reason_class' => 'observer_error',
            'routing_guard' => 'observe_only',
            'evidence' => 'self_reported',
            'retry_after_s' => 0,
            'drain_until' => 17,
            'summary' => 'Backend reports degraded readiness.',
        ], NodeScorer::backendStability($node));
    }

    public function test_non_numeric_optional_values_remain_null(): void
    {
        $node = new Node;
        $node->backend_stability = [
            'backend_state' => 'degraded',
            'reason_class' => 'backend_unstable',
            'retry_after_s' => 'later',
            'drain_until' => null,
        ];

        $normalized = NodeScorer::backendStability($node);

        $this->assertNull($normalized['retry_after_s']);
        $this->assertNull($normalized['drain_until']);
        $this->assertSame('Backend reports instability separate from network reachability.', $normalized['summary']);
    }

    #[DataProvider('admissionStates')]
    public function test_only_draining_blocks_admission(?array $report, bool $expected): void
    {
        $node = new Node;
        $node->backend_stability = $report;

        $this->assertSame($expected, BackendStabilityPolicy::allowsAdmission($node));
    }

    public static function admissionStates(): iterable
    {
        yield 'not reported' => [null, true];
        yield 'ready' => [['backend_state' => 'ok', 'reason_class' => 'ok'], true];
        yield 'degraded' => [['backend_state' => 'degraded', 'reason_class' => 'backend_cold'], true];
        yield 'draining' => [['backend_state' => 'draining', 'reason_class' => 'backend_loading'], false];
    }
}

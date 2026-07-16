<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class CipEconomicAttributionFixtureTest extends TestCase
{
    private function attribution(array $v): array
    {
        $querying = $v['querying_node_id'] ?? null;
        if (! $querying) {
            return ['action' => 'award', 'attribution' => 'legacy_unattributed', 'trust_weight' => 0.0];
        }
        if ($querying === ($v['serving_node_id'] ?? null)) {
            return ['action' => 'exclude', 'attribution' => 'self_node', 'trust_weight' => 0.0];
        }
        if (! ($v['querying_exists'] ?? false)) {
            return ['action' => 'reject', 'attribution' => 'unknown_querying_node', 'trust_weight' => 0.0, 'error' => 'IICP-E027'];
        }
        $serving = $v['serving_operator'] ?? null;
        $consumer = $v['querying_operator'] ?? null;
        if ($serving && $consumer && $serving === $consumer) {
            return ['action' => 'exclude', 'attribution' => 'self_operator', 'trust_weight' => 0.0];
        }
        if ($serving && $consumer) {
            return ['action' => 'award', 'attribution' => 'attributed_cross_operator', 'trust_weight' => 1.0];
        }

        return ['action' => 'award', 'attribution' => 'attributed_cross_node_unverified_operator', 'trust_weight' => 0.5];
    }

    private function receiptTime(array $v): array
    {
        if (! isset($v['completed_at'], $v['observed_at'], $v['expires_at'])) {
            return ['action' => 'reject', 'error' => 'IICP-E027'];
        }
        $completed = CarbonImmutable::parse($v['completed_at']);
        $observed = CarbonImmutable::parse($v['observed_at']);
        $expires = CarbonImmutable::parse($v['expires_at']);

        return $expires->greaterThan($completed->addSeconds(300)) || $observed->greaterThan($expires)
            ? ['action' => 'reject', 'error' => 'IICP-E027']
            : ['action' => 'accept'];
    }

    public function test_fixture_contract(): void
    {
        $data = json_decode(file_get_contents(dirname(__DIR__, 2).'/parity/cip-economic-attribution-v0.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($data['attribution_cases'] as $case) {
            $this->assertSame($case['expected'], $this->attribution($case['input']), $case['name']);
        }
        foreach ($data['heartbeat_cases'] as $case) {
            $counted = min(max(0, (int) $case['input']['tasks_success']), 300);
            $failed = max(0, (int) $case['input']['tasks_failed']);
            $this->assertSame($case['expected'], ['counted_success' => $counted, 'completed_increment' => $counted, 'lifetime_jobs_increment' => $counted + $failed], $case['name']);
        }
        foreach ($data['receipt_time_cases'] as $case) {
            $this->assertSame($case['expected'], $this->receiptTime($case['input']), $case['name']);
        }
        foreach ($data['selection_tie_cases'] as $case) {
            $eligible = array_values(array_filter($case['input']['nodes'], fn (array $node): bool => $node['eligible']));
            usort($eligible, fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['node_id'] <=> $b['node_id']);
            $this->assertSame($case['expected'], ['selected_node_id' => $eligible[0]['node_id'] ?? null], $case['name']);
        }
    }
}

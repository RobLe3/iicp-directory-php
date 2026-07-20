<?php

namespace Tests\Unit;

use App\Services\DiscoveryPhaseTiming;
use Tests\TestCase;

class DiscoveryPhaseTimingTest extends TestCase
{
    public function test_server_timing_contains_only_fixed_metric_names_and_durations(): void
    {
        $timing = new DiscoveryPhaseTiming;
        $this->assertSame('result', $timing->measure('cache', fn () => 'result'));
        $timing->set('database_hydration', 12.3456);
        $timing->set('untrusted-node-id', 999);

        $header = $timing->serverTiming();
        $this->assertMatchesRegularExpression('/^iicp_cache;dur=\d+\.\d{3}, iicp_db;dur=12\.346$/', $header);
        $this->assertStringNotContainsString('untrusted', $header);
        $this->assertStringNotContainsString('node', $header);
    }

    public function test_negative_values_are_clamped(): void
    {
        $timing = new DiscoveryPhaseTiming;
        $timing->set('total', -1);

        $this->assertSame('iicp_total;dur=0.000', $timing->serverTiming());
    }

    public function test_unknown_measured_phase_is_rejected_before_execution(): void
    {
        $executed = false;
        $timing = new DiscoveryPhaseTiming;
        $this->expectException(\InvalidArgumentException::class);
        try {
            $timing->measure('node-id', function () use (&$executed): void {
                $executed = true;
            });
        } finally {
            $this->assertFalse($executed);
        }
    }
}

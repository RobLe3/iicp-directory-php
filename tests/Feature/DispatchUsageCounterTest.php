<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Services\DispatchUsageCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DispatchUsageCounterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_summary_is_anonymous_and_reports_ticketed_share(): void
    {
        Carbon::setTestNow('2026-07-12 12:00:00 UTC');
        config(['app.iicp_dispatch_adoption_valid_from' => '2026-07-11']);
        $counter = app(DispatchUsageCounter::class);
        $counter->record(DispatchUsageCounter::TICKETED_DISPATCH);
        $counter->record(DispatchUsageCounter::TICKETED_DISPATCH);
        $counter->record(DispatchUsageCounter::LEGACY_DISPATCH);
        $counter->record(DispatchUsageCounter::PUBLIC_VIEW);

        $summary = $counter->summary();

        $this->assertSame(2, $summary['ticketed_requests']);
        $this->assertSame(1, $summary['legacy_route_discovery_requests']);
        $this->assertSame(1, $summary['public_view_requests']);
        $this->assertSame(0.6667, $summary['ticketed_share']);
        $this->assertFalse($summary['contains_caller_identifiers']);
        $this->assertSame('2026-07-11', $summary['measurement_valid_since']);
        $this->assertSame(2, $summary['measurement_days_observed']);
        $this->assertFalse($summary['sample_eligible']);
        $this->assertFalse($summary['cutover_eligible']);
        $this->assertSame(
            ['id', 'usage_date', 'mode', 'request_count', 'created_at', 'updated_at'],
            array_keys((array) DB::table('dispatch_usage_daily')->first()),
        );
    }

    public function test_rows_before_measurement_epoch_are_excluded(): void
    {
        Carbon::setTestNow('2026-07-12 12:00:00 UTC');
        config(['app.iicp_dispatch_adoption_valid_from' => '2026-07-12']);
        DB::table('dispatch_usage_daily')->insert([
            'usage_date' => '2026-07-11', 'mode' => 'legacy_dispatch', 'request_count' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(DispatchUsageCounter::class)->record(DispatchUsageCounter::TICKETED_DISPATCH);
        $summary = app(DispatchUsageCounter::class)->summary();

        $this->assertSame(1, $summary['ticketed_requests']);
        $this->assertSame(0, $summary['legacy_route_discovery_requests']);
        $this->assertSame(1.0, $summary['ticketed_share']);
    }

    public function test_future_measurement_epoch_never_reports_observed_days(): void
    {
        Carbon::setTestNow('2026-07-12 12:00:00 UTC');
        config(['app.iicp_dispatch_adoption_valid_from' => '2026-07-13']);

        $summary = app(DispatchUsageCounter::class)->summary();

        $this->assertSame(0, $summary['measurement_days_observed']);
        $this->assertFalse($summary['measurement_window_complete']);
        $this->assertFalse($summary['cutover_eligible']);
    }

    public function test_discover_records_public_and_legacy_modes_without_caller_data(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->getJson("/api/v1/discover?intent={$intent}")->assertOk();
        $this->getJson("/api/v1/discover?intent={$intent}&view=public")->assertOk();

        $this->assertDatabaseHas('dispatch_usage_daily', [
            'mode' => 'legacy_dispatch',
            'request_count' => 1,
        ]);
        $this->assertDatabaseHas('dispatch_usage_daily', [
            'mode' => 'public_view',
            'request_count' => 1,
        ]);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Anonymous aggregate counters for the route-discovery migration (#612). */
class DispatchUsageCounter
{
    public const PUBLIC_VIEW = 'public_view';

    public const LEGACY_DISPATCH = 'legacy_dispatch';

    public const TICKETED_DISPATCH = 'ticketed_dispatch';

    public function record(string $mode): void
    {
        if (! in_array($mode, $this->modes(), true) || ! Schema::hasTable('dispatch_usage_daily')) {
            return;
        }

        $date = now()->utc()->toDateString();

        try {
            DB::table('dispatch_usage_daily')->insertOrIgnore([
                'usage_date' => $date,
                'mode' => $mode,
                'request_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            // A concurrent request may have inserted the unique date/mode row.
        }

        DB::table('dispatch_usage_daily')
            ->where('usage_date', $date)
            ->where('mode', $mode)
            ->increment('request_count', 1, ['updated_at' => now()]);
    }

    /** @return array<string,mixed> */
    public function summary(int $days = 7): array
    {
        $retentionDays = max(1, (int) config('app.iicp_telemetry_retention.dispatch_days', 30));
        $days = max(1, min($days, $retentionDays));
        $counts = array_fill_keys($this->modes(), 0);
        $validFrom = $this->validFrom();
        $windowStart = now()->utc()->subDays($days - 1)->startOfDay();
        if ($validFrom !== null && $validFrom->greaterThan($windowStart)) {
            $windowStart = $validFrom;
        }
        $daily = [];

        if (Schema::hasTable('dispatch_usage_daily')) {
            $rows = DB::table('dispatch_usage_daily')
                ->where('usage_date', '>=', $windowStart->toDateString())
                ->select(['usage_date', 'mode', 'request_count'])
                ->get();
            foreach ($rows as $row) {
                if (array_key_exists($row->mode, $counts)) {
                    $value = (int) $row->request_count;
                    $counts[$row->mode] += $value;
                    $date = (string) $row->usage_date;
                    $daily[$date] ??= array_fill_keys($this->modes(), 0);
                    $daily[$date][$row->mode] += $value;
                }
            }
        }

        $dispatchTotal = $counts[self::TICKETED_DISPATCH] + $counts[self::LEGACY_DISPATCH];
        $minRequests = max(1, (int) config('app.iicp_dispatch_cutover_min_requests', 100));
        $minShare = min(1.0, max(0.0, (float) config('app.iicp_dispatch_cutover_min_share', 0.90)));
        $sustainedDays = max(1, (int) config('app.iicp_dispatch_cutover_sustained_days', 14));
        $sampleEligible = $dispatchTotal >= $minRequests;
        $measurementDays = $validFrom === null
            ? 0
            : (int) max(0, $validFrom->diffInDays(now()->utc()->startOfDay(), false) + 1);
        $windowComplete = $validFrom !== null && $measurementDays >= $sustainedDays;
        $sustained = $windowComplete;
        if ($sustained) {
            for ($offset = 0; $offset < $sustainedDays; $offset++) {
                $date = now()->utc()->startOfDay()->subDays($offset)->toDateString();
                $day = $daily[$date] ?? array_fill_keys($this->modes(), 0);
                $dayTotal = $day[self::TICKETED_DISPATCH] + $day[self::LEGACY_DISPATCH];
                $dayShare = $dayTotal > 0 ? $day[self::TICKETED_DISPATCH] / $dayTotal : 0.0;
                if ($dayTotal === 0 || $dayShare < $minShare) {
                    $sustained = false;
                    break;
                }
            }
        }

        return [
            'basis' => 'anonymous_daily_request_counts',
            'window_days' => $days,
            'ticketed_requests' => $counts[self::TICKETED_DISPATCH],
            'legacy_route_discovery_requests' => $counts[self::LEGACY_DISPATCH],
            'public_view_requests' => $counts[self::PUBLIC_VIEW],
            'ticketed_share' => $dispatchTotal > 0
                ? round($counts[self::TICKETED_DISPATCH] / $dispatchTotal, 4)
                : null,
            'retention_days' => $retentionDays,
            'contains_caller_identifiers' => false,
            'measurement_valid_since' => $validFrom?->toDateString(),
            'measurement_days_observed' => $measurementDays,
            'measurement_window_complete' => $windowComplete,
            'minimum_sample_requests' => $minRequests,
            'sample_eligible' => $sampleEligible,
            'cutover_share_threshold' => $minShare,
            'cutover_sustained_days' => $sustainedDays,
            'cutover_eligible' => $sampleEligible && $sustained,
            'measurement_limits' => 'Anonymous request-path counts can include manual or automated callers; use only after read-only tooling has moved to public view.',
        ];
    }

    private function validFrom(): ?CarbonImmutable
    {
        $raw = config('app.iicp_dispatch_adoption_valid_from');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw, 'UTC')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function modes(): array
    {
        return [self::PUBLIC_VIEW, self::LEGACY_DISPATCH, self::TICKETED_DISPATCH];
    }
}

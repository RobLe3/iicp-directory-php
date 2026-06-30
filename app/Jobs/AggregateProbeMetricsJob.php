<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Jobs;

use App\Models\TelemetryProbe;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AggregateProbeMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const WINDOWS = [
        '1h' => '-1 hour',
        '24h' => '-24 hours',
        '7d' => '-7 days',
    ];

    /**
     * Measurement-defect cutoff (GAPS-020 / #508, 2026-06-11T11:25Z): before this
     * instant every REACH probe constructed a fresh HTTP client + TLS context
     * INSIDE its latency window, inflating recorded wall latencies by
     * ~70-450ms. Those samples measure the probe's own bug, not the directory —
     * excluding them from LATENCY percentiles is sensor recalibration, not
     * history editing (pass/fail conformance counting is untouched; the rows
     * themselves are retained). Inert once all windows roll past the cutoff.
     */
    private const LATENCY_VALID_FROM = '2026-06-11 11:25:00';

    public function handle(): void
    {
        foreach (self::WINDOWS as $label => $cutoff) {
            $this->aggregateWindow($label, $cutoff);
        }
    }

    private function aggregateWindow(string $window, string $cutoff): void
    {
        $since = now()->modify($cutoff);
        // Latency samples additionally start at the measurement-defect cutoff.
        $latencySince = max($since, Carbon::parse(self::LATENCY_VALID_FROM, 'UTC'));

        // discover p95 from conformance latency samples
        $discoverSamples = TelemetryProbe::where('test_id', 'DIR-DISC-01')
            ->where('passed', true)
            ->whereNotNull('latency_ms')
            ->where('probed_at', '>=', $latencySince)
            ->pluck('latency_ms')
            ->sort()
            ->values();

        $this->writeAggregate($window, 'discover_p50_ms', $this->percentile($discoverSamples, 50));
        $this->writeAggregate($window, 'discover_p95_ms', $this->percentile($discoverSamples, 95));

        // #508 latency decomposition: wall latency conflates three very different
        // layers — the directory app (query_ms), the CDN edge (cf HIT), and the
        // CDN→origin pull (cf EXPIRED/MISS, ~10× slower on shared hosting). The
        // 5-min probe cadence aliases against the edge TTL so probes mostly
        // sample the worst-case origin path that real users rarely hit. Aggregate
        // each layer separately so directory_health can score what the directory
        // actually controls.
        $rows = TelemetryProbe::where('test_id', 'DIR-DISC-01')
            ->where('passed', true)
            ->whereNotNull('metadata')
            ->where('probed_at', '>=', $latencySince)
            ->get(['latency_ms', 'metadata']);

        $query = collect();
        $edge = collect();
        $origin = collect();
        foreach ($rows as $row) {
            $meta = is_array($row->metadata) ? $row->metadata : json_decode((string) $row->metadata, true);
            if (! is_array($meta)) {
                continue;
            }
            if (isset($meta['directory_query_ms']) && is_numeric($meta['directory_query_ms'])) {
                $query->push((float) $meta['directory_query_ms']);
            }
            if ($row->latency_ms !== null) {
                if (($meta['cf_cache_status'] ?? null) === 'HIT') {
                    $edge->push((float) $row->latency_ms);
                } elseif (isset($meta['cf_cache_status'])) {
                    $origin->push((float) $row->latency_ms);
                }
            }
        }

        $this->writeAggregate($window, 'discover_query_p50_ms', $this->percentile($query->sort()->values(), 50), $query->count());
        $this->writeAggregate($window, 'discover_edge_p50_ms', $this->percentile($edge->sort()->values(), 50), $edge->count());
        $this->writeAggregate($window, 'discover_origin_p50_ms', $this->percentile($origin->sort()->values(), 50), $origin->count());

        // heartbeat latency — from DIR-HB-02 (invalid-token rejection probe).
        // DIR-HB-02 measures the heartbeat endpoint's full request round-trip
        // through auth middleware, which is representative of endpoint latency.
        // DIR-HB-01 (authenticated heartbeat) requires a node credential and
        // will replace this source once a registered node_token is configured.
        $hbSamples = TelemetryProbe::whereIn('test_id', ['DIR-HB-01', 'DIR-HB-02'])
            ->where('passed', true)
            ->whereNotNull('latency_ms')
            ->where('probed_at', '>=', $latencySince)
            ->pluck('latency_ms')
            ->sort()
            ->values();

        $this->writeAggregate($window, 'heartbeat_p50_ms', $this->percentile($hbSamples, 50));

        // reachability: fraction of REACH-01 probes that passed
        $reachTotal = TelemetryProbe::where('test_id', 'REACH-01')
            ->where('probed_at', '>=', $since)->count();
        $reachPassed = TelemetryProbe::where('test_id', 'REACH-01')
            ->where('passed', true)
            ->where('probed_at', '>=', $since)->count();

        $reachPct = $reachTotal > 0 ? round($reachPassed / $reachTotal * 100, 2) : null;
        $this->writeAggregate($window, 'reachability_pct', $reachPct, $reachTotal);

        // conformance summary
        $confTotal = TelemetryProbe::where('probe_type', 'conformance')
            ->where('probed_at', '>=', $since)->count();
        $confPassed = TelemetryProbe::where('probe_type', 'conformance')
            ->where('passed', true)
            ->where('probed_at', '>=', $since)->count();
        $confFailed = $confTotal - $confPassed;

        $this->writeAggregate($window, 'conformance_passed', $confPassed, $confTotal);
        $this->writeAggregate($window, 'conformance_failed', $confFailed, $confTotal);

        // active probe count (distinct tokens with activity in window)
        $activeProbes = TelemetryProbe::where('probed_at', '>=', $since)
            ->distinct('probe_token_id')
            ->count('probe_token_id');
        $this->writeAggregate($window, 'active_probe_count', $activeProbes);
    }

    private function percentile(Collection $sorted, int $pct): ?float
    {
        if ($sorted->isEmpty()) {
            return null;
        }
        $idx = (int) ceil($pct / 100 * $sorted->count()) - 1;

        return round($sorted->get(max(0, $idx)), 1);
    }

    private function writeAggregate(string $window, string $metric, ?float $value, int $samples = 0): void
    {
        DB::table('iicp_telemetry_aggregates')->insert([
            'window' => $window,
            'metric' => $metric,
            'value' => $value,
            'sample_count' => $samples,
            'computed_at' => now(),
        ]);
    }
}

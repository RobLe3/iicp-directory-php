<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\Node;
use App\Models\ProbeToken;
use App\Models\TelemetryProbe;
use App\Services\NodeHealthService;
use App\Services\NodeScorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function __construct(private NodeHealthService $health) {}

    /** Credit schedule constants — derived from research/credit-rate-calibration/01-rate-calibration-findings.md. */
    private const CREDIT_SCHEDULE = [
        'formula' => 'ceil(output_tokens / tokens_per_credit) × tier_weight × node_multiplier',
        'tokens_per_credit' => 1000,
        'tier_weights' => [
            'sub_1b' => 0.05,
            '7b' => 1.0,
            '13b' => 2.0,
            '30b' => 6.5,
            '70b' => 32.0,
            '100b_plus' => 75.0,
        ],
        'evaluation_grant' => [
            'credits' => 5,
            'interval_seconds' => 21600,
        ],
        'burn_rate_pct' => 2.0,
    ];

    public function index(): JsonResponse
    {
        $stats = Cache::remember('stats.public', 60, function () {
            $probes = $this->probeStats();

            return [
                'server' => $this->serverStats(),
                'probes' => $probes,
                'credit_schedule' => self::CREDIT_SCHEDULE,
                // ADR-044 (#372): mesh_health is the median per-node health over
                // active provider nodes (the mesh's serving set), with the full
                // distribution exposed. Directory-infrastructure signals (discover
                // latency, conformance, REACH reachability) moved to directory_health.
                'mesh_health' => $this->health->meshHealth($this->health->activeProviderNodes()),
                'directory_health' => $this->directoryHealth($probes),
            ];
        });

        return response()->json($stats);
    }

    private function serverStats(): array
    {
        $base = Node::where('available', true)
            ->where('status', 'active')
            ->where('last_seen', '>=', now()->subSeconds(90));

        // #326 + ADR-047 (#411) — active_nodes = what /v1/discover actually returns:
        // dial-back-verified (direct) OR heartbeating with a routable surface (relay).
        // Keeps stats consistent with the discover filter so a live CGNAT/IPv6 fleet
        // isn't reported as active_nodes=0 while it heartbeats. internal_nodes = the rest.
        // The status='active' clause MUST mirror NodeScorer's discover query — without
        // it, stats would count a node that is available=true but status!=active (e.g.
        // mid-dormancy) which discover excludes, making active_nodes overcount.
        $discoverable = function ($w) {
            $w->where('public_reachable', true)
                ->orWhereIn('exposure_mode', NodeScorer::RELAY_REACHABLE_EXPOSURE_MODES);
        };
        $publicActive = (clone $base)->where($discoverable)->count();
        $internalActive = (clone $base)->whereNot($discoverable)->count();

        // #335 — surface stale-active rows (active=true but last_seen >24h ago)
        // so the post-deploy integrity gate can alert when NodeLifecycleCommand
        // hasn't been firing. Healthy steady-state value: 0.
        $staleActiveNodes = Node::where('available', true)
            ->where('last_seen', '<', now()->subHours(24))
            ->count();

        return [
            'version' => config('app.iicp_version', 'v1.5.0'),
            'active_nodes' => $publicActive,
            'internal_nodes' => $internalActive,
            'stale_active_nodes' => $staleActiveNodes,
            'uptime_seconds' => $this->uptimeSeconds(),
        ];
    }

    private function probeStats(): array
    {
        $lastProbe = TelemetryProbe::latest('probed_at')->first();
        $lastProbeAt = $lastProbe?->probed_at?->toIso8601String();

        $activeProbes = ProbeToken::whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subHours(2))
            ->count();

        $regions = ProbeToken::whereNotNull('region')
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subHours(2))
            ->pluck('region')
            ->unique()
            ->values()
            ->all();

        return [
            'active_count' => $activeProbes,
            'regions' => $regions,
            'aggregate_24h' => $this->aggregate('24h'),
            'conformance_24h' => $this->conformanceAggregate('24h'),
            'last_probe_at' => $lastProbeAt,
        ];
    }

    private function aggregate(string $window): array
    {
        $rows = DB::table('iicp_telemetry_aggregates')
            ->where('window', $window)
            ->orderByDesc('computed_at')
            ->get()
            ->unique('metric')
            ->keyBy('metric');

        // D2-READ (W-042/D5prime prep): Task success rate now derived from canonical
        // denormalized nodes.tasks_total / nodes.tasks_failed columns (added by
        // 2026_05_25_300000 + 2026_05_26_100000 migrations). After Phase 2 SQL drop
        // of `reputations` table, this is the sole source.
        $totalTasks = (int) Node::sum('tasks_total');
        $failedTasks = (int) Node::sum('tasks_failed');
        $successRate = $totalTasks > 0
            ? round((($totalTasks - $failedTasks) / $totalTasks) * 100, 1)
            : null;

        return [
            'discover_p50_ms' => $rows->get('discover_p50_ms')?->value,
            'discover_p95_ms' => $rows->get('discover_p95_ms')?->value,
            'heartbeat_p50_ms' => $rows->get('heartbeat_p50_ms')?->value,
            'reachability_pct' => $rows->get('reachability_pct')?->value,
            'task_success_rate_pct' => $successRate,
        ];
    }

    private function conformanceAggregate(string $window): array
    {
        $rows = DB::table('iicp_telemetry_aggregates')
            ->where('window', $window)
            ->whereIn('metric', ['conformance_passed', 'conformance_failed'])
            ->orderByDesc('computed_at')
            ->get()
            ->unique('metric')
            ->keyBy('metric');

        return [
            'passed' => (int) ($rows->get('conformance_passed')?->value ?? 0),
            'failed' => (int) ($rows->get('conformance_failed')?->value ?? 0),
            // #338 — top failing probes for live triage. Aggregates the last
            // 24h of telemetry_probes by test_id, returns the top 5 by
            // fail-count so operators can spot conformance gaps without
            // tailing logs. Empty array when nothing has failed.
            'top_failures' => $this->topFailures(24),
        ];
    }

    /**
     * #338 — return up to 5 probe test_ids with the highest fail-count over
     * the past N hours. Each entry: { test_id, failed, passed, total,
     * fail_rate }. fail_rate is a float 0..1.
     */
    private function topFailures(int $hours): array
    {
        $since = now()->subHours($hours);
        $rows = DB::table('iicp_telemetry_probes')
            ->where('probed_at', '>=', $since)
            ->whereNotNull('test_id')
            ->selectRaw(
                'test_id, '
                .'SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) AS passed, '
                .'SUM(CASE WHEN passed = 0 THEN 1 ELSE 0 END) AS failed, '
                .'COUNT(*) AS total'
            )
            ->groupBy('test_id')
            ->having('failed', '>', 0)
            ->orderByDesc('failed')
            ->limit(5)
            ->get();

        return $rows->map(function ($r) {
            $total = (int) $r->total;
            $failed = (int) $r->failed;

            return [
                'test_id' => $r->test_id,
                'passed' => (int) $r->passed,
                'failed' => $failed,
                'total' => $total,
                'fail_rate' => $total > 0 ? round($failed / $total, 4) : 0.0,
            ];
        })->toArray();
    }

    /**
     * directory_health — ADR-044 (#372). The directory-infrastructure signal
     * that `mesh_health` used to be conflated with: how fast and conformant the
     * directory's own control-plane endpoints are, as measured by REACH probes.
     * This is NOT node health — it is a separate operator/QA signal that lives
     * alongside mesh_health so each number means what it says.
     *
     * Formula: 0.6×discover_latency + 0.4×conformance.
     * `probe_reachability_pct` is the REACH-01 global pass rate (a single-host
     * ping with no node attribution — Phase B (#373) makes reachability per-node).
     */
    private function directoryHealth(array $probes): array
    {
        $agg = $probes['aggregate_24h'];
        $conf = $probes['conformance_24h'];

        $p50 = $agg['discover_p50_ms'] ?? null;
        $reachability = $agg['reachability_pct'] ?? null;
        $passed = $conf['passed'] ?? 0;
        $failed = $conf['failed'] ?? 0;

        // No probe data yet → unavailable rather than a misleading default.
        if ($p50 === null && $passed === 0 && $failed === 0) {
            return [
                'score' => null,
                'label' => 'unavailable',
                'components' => null,
                'probe_reachability_pct' => $reachability,
                'window' => '24h',
            ];
        }

        // Discover latency: p50 ≤ 50ms → 1.0; ≥ 500ms → 0.0.
        $latScore = $p50 !== null
            ? max(0.0, min(1.0, 1.0 - ($p50 - 50.0) / 450.0))
            : 0.5;

        // Conformance: pass fraction over the window.
        $total = $passed + $failed;
        $confScore = $total > 0 ? $passed / $total : 1.0;

        $score = round(0.6 * $latScore + 0.4 * $confScore, 3);

        $label = match (true) {
            $score >= 0.85 => 'healthy',
            $score >= 0.65 => 'degraded',
            $score >= 0.40 => 'impaired',
            default => 'critical',
        };

        return [
            'score' => $score,
            'label' => $label,
            'components' => [
                'discover_latency' => round($latScore, 3),
                'conformance' => round($confScore, 3),
            ],
            'discover_p50_ms' => $p50,
            'probe_reachability_pct' => $reachability,
            'window' => '24h',
        ];
    }

    private function uptimeSeconds(): int
    {
        // Seconds since the first node was ever registered (proxy for service uptime).
        // Uses Eloquent model so created_at is cast to Carbon with correct timezone.
        $oldest = Node::orderBy('created_at')->first();

        return $oldest ? max(0, now()->timestamp - $oldest->created_at->timestamp) : 0;
    }
}

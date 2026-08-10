<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\Node;
use App\Models\ProbeToken;
use App\Models\TelemetryProbe;
use App\Services\DispatchUsageCounter;
use App\Services\FederatedMeshHealthResolver;
use App\Services\MeshResilienceSummary;
use App\Services\NodeHealthService;
use App\Services\NodeScorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public const PUBLIC_STATS_CACHE_KEY = 'stats.public';

    public const PUBLIC_STATS_CACHE_SECONDS = 60;

    public const PUBLIC_STATS_WARM_CACHE_SECONDS = 90;

    public const PUBLIC_STATS_EMPTY_CACHE_SECONDS = 5;

    public function __construct(
        private NodeHealthService $health,
        private FederatedMeshHealthResolver $federatedHealth,
    ) {}

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
        $stats = $this->cachedPublicStats(self::PUBLIC_STATS_CACHE_SECONDS);

        return response()->json($stats);
    }

    /**
     * Return the public stats document with a deliberately short cache TTL for
     * empty public-serving-set snapshots. A momentary DB/cache/probe gap should
     * not make the website show "0 nodes" for a full warm-cache interval when
     * heartbeats resume seconds later (#598).
     */
    public function cachedPublicStats(int $nonEmptyTtlSeconds = self::PUBLIC_STATS_CACHE_SECONDS): array
    {
        $cached = Cache::get(self::PUBLIC_STATS_CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $stats = $this->build();
        Cache::put(self::PUBLIC_STATS_CACHE_KEY, $stats, $this->publicStatsTtl($stats, $nonEmptyTtlSeconds));

        return $stats;
    }

    public function warmPublicStatsCache(): int
    {
        $stats = $this->build();
        $ttl = $this->publicStatsTtl($stats, self::PUBLIC_STATS_WARM_CACHE_SECONDS);
        Cache::put(self::PUBLIC_STATS_CACHE_KEY, $stats, $ttl);

        return $ttl;
    }

    public function publicStatsTtl(array $stats, int $nonEmptyTtlSeconds): int
    {
        $publicRoutable = (int) ($stats['server']['public_routable_nodes'] ?? $stats['server']['active_nodes'] ?? 0);

        return $publicRoutable > 0 ? $nonEmptyTtlSeconds : self::PUBLIC_STATS_EMPTY_CACHE_SECONDS;
    }

    /**
     * Build the public stats document. Called by the 60s response cache above
     * and by the iicp:warm-stats-cache scheduler (#508) so no user request
     * ever pays the ~1.2s aggregate rebuild on cache expiry.
     */
    public function build(): array
    {
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
            // ADR-048 (#374): federation-aware mesh_health — resolves each node by
            // majority-vote across evaluators over the union of replicated HEALTH
            // snapshots, so any replica reports the same fleet aggregate. Present only
            // once HEALTH events have been applied (federation active); null otherwise
            // so the single-directory mesh_health above stays the unconditional figure.
            'mesh_health_federated' => $this->federatedMeshHealthOrNull(),
            'directory_health' => $this->directoryHealth($probes),
            // Public visibility/recovery summary. This is additive and does
            // not change discovery: it prevents short tunnel/relay/cache
            // recovery windows from looking like a permanent empty mesh on
            // public dashboards.
            'resilience' => app(MeshResilienceSummary::class)->build(),
            // DIR-MIG-01 / #531 — adoption telemetry: the sdk_version + sdk_language
            // distribution of active nodes. Read-only; this is the objective signal
            // the capability-migration framework (iicp-dir §6.1) uses to decide when
            // an adoption-gated hard-enforcement stage is safe to start.
            'sdk_adoption' => $this->sdkAdoption(),
            'receipt_profile_adoption' => $this->receiptProfileAdoption(),
            // Anonymous aggregate migration evidence. No caller, route, ticket,
            // endpoint or payload data is stored in these counters.
            'dispatch_discovery_adoption' => app(DispatchUsageCounter::class)->summary(),
        ];
    }

    /**
     * #531 — distribution of `sdk_version` (and `sdk_language`) over active
     * nodes, plus the per-language newest version seen. Advisory provenance
     * (sdk_version is self-reported), but sufficient to gate migration phases.
     */
    private function sdkAdoption(): array
    {
        $active = Node::where('available', true)
            ->where('status', 'active')
            ->where('last_seen', '>=', now()->subSeconds(90))
            ->get(['sdk_language', 'sdk_version', 'sdk_compatibility_version']);

        $total = $active->count();
        // Return plain arrays (not Collections) so the value survives the stats
        // response cache serialize/deserialize cycle — a cached Collection comes
        // back as __PHP_Incomplete_Class and breaks the JSON shape.
        $byLanguage = $active->groupBy(fn ($n) => $n->sdk_language ?: 'unknown')
            ->map(fn ($g) => $g->count())
            ->toArray();
        $byVersion = $active->groupBy(fn ($n) => $n->effectiveSdkCompatibilityVersion() ?: 'unknown')
            ->map(fn ($g) => $g->count())
            ->sortDesc()
            ->toArray();

        return [
            'basis' => 'heartbeating_nodes',
            'total_heartbeating' => $total,
            // Backward-compatible alias retained for adoption dashboards that
            // still read total_active.  It counts heartbeating nodes, not only
            // public-routable nodes.
            'total_active' => $total,
            'by_language' => $byLanguage,
            'by_version' => $byVersion,
        ];
    }

    /** Anonymous, heartbeating-node adoption counts for pre-normative receipts. */
    private function receiptProfileAdoption(): array
    {
        $active = Node::where('available', true)
            ->where('status', 'active')
            ->where('last_seen', '>=', now()->subSeconds(90))
            ->get(['supported_receipt_profiles']);

        $ready = $active->filter(fn (Node $node) => in_array(
            'consumer_cosignature_v1',
            $node->supported_receipt_profiles ?? [],
            true,
        ))->count();

        return [
            'basis' => 'heartbeating_nodes',
            'profile' => 'consumer_cosignature_v1',
            'ready' => $ready,
            'total_heartbeating' => $active->count(),
        ];
    }

    /**
     * ADR-048 federation-aware aggregate — null until at least one HEALTH event has been
     * applied, so a non-federated directory's /stats is unchanged (the single-directory
     * mesh_health remains the authoritative figure while sample == 0).
     */
    private function federatedMeshHealthOrNull(): ?array
    {
        $federated = $this->federatedHealth->federatedMeshHealth();

        return ($federated['sample'] ?? 0) > 0 ? $federated : null;
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
        $totalActive = (clone $base)->count();
        $publicActive = (clone $base)
            // Must mirror NodeScorer::discover(): a confirmed-dead listed
            // endpoint is hidden from normal discover until a probe clears it.
            ->whereNull('endpoint_verified_dead_at')
            ->where($discoverable)
            ->count();
        $internalActive = max(0, $totalActive - $publicActive);
        $keyReady = (clone $base)
            ->whereNotNull('cx_public_key')
            ->count();
        $downlevel = (clone $base)
            ->get(['sdk_version', 'sdk_compatibility_version'])
            ->filter(fn (Node $n) => NodeScorer::sdkStatus($n->effectiveSdkCompatibilityVersion()) !== 'current')
            ->count();

        // #335 — surface stale-active rows (active=true but last_seen >24h ago)
        // so the post-deploy integrity gate can alert when NodeLifecycleCommand
        // hasn't been firing. Healthy steady-state value: 0.
        $staleActiveNodes = Node::where('available', true)
            ->where('last_seen', '<', now()->subHours(24))
            ->count();

        return [
            'version' => config('app.iicp_version', 'v1.5.0'),
            'build_id' => config('app.iicp_build_id'),
            // Backward-compatible alias: active_nodes historically existed on
            // the wire.  Its current meaning is public/discoverable serving
            // nodes; newer clients should use public_routable_nodes for clarity.
            'active_nodes' => $publicActive,
            'public_routable_nodes' => $publicActive,
            'heartbeating_nodes' => $totalActive,
            'limited_reach_nodes' => $internalActive,
            'key_ready_nodes' => $keyReady,
            'downlevel_nodes' => $downlevel,
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
        // Keep historical aggregates for retention/evidence, but never hydrate
        // the full history into PHP. Production accumulated >190k rows for 19
        // metrics, which exhausted the shared-host CLI during cache warming and
        // exposed cache-miss web requests to the same failure. Resolve the newest
        // timestamp in SQL, then MAX(id) only among tied newest rows.
        $latestTimes = DB::table('iicp_telemetry_aggregates')
            ->select('metric')
            ->selectRaw('MAX(computed_at) AS latest_at')
            ->where('window', $window)
            ->groupBy('metric');
        $latestIds = DB::table('iicp_telemetry_aggregates as candidate')
            ->joinSub($latestTimes, 'latest', function ($join): void {
                $join->on('latest.metric', '=', 'candidate.metric')
                    ->on('latest.latest_at', '=', 'candidate.computed_at');
            })
            ->where('candidate.window', $window)
            ->groupBy('candidate.metric')
            ->selectRaw('MAX(candidate.id)');

        $rows = DB::table('iicp_telemetry_aggregates')
            ->whereIn('id', $latestIds)
            ->get()
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
            // #508 decomposition: app processing vs CDN edge vs CDN→origin pull.
            'discover_query_p50_ms' => $rows->get('discover_query_p50_ms')?->value,
            'discover_query_cache_hit_p50_ms' => $rows->get('discover_query_cache_hit_p50_ms')?->value,
            'discover_query_cache_hit_p95_ms' => $rows->get('discover_query_cache_hit_p95_ms')?->value,
            'discover_query_cache_hit_samples' => (int) ($rows->get('discover_query_cache_hit_p50_ms')?->sample_count ?? 0),
            'discover_query_cache_miss_p50_ms' => $rows->get('discover_query_cache_miss_p50_ms')?->value,
            'discover_query_cache_miss_p95_ms' => $rows->get('discover_query_cache_miss_p95_ms')?->value,
            'discover_query_cache_miss_samples' => (int) ($rows->get('discover_query_cache_miss_p50_ms')?->sample_count ?? 0),
            'discover_query_cache_bypass_p50_ms' => $rows->get('discover_query_cache_bypass_p50_ms')?->value,
            'discover_origin_cache_hit_p50_ms' => $rows->get('discover_origin_cache_hit_p50_ms')?->value,
            'discover_origin_cache_hit_p95_ms' => $rows->get('discover_origin_cache_hit_p95_ms')?->value,
            'discover_origin_cache_miss_p50_ms' => $rows->get('discover_origin_cache_miss_p50_ms')?->value,
            'discover_origin_cache_miss_p95_ms' => $rows->get('discover_origin_cache_miss_p95_ms')?->value,
            'discover_edge_p50_ms' => $rows->get('discover_edge_p50_ms')?->value,
            'discover_origin_p50_ms' => $rows->get('discover_origin_p50_ms')?->value,
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
        // #508: score the latency the directory CONTROLS — its own processing
        // time (query_ms). The wall p50 conflates CDN transport: the 5-min probe
        // cadence aliases against the edge TTL, so probes mostly sample the
        // CDN→origin worst case that cached real-user traffic rarely hits. The
        // wall/edge/origin figures stay exposed below; latency_basis says which
        // one the score used (query when available, wall fallback).
        $queryP50 = $agg['discover_query_p50_ms'] ?? null;
        $scoreP50 = $queryP50 ?? $p50;
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
        $latScore = $scoreP50 !== null
            ? max(0.0, min(1.0, 1.0 - ($scoreP50 - 50.0) / 450.0))
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
            'discover_query_p50_ms' => $queryP50,
            'discover_edge_p50_ms' => $agg['discover_edge_p50_ms'] ?? null,
            'discover_origin_p50_ms' => $agg['discover_origin_p50_ms'] ?? null,
            'latency_basis' => $queryP50 !== null ? 'query' : 'wall',
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

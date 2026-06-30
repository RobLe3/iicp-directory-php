<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Jobs\AggregateProbeMetricsJob;
use App\Models\Node;
use App\Models\ProxyTelemetry;
use App\Models\Reputation;
use App\Models\TelemetryProbe;
use App\Services\NodeEventLogger;
use App\Services\OtelTracer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Dual telemetry ingestion — REACH conformance probes + proxy-observed metrics (spec §3.5).
 *
 * Two pipelines share this controller:
 *   1. store() — receives structured probe results from the REACH daemon (POST /v1/telemetry/probes).
 *      Authenticated via probe_token. Bulk-inserts into telemetry_probes; stochastically
 *      dispatches AggregateProbeMetricsJob.
 *   2. proxyReport() — receives per-task latency + outcome from proxy nodes
 *      (POST /v1/telemetry/proxy-report, spec §3.5, issue #112).
 *      Authenticated via node_token (proxy_token). Feeds into the Reputation EMA via
 *      proxy-observed latency, guarded by a Sybil quorum gate.
 *
 * WHY stochastic dispatch of AggregateProbeMetricsJob (random_int 1-in-10): probe
 * batches arrive in bursts (REACH daemon sends every 60s). A deterministic dispatch-on-every-
 * batch causes thundering herd on the job queue. A 1-in-10 chance means the job fires
 * roughly every ~10 batches; the scheduled fallback (every 5 minutes) handles any gaps.
 *
 * WHY 60s time_bucket dedup in proxyReport: one proxy can observe the same provider node
 * multiple times in a minute. Storing every observation would bloat the table and bias the
 * EMA. One record per (proxy, node, minute) preserves signal density without redundancy.
 * The time bucket approach avoids expensive locking: two concurrent reports in the same
 * bucket produce a harmless duplicate check rather than a transaction contention.
 *
 * WHY Sybil quorum gate (≥ 3 distinct proxies within 7 days): a single rogue proxy could
 * inflate or deflate a node's observed_latency_ms. Requiring independent observations from
 * at least 3 distinct proxies before the EMA applies prevents single-source gaming while
 * keeping new nodes at a neutral probation score (0.5) until they earn quorum coverage.
 *
 * WHY OUTLIER_WEIGHT (spec §T4.3, #187): a mis-calibrated or compromised proxy could bias
 * the EMA by reporting extreme latency values. After ≥ 10 observations from the same proxy,
 * if its report exceeds 3× the current median across all recent (24h) reports we reduce its
 * EMA contribution to 10% (outlier_weight = 0.1). Honest-but-noisy proxies (±40% noise)
 * never trip the threshold; only sustained outlier reporters are suppressed.
 *
 * Spec: spec/iicp-semantics.md §3.4 (trust-telemetry layer), §3.5 (proxy report).
 * ADR: ADR-014 (observability), ADR-015 (reputation). Issues: #112 (trust-tel), #114 (Sybil), #187 (T4.3).
 */
class TelemetryController extends Controller
{
    public function __construct(private NodeEventLogger $eventLogger) {}

    private const ALLOWED_LEVELS = ['MUST', 'SHOULD', 'INFO'];

    // RT-03b (#382): Sybil quorum independence requirements.
    // Proxies must be at least this old to count toward quorum.
    public const QUORUM_MIN_AGE_DAYS = 3;

    // Proxies must have at least this reputation to count toward quorum.
    // Starting score is 0.5; 0.55 requires minimal positive task history.
    public const QUORUM_MIN_REPUTATION = 0.55;

    private const ALLOWED_TYPES = ['reachability', 'conformance'];

    public function store(Request $request): Response
    {
        $token = $request->attributes->get('probe_token');

        $data = $request->validate([
            'run_id' => ['required', 'string', 'max:36'],
            'probed_at' => ['required', 'date'],
            'results' => ['required', 'array', 'min:1', 'max:100'],
            'results.*.probe_id' => ['required', 'string', 'max:64'],
            'results.*.probe_type' => ['required', 'string', 'in:'.implode(',', self::ALLOWED_TYPES)],
            'results.*.test_id' => ['required', 'string', 'max:32'],
            'results.*.level' => ['required', 'string', 'in:'.implode(',', self::ALLOWED_LEVELS)],
            'results.*.passed' => ['required', 'boolean'],
            'results.*.latency_ms' => ['nullable', 'numeric', 'min:0', 'max:60000'],
            'results.*.detail' => ['nullable', 'string', 'max:512'],
            'results.*.metadata' => ['nullable', 'array'],
            // #373 — per-node attribution: a reachability/conformance probe targeting a
            // specific node carries its id; directory-infra probes omit it (node_id=null).
            'results.*.node_id' => ['sometimes', 'nullable', 'string', 'size:36'],
        ]);

        $probedAt = Carbon::parse($data['probed_at'])->utc()->format('Y-m-d H:i:s');

        $rows = collect($data['results'])->map(fn ($r) => [
            'probe_token_id' => $token->id,
            'run_id' => $data['run_id'],
            'probed_at' => $probedAt,
            'probe_id' => $r['probe_id'],
            'probe_type' => $r['probe_type'],
            'test_id' => $r['test_id'],
            'level' => $r['level'],
            'passed' => $r['passed'],
            'latency_ms' => $r['latency_ms'] ?? null,
            'detail' => $r['detail'] ?? null,
            'metadata' => isset($r['metadata']) ? json_encode($r['metadata']) : null,
            // #373 — attribute the probe to a node when the result names one (else null).
            'node_id' => $r['node_id'] ?? null,
        ]);

        TelemetryProbe::insert($rows->all());

        AggregateProbeMetricsJob::dispatchIf(
            random_int(1, 10) === 1,  // stochastic dispatch — job also runs on schedule
        );

        return response('', 202);
    }

    // Proxy-observed metrics ingestion — spec §3.5 (#112, trust-telemetry layer)
    public function proxyReport(Request $request): JsonResponse
    {
        $proxy = $request->input('_authenticated_node');

        if (! $proxy instanceof Node) {
            return response()->json([
                'error' => ['code' => 'IICP-E032', 'message' => 'Invalid or missing proxy_token'],
            ], 401);
        }

        $span = OtelTracer::startSpan($request, 'iicp.directory.telemetry.proxy_report');

        $data = $request->validate([
            'node_id' => ['required', 'string', 'size:36'],
            // RT-03 (#377): proxy_node_id is now bound to the authenticated node's ID.
            // The request field is still accepted for API compatibility but ignored;
            // the authenticated identity is the source of truth.
            'proxy_node_id' => ['sometimes', 'string'],
            'latency_ms_observed' => ['required', 'numeric', 'min:0', 'max:300000'],
            'tokens_observed' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'string', 'in:success,failure,timeout'],
            'qos_advertised' => ['nullable', 'string', 'in:realtime,interactive,batch,best-effort'],
            'qos_met' => ['required', 'boolean'],
        ]);

        // Deduplication: one report per (node_id, proxy_node_id, time_bucket=60s).
        // now()->timestamp (not time()) so tests can freeze the clock — two reports
        // straddling a minute edge land in different buckets otherwise (flaky dedup).
        $timeBucket = (int) (floor(now()->timestamp / 60) * 60);

        $existing = ProxyTelemetry::where('node_id', $data['node_id'])
            ->where('proxy_node_id', $proxy->id)
            ->where('time_bucket', $timeBucket)
            ->exists();

        if ($existing) {
            $span->setAttribute('iicp.duplicate', true)->end();

            return response()->json(['accepted' => false, 'reason' => 'duplicate'], 200);
        }

        ProxyTelemetry::create([
            'node_id' => $data['node_id'],
            'proxy_node_id' => $proxy->id,
            'time_bucket' => $timeBucket,
            'latency_ms_observed' => $data['latency_ms_observed'],
            'tokens_observed' => $data['tokens_observed'],
            'status' => $data['status'],
            'qos_advertised' => $data['qos_advertised'] ?? null,
            'qos_met' => $data['qos_met'],
        ]);

        // Sybil quorum gate (#114): EMA only applies when ≥3 distinct proxies have reported
        // within the last 7 days. RT-03b (#382): proxies must be ≥QUORUM_MIN_AGE_DAYS old
        // AND have reputation ≥QUORUM_MIN_REPUTATION to prevent colluding fresh nodes.
        $quorumWindow = now()->subDays(7);
        $minAgeThreshold = now()->subDays(self::QUORUM_MIN_AGE_DAYS);
        $distinctProxies = ProxyTelemetry::join('nodes', 'proxy_telemetry.proxy_node_id', '=', 'nodes.id')
            ->where('proxy_telemetry.node_id', $data['node_id'])
            ->where('proxy_telemetry.created_at', '>=', $quorumWindow)
            ->where('nodes.created_at', '<=', $minAgeThreshold)
            ->where('nodes.reputation_score', '>=', self::QUORUM_MIN_REPUTATION)
            ->distinct()
            ->count('proxy_telemetry.proxy_node_id');

        $rep = Reputation::firstOrCreate(
            ['node_id' => $data['node_id']],
            ['score' => 0.5, 'tasks_total' => 0, 'tasks_failed' => 0, 'completed_tasks_count' => 0, 'avg_latency_ms' => 0.0],
        );

        // T4.3 OUTLIER_WEIGHT: reduce EMA weight for outlier reporters (#187, spec §T4.3)
        $outlierWindow = now()->subHours(24);
        $recentReports = ProxyTelemetry::where('node_id', $data['node_id'])
            ->where('created_at', '>=', $outlierWindow)
            ->pluck('latency_ms_observed')
            ->toArray();

        $proxyObsCount = ProxyTelemetry::where('node_id', $data['node_id'])
            ->where('proxy_node_id', $proxy->id)
            ->count();

        $outlierWeight = 1.0;
        if ($proxyObsCount >= 10 && count($recentReports) >= 2) {
            sort($recentReports);
            $n = count($recentReports);
            $median = ($n % 2 === 0)
                ? ($recentReports[$n / 2 - 1] + $recentReports[$n / 2]) / 2.0
                : $recentReports[intdiv($n, 2)];
            if ($median > 0 && $data['latency_ms_observed'] > 3.0 * $median) {
                $outlierWeight = 0.1;
            }
        }

        if ($distinctProxies >= 3) {
            $effectiveAlpha = 0.1 * $outlierWeight;
            $newObserved = ($rep->observed_latency_ms === null)
                ? $data['latency_ms_observed']
                : ($effectiveAlpha * $data['latency_ms_observed'] + (1 - $effectiveAlpha) * $rep->observed_latency_ms);

            $rep->update(['observed_latency_ms' => round($newObserved, 2)]);
        } else {
            $newObserved = $rep->observed_latency_ms;
        }

        // W-042 / db-D4prime: SCORE_UPDATE event emission removed.
        // Per S.13 v0.3.0 (db-D3prime): replicas derive observed_latency_ms
        // from the canonical row via snapshot (snapshot+event-tail model);
        // the SCORE_UPDATE event was state, not state-change, and would
        // accumulate at proxy_telemetry rate (~150k/month at production).

        $span->setAttribute('iicp.node_id', $data['node_id'])
            ->setAttribute('iicp.proxy_node_id', $proxy->id)
            ->setAttribute('iicp.latency_ms_observed', (float) $data['latency_ms_observed'])
            ->setAttribute('iicp.outlier_weight', (float) $outlierWeight)
            ->setAttribute('iicp.quorum_met', $distinctProxies >= 3)
            ->setAttribute('iicp.distinct_proxies', (int) $distinctProxies)
            ->setAttribute('iicp.ema_updated', $distinctProxies >= 3)
            ->end();

        return response()->json(['accepted' => true], 202);
    }
}

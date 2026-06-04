<?php

// SPDX-License-Identifier: Apache-2.0

/**
 * GET /api/v1/discover — return a scored, filtered, sorted list of nodes.
 *
 * The discovery endpoint is the proxy's primary entry point: it answers
 * "which nodes can serve intent X under QoS Y in region Z?" and returns a
 * deterministic ranking. The directory NEVER sees task payloads — only the
 * intent URN and optional QoS / region / model hints (ADR-001 hard rule).
 *
 * Implements:
 *   - ADR-008 — Phase 1 scoring formula (latency × load × reputation × match)
 *   - ADR-012 — Phase 5 CIP weights kick in per-request when ?model= is set
 *   - ADR-017 — public registry contract (no auth required for discovery)
 *
 * Scoring itself lives in {@see NodeScorer}; this controller is intentionally
 * thin so the scoring policy can evolve without touching the HTTP surface.
 */

namespace App\Http\Controllers;

use App\Services\NodeScorer;
use App\Services\OtelTracer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DiscoverController extends Controller
{
    /** Hard cap on returned nodes — defends the proxy from runaway result sets. */
    private const MAX_LIMIT = 50;

    /** Default page size — chosen to comfortably feed FallbackChain (5 attempts). */
    private const DEFAULT_LIMIT = 10;

    public function __construct(private NodeScorer $scorer) {}

    /**
     * Compute a discover() result and stamp it with a query-time signal.
     *
     * The `query_ms` field is exposed so REACH probes can attribute latency to
     * the directory vs the network. It is wall-clock from start of scoring to
     * JSON encode; the upper bound is enforced by middleware timeouts.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // #413 follow-up — query-string booleans arrive as the strings "true"/"false",
        // which Laravel's `boolean` rule rejects (it accepts only 1/0/true/false-typed).
        // Operators naturally pass `?include_internal=true` (and `?cip_capable=true`), which
        // 422'd and broke the documented escape hatch for debugging demoted/internal nodes.
        // Normalize via filter_var-backed Request::boolean() so both string and numeric forms work.
        foreach (['cip_capable', 'include_internal'] as $boolParam) {
            if ($request->has($boolParam)) {
                $request->merge([$boolParam => $request->boolean($boolParam)]);
            }
        }

        $validated = $request->validate([
            'intent' => ['required', 'string', 'max:255'],
            'qos' => ['sometimes', 'string', 'in:realtime,interactive,batch,best-effort'],
            'region' => ['sometimes', 'string', 'max:64'],
            'model' => ['sometimes', 'nullable', 'string', 'max:128'],
            'min_reputation' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            // ADR-019 pricing filters
            'max_multiplier' => ['sometimes', 'numeric', 'min:0'],
            'min_quality_score' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            // CIP-D1: filter to CIP-Provider nodes only (allow_remote_inference=true, S.12 §5.2)
            'cip_capable' => ['sometimes', 'boolean'],
            // #326 — default discover returns ONLY public_reachable=true nodes (the
            // honest 'mesh is empty if no public nodes' state). Operators / dev tools
            // can opt into seeing internal-only nodes with include_internal=true.
            // Default false to match the public-quickstart contract.
            'include_internal' => ['sometimes', 'boolean'],
            // #408/ADR-046 — filter to nodes accepting this input modality (e.g. image → vision-capable).
            'modality' => ['sometimes', 'string', 'in:text,image,audio,video'],
        ]);

        $span = OtelTracer::startSpan($request, 'iicp.directory.discover');
        $start = microtime(true);

        // Cache discover results for 120s — 4× heartbeat cadence; acceptable staleness for
        // node discovery since stale nodes are pruned by heartbeat timeouts anyway.
        // Bumped 60s→120s in #324 v1.9.22 (iter-1405) — pairs with CF s-maxage 300s below.
        // Probe-latency simulation (reports/probe-latency-long-term-2026-05-26.md A3 model)
        // predicts ~85% CF hit rate at this combination → p50 ≈ 43ms, p95 ≈ 282ms.
        $includeInternal = (bool) ($validated['include_internal'] ?? false);
        $cacheKey = 'discover:v1:'.md5(json_encode($validated));
        $nodes = Cache::remember($cacheKey, 120, function () use ($validated, $includeInternal) {
            return $this->scorer->discover(
                intent: $validated['intent'],
                qos: $validated['qos'] ?? null,
                region: $validated['region'] ?? null,
                model: $validated['model'] ?? null,
                minReputation: isset($validated['min_reputation']) ? (float) $validated['min_reputation'] : 0.0,
                limit: $validated['limit'] ?? self::DEFAULT_LIMIT,
                maxMultiplier: isset($validated['max_multiplier']) ? (float) $validated['max_multiplier'] : null,
                minQualityScore: isset($validated['min_quality_score']) ? (float) $validated['min_quality_score'] : null,
                cipCapable: isset($validated['cip_capable']) ? (bool) $validated['cip_capable'] : null,
                includeInternal: $includeInternal,
                modality: $validated['modality'] ?? null,
            );
        });

        $queryMs = round((microtime(true) - $start) * 1000);
        $span->setAttribute('iicp.intent', $validated['intent'])
            ->setAttribute('iicp.discover.count', count($nodes))
            ->setAttribute('iicp.discover.query_ms', $queryMs)
            ->setAttribute('iicp.discover.cip_capable_filter', isset($validated['cip_capable']) ? (bool) $validated['cip_capable'] : false);
        $span->end();

        // Cache-Control: s-maxage=300 tells Cloudflare to cache discover for 300s.
        // stale-while-revalidate=120 extends effective edge cache to 420s total.
        // Bumped from s-maxage=120 in #324 v1.9.22 (iter-1405) — paired with the
        // 60→120s Laravel cache bump above. Discover is public+unauthenticated; CDN
        // caching is safe. Tolerable staleness: heartbeats expire at 90s so a stale
        // discover entry includes nodes whose heartbeat may have lapsed; proxy's
        // 5s per-attempt timeout handles fall-through to next candidate naturally.
        return response()->json([
            'nodes' => $nodes,
            'count' => count($nodes),
            'query_ms' => $queryMs,
        ])->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=120')
            ->header('Vary', 'Accept-Encoding');
    }
}

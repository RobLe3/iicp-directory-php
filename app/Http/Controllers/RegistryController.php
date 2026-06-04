<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\Node;
use App\Services\NodeHealthService;
use App\Services\NodeScorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Public registry API — ADR-017 §1.
 *
 * All endpoints are unauthenticated. No endpoint URLs, node tokens, or per-node
 * private data are returned. Rate-limited at 60 req/min/IP by the router.
 */
class RegistryController extends Controller
{
    private const ACTIVE_WINDOW_S = 90;

    private const CACHE_TTL_S = 60;

    /** GET /v1/registry/stats — network-wide aggregate statistics (REG-03). */
    public function stats(): JsonResponse
    {
        $data = Cache::remember('registry.stats', self::CACHE_TTL_S, function () {
            return $this->buildStats();
        });

        return response()->json($data);
    }

    /** GET /v1/registry/intents — intent URNs with live node count. */
    public function intents(): JsonResponse
    {
        $data = Cache::remember('registry.intents', self::CACHE_TTL_S, function () {
            return $this->buildIntents();
        });

        return response()->json($data);
    }

    /** GET /v1/registry/nodes — paginated public node list (no private fields). */
    public function nodes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'intent' => ['sometimes', 'string'],
            'region' => ['sometimes', 'string', 'max:64'],
            'cip_enabled' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = $validated['limit'] ?? 20;
        $page = $validated['page'] ?? 1;
        $cutoff = now()->subSeconds(self::ACTIVE_WINDOW_S);

        $query = Node::where('last_seen', '>=', $cutoff)
            ->where('available', true)
            ->with(['capabilities', 'reputation']);

        if (! empty($validated['region'])) {
            $query->where('region', $validated['region']);
        }

        if (! empty($validated['intent'])) {
            $query->whereHas(
                'capabilities',
                fn ($q) => $q->where('intent', $validated['intent'])
            );
        }

        if (isset($validated['cip_enabled'])) {
            $query->where('allow_remote_inference', (bool) $validated['cip_enabled']);
        }

        $total = $query->count();
        $items = $query->latest('last_seen')->skip(($page - 1) * $limit)->take($limit)->get();

        $nodes = $items->map(function (Node $n) {
            // UUID IDs: show 8-char hex prefix (anonymized).
            // Custom alphanumeric names: show full name (already operator-chosen).
            $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $n->id);
            $entry = [
                'node_id_prefix' => $isUuid ? substr($n->id, 0, 8) : $n->id,
                'region' => $n->region,
                'reputation_score' => round($n->reputation?->score ?? 0.5, 3),
                // Authoritative tier from NodeScorer (same source the discover
                // endpoint uses) so the list view renders the exact same tier as
                // the detail view — no client-side recomputation drift.
                'reputation_tier' => NodeScorer::reputationTier($n),
                'probation' => ($n->reputation?->completed_tasks_count ?? 0) < 100,
                'intents' => $n->capabilities->pluck('intent')->unique()->values()->all(),
                'models' => $n->capabilities->flatMap(fn ($c) => $c->models ?? [])->unique()->values()->all(),
                'quantization' => $n->capabilities->pluck('quantization')->filter()->unique()->values()->all(),
                'inference_engine' => $n->capabilities->pluck('inference_engine')->filter()->unique()->values()->all(),
                'backend' => $n->backend,
                'last_seen' => $n->last_seen?->toIso8601String(),
                'credit_cost_multiplier' => $n->credit_cost_multiplier ?? 1.0,
                'pricing_model' => $n->pricing_model ?? 'per_token',
                'attested' => (bool) ($n->attested ?? false),
                'cip_enabled' => (bool) ($n->allow_remote_inference ?? false),
                'public_listing' => (bool) ($n->public_listing ?? false),
            ];
            // ADR-017 REG-01: operator_url only exposed when operator has opted into public listing
            if ($n->public_listing) {
                $entry['operator_url'] = $n->operator_url;
            }

            return $entry;
        });

        return response()->json([
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'nodes' => $nodes,
        ]);
    }

    /**
     * GET /v1/registry/nodes/{prefix} — public node profile (REG-01: no private fields).
     * {prefix} is the node_id_prefix from GET /v1/registry/nodes (8 hex chars for UUID
     * node IDs, or the full custom name for operator-supplied node IDs).
     */
    public function show(string $prefix): JsonResponse
    {
        // Accept both UUID 8-hex prefix and custom alphanumeric node names (≤36 chars).
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,35}$/', $prefix)) {
            return response()->json(['error' => 'REGISTRY-INVALID-PREFIX', 'message' => 'Prefix must be alphanumeric (max 36 chars).'], 422);
        }

        $node = Node::with(['capabilities', 'reputation'])
            ->where(function ($q) use ($prefix) {
                // Exact match (custom names) or prefix match (UUID IDs)
                $q->where('id', $prefix)->orWhere('id', 'LIKE', $prefix.'%');
            })
            ->where('available', true)
            ->latest('last_seen')
            ->first();

        if (! $node) {
            return response()->json(['error' => 'REGISTRY-NODE-NOT-FOUND', 'message' => 'No active node found for this prefix.'], 404);
        }

        $rep = $node->reputation;
        $cutoff = now()->subSeconds(self::ACTIVE_WINDOW_S);

        $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $node->id);

        return response()->json([
            'node_id_prefix' => $isUuid ? substr($node->id, 0, 8) : $node->id,
            'region' => $node->region,
            'active' => $node->last_seen?->gte($cutoff) ?? false,
            'last_seen' => $node->last_seen?->toIso8601String(),
            'reputation_score' => round($rep?->score ?? 0.5, 3),
            'reputation_tier' => NodeScorer::reputationTier($node),
            // ADR-044 (#372) — composed per-node health vector + 8-category
            // exposure classification, surfaced on the public registry profile
            // (privacy-preserving: aggregate signals only, no endpoint/secrets).
            'health' => (new NodeHealthService)->forNode($node),
            // #397 — transport protocols the node speaks (http/https/iicp-native),
            // derived from endpoint schemes. Privacy-preserving: protocol tokens only.
            'transport' => NodeScorer::transportMethods($node->endpoint, $node->transport_endpoint),
            'exposure_mode' => $node->exposure_mode,
            'observed_latency_ms' => $rep?->observed_latency_ms,
            'probation' => ($rep?->completed_tasks_count ?? 0) < 100,
            'completed_tasks' => $rep?->completed_tasks_count ?? 0,
            'intents' => $node->capabilities->pluck('intent')->unique()->values()->all(),
            'models' => $node->capabilities->flatMap(fn ($c) => $c->models ?? [])->unique()->values()->all(),
            'quantization' => $node->capabilities->pluck('quantization')->filter()->unique()->values()->all(),
            'inference_engine' => $node->capabilities->pluck('inference_engine')->filter()->unique()->values()->all(),
            'backend' => $node->backend,
            'credit_cost_multiplier' => $node->credit_cost_multiplier ?? 1.0,
            'pricing_model' => $node->pricing_model ?? 'per_token',
            'attested' => (bool) ($node->attested ?? false),
            'cip_enabled' => (bool) ($node->allow_remote_inference ?? false),
            'pricing_effective_from' => $node->pricing_effective_from?->toIso8601String(),
            'pricing_effective_until' => $node->pricing_effective_until?->toIso8601String(),
            'sdk_language' => $node->sdk_language,
            'sdk_version' => $node->sdk_version,
            'uptime_history' => $this->buildUptimeHistory($node),
            'latency_percentiles' => $this->buildLatencyPercentiles($node),
            'public_listing' => (bool) ($node->public_listing ?? false),
            // ADR-017 REG-01: operator_url only when node has opted into public listing
            ...($node->public_listing ? ['operator_url' => $node->operator_url] : []),
        ]);
    }

    /**
     * Build 30-day rolling uptime history from node_address_history heartbeat records.
     * Each entry: {date: Y-m-d, uptime_pct: float 0–100}.
     * Heartbeat interval is 30s → 2880 expected per full day. Today uses elapsed seconds.
     */
    private function buildUptimeHistory(Node $node): array
    {
        $registeredAt = $node->created_at ?? now()->subDays(30);
        $daysAvailable = (int) min(30, ceil($registeredAt->diffInDays(now(), true)) + 1);
        $daysAvailable = max(1, $daysAvailable);

        $since = now()->subDays($daysAvailable - 1)->startOfDay();

        $byDay = DB::table('node_address_history')
            ->where('node_id', $node->id)
            ->where('request_type', 'heartbeat')
            ->where('observed_at', '>=', $since)
            ->selectRaw('DATE(observed_at) as day, COUNT(*) as cnt')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $history = [];
        for ($i = $daysAvailable - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $cnt = (int) ($byDay[$date]->cnt ?? 0);
            // For today use elapsed time, full days expect 2880 beats (every 30s)
            $expected = ($i === 0)
                ? max(1, (int) (now()->diffInSeconds(now()->copy()->startOfDay()) / 30))
                : 2880;
            $pct = $cnt > 0 ? min(100.0, round($cnt / $expected * 100, 1)) : 0.0;
            $history[] = ['date' => $date, 'uptime_pct' => $pct];
        }

        return $history;
    }

    /**
     * Build 7-day rolling latency percentiles from proxy_telemetry records.
     * Returns [] if no telemetry exists for this node.
     */
    private function buildLatencyPercentiles(Node $node): array
    {
        $since = now()->subDays(7);

        $latencies = DB::table('proxy_telemetry')
            ->where('node_id', $node->id)
            ->where('created_at', '>=', $since)
            ->pluck('latency_ms_observed')
            ->sort()
            ->values()
            ->all();

        if (empty($latencies)) {
            return [];
        }

        $count = count($latencies);
        $percentile = function (array $sorted, float $p) use ($count): float {
            $idx = (int) floor($p / 100 * ($count - 1));

            return round($sorted[$idx], 1);
        };

        return [
            'p50_ms' => $percentile($latencies, 50),
            'p95_ms' => $percentile($latencies, 95),
            'p99_ms' => $percentile($latencies, 99),
            'sample_count' => $count,
            'window_days' => 7,
        ];
    }

    private function buildStats(): array
    {
        $cutoff = now()->subSeconds(self::ACTIVE_WINDOW_S);

        $totalNodes = Node::count();
        $activeNodes = Node::where('available', true)->where('last_seen', '>=', $cutoff)->count();

        // Regional distribution
        $regions = Node::where('available', true)
            ->where('last_seen', '>=', $cutoff)
            ->selectRaw('region, COUNT(*) as cnt')
            ->groupBy('region')
            ->orderByDesc('cnt')
            ->pluck('cnt', 'region')
            ->all();

        // Intent coverage — count distinct active nodes per intent URN
        $intentCoverage = DB::table('capabilities')
            ->join('nodes', 'nodes.id', '=', 'capabilities.node_id')
            ->where('nodes.available', true)
            ->where('nodes.last_seen', '>=', $cutoff)
            ->selectRaw('capabilities.intent, COUNT(DISTINCT nodes.id) as cnt')
            ->groupBy('capabilities.intent')
            ->orderByDesc('cnt')
            ->pluck('cnt', 'intent')
            ->all();

        return [
            'total_nodes' => $totalNodes,
            'active_nodes' => $activeNodes,
            'regions' => array_keys($regions),
            'region_breakdown' => $regions,
            'intent_coverage' => $intentCoverage,
            'intents_supported' => array_keys($intentCoverage),
        ];
    }

    private function buildIntents(): array
    {
        $cutoff = now()->subSeconds(self::ACTIVE_WINDOW_S);

        $rows = DB::table('capabilities')
            ->join('nodes', 'nodes.id', '=', 'capabilities.node_id')
            ->where('nodes.available', true)
            ->where('nodes.last_seen', '>=', $cutoff)
            ->selectRaw('capabilities.intent, COUNT(DISTINCT nodes.id) as node_count')
            ->groupBy('capabilities.intent')
            ->orderByDesc('node_count')
            ->get();

        return [
            'intents' => $rows->map(fn ($r) => [
                'urn' => $r->intent,
                'node_count' => (int) $r->node_count,
            ])->all(),
        ];
    }
}

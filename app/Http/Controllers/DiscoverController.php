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
 * Default discovery is route-dispatch data: clients need node_id + endpoint
 * to submit directly to the selected provider. Presentation surfaces that do
 * not need to dispatch can request `view=public` to receive the same scored
 * candidates with full node IDs, endpoint URLs and transport endpoint details
 * removed (#611).
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

use App\Models\Node;
use App\Models\Operator;
use App\Services\DispatchUsageCounter;
use App\Services\IntentPolicyGuard;
use App\Services\NodeScorer;
use App\Services\OtelTracer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class DiscoverController extends Controller
{
    /** Hard cap on returned nodes — defends the proxy from runaway result sets. */
    private const MAX_LIMIT = 50;

    /** Default page size — chosen to comfortably feed FallbackChain (5 attempts). */
    private const DEFAULT_LIMIT = 10;

    /**
     * Discovery contains live serving URLs. For Quick Tunnel / browser-reachable
     * nodes those URLs may rotate whenever a client restarts or rebuilds a
     * tunnel. Longer CDN staleness looked fast on paper but made the browser
     * mesh believe no keyed relay/browser node existed after a healthy tunnel
     * rotation. Keep a small origin cache only to absorb bursts; do not let the
     * edge hold node availability for minutes.
     */
    private const ORIGIN_CACHE_SECONDS = 5;

    private const EDGE_MAX_AGE_SECONDS = 10;

    private const EDGE_STALE_REVALIDATE_SECONDS = 5;

    public function __construct(
        private NodeScorer $scorer,
        private IntentPolicyGuard $intentPolicy,
        private DispatchUsageCounter $usage,
    ) {}

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

        // #528 — query-string booleans arrive as the strings "true"/"false";
        // Laravel's `boolean` rule rejects those, so normalize before validating
        // (the browser relay auto-discovery sends `relay_capable=true`).
        if ($request->has('relay_capable')) {
            $request->merge(['relay_capable' => filter_var($request->input('relay_capable'), FILTER_VALIDATE_BOOLEAN)]);
        }

        // IICP-DIR privacy invariant: discovery is control-plane only. Task
        // payloads/prompts MUST go directly to the selected node, never to the
        // directory. Reject common payload-like fields instead of silently
        // accepting accidental prompt leakage from a client integration.
        $payloadLikeFields = ['prompt', 'messages', 'payload', 'input', 'chat', 'content', 'response'];
        foreach ($payloadLikeFields as $field) {
            if ($request->has($field)) {
                throw ValidationException::withMessages([
                    $field => ['Discovery is control-plane only; send task payloads directly to the selected node.'],
                ]);
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
            // #528 — filter to relay-capable nodes (browser/CGNAT relay auto-discovery)
            'relay_capable' => ['sometimes', 'boolean'],
            // #326 — default discover returns ONLY public_reachable=true nodes (the
            // honest 'mesh is empty if no public nodes' state). Operators / dev tools
            // can opt into seeing internal-only nodes with include_internal=true.
            // Default false to match the public-quickstart contract.
            'include_internal' => ['sometimes', 'boolean'],
            // #408/ADR-046 — filter to nodes accepting this input modality (e.g. image → vision-capable).
            'modality' => ['sometimes', 'string', 'in:text,image,audio,video'],
            // #548 — additive shadow score; normal discover ordering remains v1.
            'score_version' => ['sometimes', 'string', 'in:v2_shadow'],
            // #611 — safe presentation view for dashboards/research. Default
            // remains route-bearing dispatch data for current client compatibility.
            'view' => ['sometimes', 'string', 'in:dispatch,public'],
        ]);

        if ($message = $this->intentPolicy->refusalMessage($validated['intent'])) {
            throw ValidationException::withMessages(['intent' => [$message]]);
        }

        $span = OtelTracer::startSpan($request, 'iicp.directory.discover');
        $start = microtime(true);

        // Cache discover results very briefly only. This endpoint carries the
        // currently usable serving endpoint; for Quick Tunnel / relay/browser
        // routes a several-minute CDN cache can outlive the real tunnel and
        // make the browser UI report "no relay/browser node" while the registry
        // already shows a healthy node.
        $includeInternal = (bool) ($validated['include_internal'] ?? false);
        $cacheKey = 'discover:v1:'.md5(json_encode($validated));
        $nodes = Cache::remember($cacheKey, self::ORIGIN_CACHE_SECONDS, function () use ($validated, $includeInternal) {
            $scored = $this->scorer->discover(
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
                relayCapable: isset($validated['relay_capable']) ? (bool) $validated['relay_capable'] : null,
                scoreVersion: $validated['score_version'] ?? null,
            );

            return $this->withOperatorNames($scored);
        });

        $queryMs = round((microtime(true) - $start) * 1000);
        $span->setAttribute('iicp.intent', $validated['intent'])
            ->setAttribute('iicp.discover.count', count($nodes))
            ->setAttribute('iicp.discover.query_ms', $queryMs)
            ->setAttribute('iicp.discover.cip_capable_filter', isset($validated['cip_capable']) ? (bool) $validated['cip_capable'] : false);
        $span->end();

        // Cache-Control: discover is public+unauthenticated, but node serving
        // URLs are live routing state. Keep edge staleness below one heartbeat
        // interval so browser dispatch and relay election converge quickly after
        // tunnel rebuilds.
        // #402 (optional): relay_available signals ≥1 relay-capable node in the result set
        // so SDK auto-election (peer_manager::elect_relay) can warn operators up-front when
        // no relay peer exists, without a second round-trip to the directory.
        $relayAvailable = ! empty(array_filter($nodes, fn ($n) => ($n['relay_capable'] ?? false) === true));
        $view = $validated['view'] ?? 'dispatch';
        $responseNodes = $view === 'public' ? $this->publicDiscoverNodes($nodes) : $nodes;
        $dataClass = $view === 'public' ? 'public_presentation' : 'route_dispatch';
        $this->usage->record($view === 'public'
            ? DispatchUsageCounter::PUBLIC_VIEW
            : DispatchUsageCounter::LEGACY_DISPATCH);

        return response()->json([
            'nodes' => $responseNodes,
            'count' => count($nodes),
            'relay_available' => $relayAvailable,
            'query_ms' => $queryMs,
            // #611 — makes the public/dispatch split machine-visible. Public
            // pages should use registry endpoints or view=public; route-dispatch
            // consumers use the default view because they need serving URLs.
            'view' => $view,
            'data_class' => $dataClass,
            'route_fields_present' => $view === 'dispatch',
            // #612 — staged route-hardening migration. New clients can request
            // one concrete, short-lived dispatch route via POST before sending a
            // task, while older clients continue using default dispatch discover.
            'dispatch_ticket_endpoint' => '/api/v1/dispatch/ticket',
            'ticketed_dispatch_available' => true,
            'redaction' => $view === 'public'
                ? [
                    'node_id' => 'node_id_prefix_only',
                    'endpoint' => 'omitted',
                    'transport_endpoint' => 'omitted',
                    'transport_metadata' => 'omitted',
                    'cx_public_key' => 'key_ready_boolean_only',
                ]
                : [
                    'dispatch_route' => 'present',
                    'public_presentation' => 'use view=public or /api/v1/registry/*',
                ],
        ])->header(
            'Cache-Control',
            sprintf(
                'public, max-age=%d, s-maxage=%d, stale-while-revalidate=%d',
                self::ORIGIN_CACHE_SECONDS,
                self::EDGE_MAX_AGE_SECONDS,
                self::EDGE_STALE_REVALIDATE_SECONDS,
            )
        )
            ->header('X-IICP-Discover-Data-Class', $dataClass)
            ->header('Vary', 'Accept-Encoding');
    }

    /**
     * Return a presentation-safe projection of discover results.
     *
     * This intentionally keeps ranking, usability and trust fields while
     * removing fields that identify or dial the node (`node_id`, `endpoint`,
     * `transport_endpoint`, raw transport metadata and CX key material). It is
     * for dashboards, audits and educational examples; clients that actually
     * dispatch tasks must use route-dispatch discovery.
     *
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<int,array<string,mixed>>
     */
    private function publicDiscoverNodes(array $nodes): array
    {
        return array_map(fn (array $node) => $this->publicDiscoverNode($node), $nodes);
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<string,mixed>
     */
    private function publicDiscoverNode(array $node): array
    {
        return array_filter([
            'node_id_prefix' => $this->publicNodePrefix($node['node_id'] ?? null),
            'region' => $node['region'] ?? null,
            'score' => $node['score'] ?? null,
            'available' => $node['available'] ?? null,
            'relay_capable' => (bool) ($node['relay_capable'] ?? false),
            'route_class' => $this->publicRouteClass($node),
            'transport_method' => $node['transport_method'] ?? null,
            'nat_type' => $node['nat_type'] ?? null,
            'address_family' => $node['address_family'] ?? null,
            'transport' => $node['transport'] ?? null,
            'reachability_tier' => $node['reachability_tier'] ?? null,
            'directory_observed_reachable' => $node['directory_observed_reachable'] ?? null,
            'route_evidence' => $node['route_evidence'] ?? null,
            'routing_hint' => $node['routing_hint'] ?? null,
            'browser_usable' => $node['browser_usable'] ?? null,
            'exposure_mode' => $node['exposure_mode'] ?? null,
            'key_ready' => (bool) ($node['key_ready'] ?? (($node['cx_public_key'] ?? null) !== null)),
            'privacy_routing_status' => $node['privacy_routing_status'] ?? null,
            'sdk_language' => $node['sdk_language'] ?? null,
            'sdk_version' => $node['sdk_version'] ?? null,
            'sdk_status' => $node['sdk_status'] ?? null,
            'sdk_baseline_version' => $node['sdk_baseline_version'] ?? null,
            'upgrade_required' => $node['upgrade_required'] ?? null,
            'health_label' => $node['health_label'] ?? null,
            'health_confidence' => $node['health_confidence'] ?? null,
            'performance' => $node['performance'] ?? null,
            'backend_stability' => $node['backend_stability'] ?? null,
            'reputation_score' => $node['reputation_score'] ?? null,
            'reputation_tier' => $node['reputation_tier'] ?? null,
            'trust_progress' => $node['trust_progress'] ?? null,
            'probation' => $node['probation'] ?? null,
            'models' => $node['models'] ?? null,
            'capability_summary' => $node['capability_summary'] ?? null,
            'input_modalities' => $node['input_modalities'] ?? null,
            'quantization' => $node['quantization'] ?? null,
            'inference_engine' => $node['inference_engine'] ?? null,
            'backend' => $node['backend'] ?? null,
            'cip_policy' => $node['cip_policy'] ?? null,
            'cip_conformance_level' => $node['cip_conformance_level'] ?? null,
            'pricing' => $node['pricing'] ?? null,
            'operator_display_name' => $node['operator_display_name'] ?? null,
            'operator_fingerprint' => $node['operator_fingerprint'] ?? null,
            'node_policy_manifest' => $node['node_policy_manifest'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function publicNodePrefix(mixed $nodeId): ?string
    {
        if (! is_string($nodeId) || $nodeId === '') {
            return null;
        }

        $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $nodeId);

        return $isUuid ? substr($nodeId, 0, 8) : substr($nodeId, 0, 36);
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function publicRouteClass(array $node): string
    {
        foreach (['transport_method', 'routing_hint', 'reachability_tier', 'exposure_mode'] as $field) {
            $value = $node[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'unknown';
    }

    /**
     * #463 — enrich discover results with the operator's public `display_name`, resolved by
     * `operator_pubkey` for delegation-verified bindings. Batched: 2 queries regardless of result
     * size (node_id→operator_pubkey, then operator_pubkey→display_name). The operator_pubkey and
     * contact are NEVER included in the response; `operator_fingerprint` is a short public hash for
     * display-name disambiguation. A node without a verified operator binding simply gets neither
     * operator key.
     *
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<int,array<string,mixed>>
     */
    private function withOperatorNames(array $nodes): array
    {
        $ids = array_values(array_filter(array_map(static fn ($n) => $n['node_id'] ?? null, $nodes)));
        if ($ids === []) {
            return $nodes;
        }

        $pubByNode = Node::query()
            ->whereIn('id', $ids)
            ->whereNotNull('operator_pubkey')
            ->where('operator_verified', true)
            ->pluck('operator_pubkey', 'id');
        if ($pubByNode->isEmpty()) {
            return $nodes;
        }

        $nameByPub = Operator::query()
            ->whereIn('operator_pubkey', $pubByNode->unique()->values()->all())
            ->where('identity_status', Operator::IDENTITY_ACTIVE)
            ->whereNotNull('display_name')
            ->pluck('display_name', 'operator_pubkey');

        foreach ($nodes as &$node) {
            $pub = $pubByNode[$node['node_id'] ?? ''] ?? null;
            if ($pub !== null && isset($nameByPub[$pub])) {
                $node['operator_display_name'] = $nameByPub[$pub];
                $node['operator_fingerprint'] = Operator::publicFingerprint($pub);
            }
        }
        unset($node);

        return $nodes;
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;
use Carbon\Carbon;

/**
 * Scoring and discovery engine — the algorithmic heart of GET /v1/discover (ADR-008, ADR-012).
 *
 * WHY two weight sets (Phase 3 vs Phase 5):
 *   - Phase 3 (no ?model=): availability, load, capacity, region, reputation shape the score.
 *     These five dimensions are always meaningful regardless of what model is running.
 *   - Phase 5 (with ?model=): W_MODEL and W_PRICE are added (ADR-012, ADR-021), reducing the
 *     other weights proportionally. A model match drives routing for model-specific tasks;
 *     price transparency matters more when a credit ledger is in play.
 *
 * WHY EXPIRY_SECONDS = 90: heartbeat cadence is 30s; a node that misses 3 consecutive
 * heartbeats (90s of silence) is considered unavailable. Proxies that cached the node from
 * discover will also hit circuit-breaker state and stop routing within that window.
 *
 * WHY MIN_SCORE = 0.1: allows lightly-loaded nodes to surface in discover even under
 * adverse conditions (e.g., high load on all nodes). A zero floor would incorrectly signal
 * "this node should never be chosen" when the issue is a temporary capacity spike.
 *
 * Spec: spec/iicp-dir.md §discover; spec/iicp-semantics.md §node-selection.
 * ADR: ADR-008 (scoring formula), ADR-012 (Phase 5 model-aware weights), ADR-021 (virtual nodes).
 */
class NodeScorer
{
    private const EXPIRY_SECONDS = 90;

    /**
     * ADR-047 (#411) — reachability tiers. A heartbeating node with a routable
     * serving surface (any of the 8 ADR-043 §9 exposure_mode categories) is
     * reachable directly OR via relay — it must NOT be hidden just because the
     * directory's dial-back failed (the prod IPv6-egress gap). `public_reachable`
     * stays the dial-back signal: true → `direct`, false-but-routable → `relay`.
     * Nodes with no exposure_mode (legacy/internal localhost/docker) stay hidden.
     */
    public const RELAY_REACHABLE_EXPOSURE_MODES = [
        'ipv4_public_direct',
        'ipv6_direct_pinhole_available',
        'ipv6_direct_firewall_required',
        'ipv4_cgnat_blocked',
        'relay_required',
        'tunnel_required',
        'dual_stack_available',
        'outbound_only',
    ];

    private const MIN_SCORE = 0.1;

    // Phase 3 weights — used when no model preference is specified
    private const W_AVAILABILITY = 0.35;

    private const W_LOAD = 0.28;

    private const W_CAPACITY = 0.18;

    private const W_REGION = 0.09;

    private const W_REPUTATION = 0.10;

    // Phase 5 weights — used when ?model= is specified (ADR-012 W_MODEL, ADR-021)
    private const P5_W_AVAILABILITY = 0.25;

    private const P5_W_LOAD = 0.20;

    private const P5_W_CAPACITY = 0.15;

    private const P5_W_REGION = 0.10;

    private const P5_W_REPUTATION = 0.10;

    private const P5_W_PRICE = 0.10;

    private const P5_W_MODEL = 0.10;

    public function __construct(private NodeHealthService $health) {}

    public function discover(
        string $intent,
        ?string $qos,
        ?string $region,
        ?string $model,
        float $minReputation,
        int $limit,
        ?float $maxMultiplier = null,
        ?float $minQualityScore = null,
        ?bool $cipCapable = null,
        bool $includeInternal = false,
        ?string $modality = null,
    ): array {
        $cutoff = Carbon::now()->subSeconds(self::EXPIRY_SECONDS);

        $query = Node::query()
            ->where('available', true)
            ->where('status', 'active')
            ->where('last_seen', '>=', $cutoff)
            // #326 + ADR-047 (#411) — default discover returns reachable nodes:
            // dial-back-verified (`public_reachable=true` → direct tier) OR
            // heartbeating with a routable serving surface (`exposure_mode` in the
            // 8-category set → relay tier; reachable via relay even when the
            // directory can't dial back, e.g. CGNAT/IPv6 with no origin egress).
            // Legacy/internal nodes (no exposure_mode) stay hidden unless
            // include_internal=true. This un-hides heartbeating CGNAT/IPv6 fleets
            // the dial-back-only filter wrongly dropped.
            ->when(! $includeInternal, fn ($q) => $q->where(function ($w) {
                $w->where('public_reachable', true)
                    ->orWhereIn('exposure_mode', self::RELAY_REACHABLE_EXPOSURE_MODES);
            }))
            ->when($cipCapable === true, fn ($q) => $q->where('allow_remote_inference', true))
            // #408/ADR-046 — ?modality=image filters to nodes whose capability for
            // this intent accepts that input modality (vision = image-capable chat).
            ->when($modality !== null, fn ($q) => $q->whereHas(
                'capabilities',
                fn ($q2) => $q2->where('intent', $intent)->whereJsonContains('input_modalities', $modality)
            ))
            ->whereHas('capabilities', fn ($q) => $q->where('intent', $intent))
            ->with([
                'capabilities' => fn ($q) => $q->where('intent', $intent),
                'availabilityWindows',
                'reputation',
            ]);

        $nodes = $query->get();

        // When ?model= is specified, exclude nodes that don't advertise the model at all.
        // Nodes with model_match=0.0 scored below MIN_SCORE anyway, but filtering early
        // is cheaper and makes intent obvious in the code path.
        if ($model !== null) {
            $nodes = $nodes->filter(function (Node $node) use ($model) {
                foreach ($node->capabilities as $cap) {
                    if (in_array($model, $cap->models ?? [], true)) {
                        return true;
                    }
                }

                return false;
            });
        }

        // Probation tier filter — spec §11.3 / #117:
        //   realtime  → requires completed_tasks_count ≥ 1000 AND reputation_score ≥ 0.8
        //   interactive → requires completed_tasks_count ≥ 100
        //   batch / best-effort → no probation filter
        if ($qos === 'realtime') {
            $nodes = $nodes->filter(function (Node $node) {
                $rep = $node->reputation;

                return $rep !== null
                    && $rep->completed_tasks_count >= 1000
                    && $rep->score >= 0.8;
            });
        } elseif ($qos === 'interactive') {
            $nodes = $nodes->filter(
                fn (Node $node) => ($node->reputation?->completed_tasks_count ?? 0) >= 100
            );
        }

        // min_reputation filter: exclude nodes below the consumer-requested threshold.
        // Default 0.0 = no filter (backward compatible).
        if ($minReputation > 0.0) {
            $nodes = $nodes->filter(
                fn (Node $node) => ($node->reputation?->score ?? 0.5) >= $minReputation
            );
        }

        $scored = $nodes
            ->map(fn (Node $node) => $this->scoreNode($node, $region, $model))
            ->filter(fn (array $item) => $item['score'] >= self::MIN_SCORE)
            ->when($maxMultiplier !== null, fn ($c) => $c->filter(
                fn (array $item) => $item['node']->credit_cost_multiplier <= $maxMultiplier
            ))
            ->when($minQualityScore !== null, fn ($c) => $c->filter(
                fn (array $item) => $item['score'] >= $minQualityScore
            ))
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        return $scored->map(fn (array $item) => [
            'node_id' => $item['node']->id,
            'endpoint' => $item['node']->endpoint,
            // spec v0.7.0 — native IICP binary endpoint; null when node only serves HTTP
            'transport_endpoint' => $item['node']->transport_endpoint,
            // #331 Phase A.1 / ADR-041 — NAT-traversal observability surfaced in discover
            'transport_method' => $item['node']->transport_method,
            'nat_type' => $item['node']->nat_type,
            'transport_metadata' => $item['node']->transport_metadata,
            // SDK identification surfaced so consumers / dashboards can
            // render a language badge (#338 follow-up). Free-form for future
            // C / C++ / Java / Go / WASM SDKs.
            'sdk_language' => $item['node']->sdk_language,
            'sdk_version' => $item['node']->sdk_version,
            // IICP-CX S.16 §3.2 — X25519 public key surfaced so CX-Consumers can
            // encrypt task payloads E2E to the node (#360). null = node does not
            // support CX and MUST NOT receive CX-encrypted payloads.
            'public_key' => $item['node']->cx_public_key,
            // Address-family signal — 'ipv4' / 'ipv6' / 'dual' / 'unknown'.
            // Derived from endpoint + transport_endpoint hosts so dashboards
            // can render an IPv4/IPv6 badge per maintainer directive 2026-05-27.
            'address_family' => $this->detectAddressFamily(
                $item['node']->endpoint,
                $item['node']->transport_endpoint,
            ),
            // #397 — transport protocols (http/https/iicp-native) so clients can
            // prefer the native binary path without a second round-trip to detail.
            'transport' => self::transportMethods(
                $item['node']->endpoint,
                $item['node']->transport_endpoint,
            ),
            'score' => round($item['score'], 4),
            'latency_estimate_ms' => $item['node']->reputation?->observed_latency_ms !== null
                ? (int) round($item['node']->reputation->observed_latency_ms)
                : null,
            'available' => $item['node']->available,
            'relay_capable' => (bool) $item['node']->relay_capable,
            // ADR-043 §9 — 8-category network exposure classification. Surfaced
            // so consumers can prefer directly-reachable nodes (closes #372/#344
            // live-verify gap: the column was stored but never serialized).
            'exposure_mode' => $item['node']->exposure_mode,
            // ADR-047 (#411) — reachability tier so clients prefer directly-dialable
            // nodes and fall back to relay-routed ones. `direct` = dial-back verified;
            // `relay` = heartbeating + routable surface, reach via relay (#341/#402).
            'reachability_tier' => $item['node']->public_reachable ? 'direct' : 'relay',
            // ADR-044 (#372) — composed per-node health label so clients can
            // prefer healthy nodes without a second round-trip to node detail.
            'health_label' => $this->health->forNode($item['node'])['label'],
            'region' => $item['node']->region,
            'reputation_score' => $item['node']->reputation?->score ?? 0.5,
            'reputation_tier' => self::reputationTier($item['node']),
            'max_concurrent' => $item['node']->max_concurrent,
            'active_jobs' => $item['node']->active_jobs,
            'load' => $item['node']->load,
            'models' => $item['node']->capabilities->flatMap(fn ($c) => $c->models ?? [])->unique()->values()->all(),
            // #408/ADR-046 — union of input modalities across this intent's capabilities
            // (e.g. ["text","image"] when the node has a vision model). Default ["text"].
            'input_modalities' => $item['node']->capabilities
                ->flatMap(fn ($c) => $c->input_modalities ?: ['text'])->unique()->values()->all(),
            'quantization' => $item['node']->capabilities->pluck('quantization')->filter()->unique()->values()->all(),
            'inference_engine' => $item['node']->capabilities->pluck('inference_engine')->filter()->unique()->values()->all(),
            'backend' => $item['node']->backend,
            // CIP-D1: Provider opt-in policy block (spec S.12 §2.1)
            'cip_policy' => [
                'allow_remote_inference' => (bool) $item['node']->allow_remote_inference,
                'allow_tool_execution' => (bool) $item['node']->allow_tool_execution,
                'allow_file_access' => (bool) $item['node']->allow_file_access,
                'pricing_credits_per_1000' => $item['node']->pricing_credits_per_1000,
            ],
            // CIP conformance level per S.12 §5.2 (REP1)
            'cip_conformance_level' => $item['node']->allow_remote_inference ? 'CIP-Provider' : 'CIP-None',
            // ADR-019 pricing declaration
            'pricing' => [
                'credit_cost_multiplier' => $item['node']->credit_cost_multiplier ?? 1.0,
                'pricing_model' => $item['node']->pricing_model ?? 'per_token',
                'attested' => (bool) ($item['node']->attested ?? false),
            ],
        ])->all();
    }

    /**
     * Reputation tier per S.12 §5.1.1 (REP2).
     * Platinum requires score ≥ 0.85 AND identity age ≥ 720 h (30 days).
     *
     * Single source of truth for tier labels — consumed by the discover
     * endpoint AND the registry list/detail endpoints so the website renders
     * one authoritative tier (no client-side recomputation drift).
     */
    public static function reputationTier(Node $node): string
    {
        // Bronze is the floor tier for all sub-Silver nodes (CIP spec v0.6.9,
        // 2026-05-30): probation nodes (< 100 tasks) AND low-score post-probation
        // nodes (score < 0.40) both emit "bronze"; "none" is retired.
        $completedTasks = $node->reputation?->completed_tasks_count ?? 0;
        if ($completedTasks < 100) {
            return 'bronze';
        }
        $score = $node->reputation?->score ?? 0.5;
        if ($score < 0.40) {
            return 'bronze';
        }
        if ($score < 0.65) {
            return 'silver';
        }
        if ($score < 0.85) {
            return 'gold';
        }
        $ageHours = $node->created_at?->diffInHours(now()) ?? 0;

        return $ageHours >= 720 ? 'platinum' : 'gold';
    }

    /**
     * Transport protocols a node speaks, derived from its endpoint schemes
     * (#397). Privacy-preserving: returns only protocol tokens, never the host.
     *
     * - HTTP control plane: `https://…` → "https", `http://…` → "http"
     * - Native IICP binary (port 948x, spec v0.7.0): `iicp://…` / `iicpsec://…`
     *   `transport_endpoint` → "iicp-native"
     *
     * @return list<string> e.g. ["https", "iicp-native"], ["http"], []
     */
    public static function transportMethods(?string $endpoint, ?string $transportEndpoint): array
    {
        $out = [];
        $scheme = strtolower((string) (parse_url((string) $endpoint, PHP_URL_SCHEME) ?: ''));
        if ($scheme === 'https' || $scheme === 'http') {
            $out[] = $scheme;
        }
        $tcpScheme = strtolower((string) (parse_url((string) $transportEndpoint, PHP_URL_SCHEME) ?: ''));
        if ($tcpScheme === 'iicp' || $tcpScheme === 'iicpsec') {
            $out[] = 'iicp-native';
        }

        return $out;
    }

    private function scoreNode(Node $node, ?string $requestedRegion, ?string $requestedModel = null): array
    {
        $availabilityScore = $this->computeAvailability($node);
        $loadScore = 1.0 - min($node->load, 1.0);
        $capacityScore = $node->max_concurrent > 0
            ? 1.0 - ($node->active_jobs / $node->max_concurrent)
            : 0.0;
        $regionScore = match (true) {
            $requestedRegion === null => 0.5,
            $node->region === $requestedRegion => 1.0,
            default => 0.0,
        };
        $reputationScore = $node->reputation?->score ?? 0.5;

        if ($requestedModel !== null) {
            $modelMatch = $this->computeModelMatch($node, $requestedModel);
            // W_PRICE: cheaper nodes score higher; 0.5 neutral when pricing unavailable
            $priceScore = $node->pricing_credits_per_1000 !== null
                ? max(0.0, 1.0 - ($node->pricing_credits_per_1000 / 10.0))
                : 0.5;

            $score = (self::P5_W_AVAILABILITY * $availabilityScore)
                + (self::P5_W_LOAD * $loadScore)
                + (self::P5_W_CAPACITY * max(0.0, $capacityScore))
                + (self::P5_W_REGION * $regionScore)
                + (self::P5_W_REPUTATION * $reputationScore)
                + (self::P5_W_PRICE * $priceScore)
                + (self::P5_W_MODEL * $modelMatch);
        } else {
            $score = (self::W_AVAILABILITY * $availabilityScore)
                + (self::W_LOAD * $loadScore)
                + (self::W_CAPACITY * max(0.0, $capacityScore))
                + (self::W_REGION * $regionScore)
                + (self::W_REPUTATION * $reputationScore);
        }

        return ['node' => $node, 'score' => $score];
    }

    private function computeModelMatch(Node $node, string $model): float
    {
        foreach ($node->capabilities as $cap) {
            if (in_array($model, $cap->models ?? [], true)) {
                return 1.0;
            }
        }

        return 0.0;
    }

    private function computeAvailability(Node $node): float
    {
        $windows = $node->availabilityWindows;

        if ($windows->isEmpty()) {
            return 1.0;
        }

        $nowTime = now()->format('H:i:s');

        foreach ($windows as $window) {
            if ($nowTime >= $window->start_time && $nowTime <= $window->end_time) {
                return (float) $window->share;
            }
        }

        return 0.5;
    }

    /**
     * Detect the IP address family of a node's advertised endpoints.
     * Returns 'ipv4', 'ipv6', 'dual' (both transports use opposite families),
     * 'hostname' (DNS-only, family unknown until resolved), or 'unknown'.
     *
     * Per maintainer directive 2026-05-27: surface this so the website
     * dashboard / discover consumers can render an IPv4 vs IPv6 badge.
     */
    private function detectAddressFamily(?string $endpoint, ?string $transportEndpoint): string
    {
        $primary = $this->hostFamily($endpoint);
        $tcp = $this->hostFamily($transportEndpoint);
        if ($primary === 'unknown' && $tcp === 'unknown') {
            return 'unknown';
        }
        // Hostname (DNS name) — can't statically tell without resolving;
        // defer to consumer-side resolution. Render badge as 'DNS' or
        // hide entirely on the website.
        if ($primary === 'hostname' || $tcp === 'hostname') {
            return 'hostname';
        }
        if ($primary === $tcp) {
            return $primary;
        }
        if ($primary !== 'unknown' && $tcp !== 'unknown') {
            return 'dual';
        }

        return $primary !== 'unknown' ? $primary : $tcp;
    }

    private function hostFamily(?string $url): string
    {
        if (! $url) {
            return 'unknown';
        }
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return 'unknown';
        }
        // Strip IPv6 brackets that parse_url leaves on
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'ipv4';
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'ipv6';
        }

        // Looks like a DNS hostname — family unknown until resolved.
        return 'hostname';
    }
}

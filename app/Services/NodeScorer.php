<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;
use App\Models\Operator;
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

    /** Current SDK baseline for strict demotion of downlevel/unkeyed nodes. */
    public const SDK_BASELINE_VERSION = NodeReadinessPolicy::SDK_BASELINE_VERSION;

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

    public function __construct(
        private NodeHealthService $health,
        private CapabilityEvidencePolicy $capabilityEvidence,
        private NodeEligibilityPolicy $eligibility,
        private NodeRankingPolicy $ranking,
    ) {}

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
        ?bool $relayCapable = null,
        ?string $scoreVersion = null,
        ?DiscoveryPhaseTiming $timing = null,
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
            ->when(! $includeInternal, fn ($q) => $q
                // #536 — a listed endpoint confirmed dead must be hidden from normal
                // discover even if the node is relay-capable or has a routable
                // exposure_mode. include_internal remains the diagnostics escape hatch.
                ->whereNull('endpoint_verified_dead_at')
                ->where(function ($w) {
                    $w->where('public_reachable', true)
                        ->orWhereIn('exposure_mode', self::RELAY_REACHABLE_EXPOSURE_MODES);
                }))
            ->when($cipCapable === true, fn ($q) => $q->where('allow_remote_inference', true))
            // #528 — ?relay_capable=true filters to nodes that can act as a relay
            // (browser/CGNAT auto-discovery binds these). Previously a no-op param.
            ->when($relayCapable === true, fn ($q) => $q->where('relay_capable', true))
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

        $nodes = $timing !== null
            ? $timing->measure('database_hydration', fn () => $query->get())
            : $query->get();

        $scoringStarted = hrtime(true);

        $eligible = fn () => $this->eligibility->filter($nodes, $model, $qos, $minReputation);
        $nodes = $timing !== null ? $timing->profile('eligibility', $eligible) : $eligible();

        $rank = fn () => $nodes
            ->map(fn (Node $node) => ['node' => $node, 'score' => $this->ranking->score($node, $region, $model)])
            ->filter(fn (array $item) => $item['score'] >= self::MIN_SCORE)
            ->when($maxMultiplier !== null, fn ($c) => $c->filter(fn (array $item) => $item['node']->credit_cost_multiplier <= $maxMultiplier))
            ->when($minQualityScore !== null, fn ($c) => $c->filter(fn (array $item) => $item['score'] >= $minQualityScore))
            ->sortByDesc('score')->take($limit)->values();
        $scored = $timing !== null ? $timing->profile('ranking', $rank) : $rank();

        $enrichHealth = fn () => $this->health->forNodes($scored->pluck('node'));
        $healthByNode = $timing !== null ? $timing->profile('health_enrichment', $enrichHealth) : $enrichHealth();

        $project = fn () => $scored->map(function (array $item) use ($intent, $model, $scoreVersion, $healthByNode): array {
            $node = $item['node'];
            $registeredModels = $this->capabilityEvidence->registeredModels($node);
            $liveModels = $this->capabilityEvidence->liveModels($node, $registeredModels);
            $health = $healthByNode[$node->id] ?? $this->health->forNode($node);
            $capabilitySummary = $this->capabilitySummary($node, $registeredModels, $liveModels);
            $routingSignals = self::routingSignals($node, $health);
            $policyManifest = self::policyManifest($node);
            $out = [
                'node_id' => $node->id,
                'endpoint' => $node->endpoint,
                // spec v0.7.0 — native IICP binary endpoint; null when node only serves HTTP
                'transport_endpoint' => $node->transport_endpoint,
                // #331 Phase A.1 / ADR-041 — NAT-traversal observability surfaced in discover
                'transport_method' => $node->transport_method,
                'nat_type' => $node->nat_type,
                'transport_metadata' => $node->transport_metadata,
                // SDK identification surfaced so consumers / dashboards can
                // render a language badge (#338 follow-up). Free-form for future
                // C / C++ / Java / Go / WASM SDKs.
                'sdk_language' => $node->sdk_language,
                'implementation_name' => $node->implementation_name,
                'implementation_version' => $node->implementation_version,
                'sdk_compatibility_version' => $node->effectiveSdkCompatibilityVersion(),
                'sdk_version' => $node->effectiveSdkCompatibilityVersion(),
                // Pre-normative migration evidence only. Never expose the raw
                // mutable profile list or use it as a routing/trust signal.
                'consumer_cosignature_ready' => in_array(
                    'consumer_cosignature_v1',
                    $node->supported_receipt_profiles ?? [],
                    true,
                ),
                // IICP-CX S.16 §3.2 — canonical X25519 public key surfaced under the
                // same name used by REGISTER/storage so CX-Consumers can encrypt task
                // payloads E2E to the node (#360). null = node does not support CX and
                // MUST NOT receive CX-encrypted payloads.
                'cx_public_key' => $node->cx_public_key,
                // Address-family signal — 'ipv4' / 'ipv6' / 'dual' / 'unknown'.
                // Derived from endpoint + transport_endpoint hosts so dashboards
                // can render an IPv4/IPv6 badge per maintainer directive 2026-05-27.
                'address_family' => $this->detectAddressFamily(
                    $node->endpoint,
                    $node->transport_endpoint,
                ),
                // #397 — transport protocols (http/https/iicp-native) so clients can
                // prefer the native binary path without a second round-trip to detail.
                'transport' => self::transportMethods(
                    $node->endpoint,
                    $node->transport_endpoint,
                ),
                'score' => round($item['score'], 4),
                'latency_estimate_ms' => $node->reputation?->observed_latency_ms !== null
                    ? (int) round($node->reputation->observed_latency_ms)
                    : null,
                'latency_evidence' => self::latencyEvidence($node),
                ...self::performanceSignals($node),
                'available' => $node->available,
                'relay_capable' => (bool) $node->relay_capable,
                'probation' => ($node->reputation?->completed_tasks_count ?? 0) < 100,
                'trust_progress' => self::trustProgress($node),
                // ADR-043 §9 — 8-category network exposure classification. Surfaced
                // so consumers can prefer directly-reachable nodes (closes #372/#344
                // live-verify gap: the column was stored but never serialized).
                'exposure_mode' => $node->exposure_mode,
                // ADR-047 (#411) — reachability tier so clients prefer directly-dialable
                // nodes and fall back to relay-routed ones. `direct` = dial-back verified;
                // `relay` = heartbeating + routable surface, reach via relay (#341/#402).
                // Deprecated display hint: see route_evidence/routing_hint/browser_usable
                // for the split machine-readable routing contract.
                'reachability_tier' => $node->public_reachable ? 'direct' : 'relay',
                // Additive routing-signal split: discoverability, directory-observed
                // reachability and browser usability are different facts. Keep the
                // legacy reachability_tier above for one adoption window.
                ...$routingSignals,
                ...self::complianceSignals($node),
                // ADR-044 (#372) — composed per-node health label so clients can
                // prefer healthy nodes without a second round-trip to node detail.
                'health_label' => $health['label'],
                'health_confidence' => $health['confidence'] ?? null,
                'health_reasons' => self::healthReasons($node, $health, $policyManifest),
                'region' => $node->region,
                'reputation_score' => $node->reputation?->score ?? 0.5,
                'reputation_model' => $node->reputation_model ?? 'legacy',
                'reputation_epoch' => $node->reputation_epoch,
                'reputation_tier' => self::reputationTier($node),
                'max_concurrent' => $node->max_concurrent,
                'active_jobs' => $node->active_jobs,
                'load' => $node->load,
                // #494: if health_models is reported, advertise only models currently live;
                // otherwise fall back to static capabilities (backward compat, null = unknown).
                'models' => $liveModels,
                'capability_summary' => $capabilitySummary,
                'capabilities' => $node->capabilities
                    ->map(fn ($capability) => $capability->toEffectiveCapabilityArray())
                    ->values()->all(),
                // #408/ADR-046 — union of input modalities across this intent's capabilities
                // (e.g. ["text","image"] when the node has a vision model). Default ["text"].
                'input_modalities' => $node->capabilities
                    ->flatMap(fn ($c) => $c->input_modalities ?: ['text'])->unique()->values()->all(),
                'supported_profiles' => $node->capabilities
                    ->where('intent', $intent)
                    ->flatMap(fn ($c) => $c->supported_profiles ?: [])->unique()->values()->all(),
                'quantization' => $node->capabilities->pluck('quantization')->filter()->unique()->values()->all(),
                'inference_engine' => $node->capabilities->pluck('inference_engine')->filter()->unique()->values()->all(),
                'backend' => $node->backend,
                'backend_stability' => self::backendStability($node),
                'node_policy_manifest' => $policyManifest,
                // CIP-D1: Provider opt-in policy block (spec S.12 §2.1)
                'cip_policy' => [
                    'allow_remote_inference' => (bool) $node->allow_remote_inference,
                    'allow_tool_execution' => (bool) $node->allow_tool_execution,
                    'allow_file_access' => (bool) $node->allow_file_access,
                    'pricing_credits_per_1000' => $node->pricing_credits_per_1000,
                ],
                // CIP conformance level per S.12 §5.2 (REP1)
                'cip_conformance_level' => $node->allow_remote_inference ? 'CIP-Provider' : 'CIP-None',
                // ADR-019 pricing declaration
                'pricing' => [
                    'credit_cost_multiplier' => $node->credit_cost_multiplier ?? 1.0,
                    'pricing_model' => $node->pricing_model ?? 'per_token',
                    'attested' => (bool) ($node->attested ?? false),
                ],
            ];

            if ($scoreVersion === 'v2_shadow') {
                $out += $this->ranking->shadowV2($node, $health, $capabilitySummary, $model);
            }

            return $out;
        })->all();
        $result = $timing !== null ? $timing->profile('projection', $project) : $project();

        if ($timing !== null) {
            $timing->set('scoring', (hrtime(true) - $scoringStarted) / 1_000_000);
        }

        return $result;
    }

    /**
     * Reputation tier per S.12 §5.1.1 (REP2) plus #554 observation gates.
     *
     * Score remains necessary but not sufficient for higher public trust tiers:
     * Gold requires at least 100 completed observations, and Platinum requires
     * at least 1000 completed observations plus identity age ≥ 720 h (30 days).
     * This keeps sudden score jumps from being presented as sustained trust.
     *
     * Single source of truth for tier labels — consumed by the discover
     * endpoint AND the registry list/detail endpoints so the website renders
     * one authoritative tier (no client-side recomputation drift).
     */
    public static function reputationTier(Node $node): string
    {
        $score = $node->reputation?->score ?? 0.5;
        $completed = (int) ($node->reputation?->completed_tasks_count ?? 0);
        if ($score < 0.40) {
            return 'bronze';
        }
        if ($score < 0.65) {
            return 'silver';
        }

        // #554: high public tiers require sustained observed work, not only a
        // score threshold. Nodes with too little evidence stay at the safer
        // Silver display tier even if a local/test heartbeat jumps the score.
        if ($completed < 100) {
            return 'silver';
        }

        if ($score < 0.85) {
            return 'gold';
        }
        $ageHours = $node->created_at?->diffInHours(now()) ?? 0;

        return ($ageHours >= 720 && $completed >= 1000) ? 'platinum' : 'gold';
    }

    public static function trustProgress(Node $node): array
    {
        $completed = (int) ($node->reputation?->completed_tasks_count ?? 0);
        $score = (float) ($node->reputation?->getAttribute('score') ?? 0.5);
        $remainingGoldRequirements = [];
        if ($completed < 100) {
            $remainingGoldRequirements[] = 'completed_tasks';
        }
        if ($score < 0.65) {
            $remainingGoldRequirements[] = 'reputation_score';
        }

        return [
            'completed_tasks' => $completed,
            'gold_min_tasks' => 100,
            'platinum_min_tasks' => 1000,
            'tasks_until_gold' => max(0, 100 - $completed),
            'tasks_until_platinum' => max(0, 1000 - $completed),
            'gold_task_threshold_met' => $completed >= 100,
            'gold_reputation_threshold_met' => $score >= 0.65,
            'remaining_gold_requirements' => $remainingGoldRequirements,
            'probation' => $completed < 100,
        ];
    }

    /** @return array{estimate_ms:int|null,basis:string} */
    public static function latencyEvidence(Node $node): array
    {
        $estimate = self::positiveFloat($node->reputation?->getAttribute('observed_latency_ms'));

        return [
            'estimate_ms' => $estimate !== null ? (int) round($estimate) : null,
            'basis' => $estimate !== null ? 'multi_proxy_ema' : 'none',
        ];
    }

    /**
     * Explain independent health dimensions without changing the composed label.
     *
     * @param  array<string,mixed>|null  $health
     * @param  array<string,mixed>|null  $policyManifest
     * @return list<array{dimension:string,state:string,reason:string,evidence:string}>
     */
    public static function healthReasons(Node $node, ?array $health, ?array $policyManifest): array
    {
        $observedReachable = self::directoryObservedReachable($health);
        $backend = self::backendStability($node);
        $policyStatus = $policyManifest['verification']['status'] ?? null;
        $trust = self::trustProgress($node);

        return [
            [
                'dimension' => 'reachability',
                'state' => $observedReachable === true ? 'reachable' : ($observedReachable === false ? 'unreachable' : 'unknown'),
                'reason' => $observedReachable === null ? 'not_directory_observed' : 'directory_observation',
                'evidence' => $health['evidence_level'] ?? 'none',
            ],
            [
                'dimension' => 'backend',
                'state' => (string) ($backend['backend_state'] ?? 'unknown'),
                'reason' => (string) ($backend['reason_class'] ?? 'not_reported'),
                'evidence' => (string) ($backend['evidence'] ?? 'not_reported'),
            ],
            [
                'dimension' => 'trust',
                'state' => $trust['probation'] ? 'probation' : 'established',
                'reason' => $trust['probation'] ? 'task_threshold_pending' : 'task_threshold_met',
                'evidence' => 'directory_accounting',
            ],
            [
                'dimension' => 'policy',
                'state' => $policyManifest === null ? 'missing' : ($policyStatus === 'verified' ? 'verified' : 'unverified'),
                'reason' => $policyManifest === null ? 'manifest_not_provided' : (string) ($policyStatus ?? 'signature_not_verified'),
                'evidence' => $policyManifest === null ? 'none' : 'manifest_projection',
            ],
        ];
    }

    /**
     * Additive routing-signal split for clients and dashboards.
     *
     * - directory_observed_reachable: true/false only when a recent active probe
     *   produced evidence; null means no directory observation is available.
     * - route_evidence: whether the current route is observed, self-attested, or
     *   missing.
     * - routing_hint: coarse client transport bucket, not a trust label.
     * - browser_usable: true only for endpoints a normal HTTPS page may call.
     *
     * This intentionally does NOT change discovery eligibility; it replaces the
     * overloaded meaning clients were reading into reachability_tier.
     */
    public static function routingSignals(Node $node, ?array $health = null): array
    {
        $observedReachable = self::directoryObservedReachable($health);

        return [
            'directory_observed_reachable' => $observedReachable,
            'route_evidence' => $observedReachable !== null
                ? 'directory_observed'
                : (self::selfAttestsRoute($node) ? 'self_attested' : 'missing'),
            'routing_hint' => self::routingHint($node),
            'browser_usable' => self::browserUsableEndpoint($node->endpoint),
        ];
    }

    /**
     * Public route-recovery hints for dashboards.
     *
     * These fields are deliberately advisory. They explain why a node may be
     * alive but not fully stable from the directory/browser point of view
     * without changing the actual discovery predicate or leaking endpoints.
     *
     * @return array{recovery_state:string,route_recovery_hint:string}
     */
    public static function recoverySignals(Node $node, ?array $health = null): array
    {
        $routing = self::routingSignals($node, $health);
        $heartbeating = self::isHeartbeating($node);
        $publicRoutable = self::publicRoutable($node);

        if (! $heartbeating) {
            return [
                'recovery_state' => 'unavailable',
                'route_recovery_hint' => 'no_recent_heartbeat',
            ];
        }

        if ($node->endpoint_verified_dead_at !== null) {
            return [
                'recovery_state' => 'cooldown',
                'route_recovery_hint' => 'route_cooldown',
            ];
        }

        if (! $publicRoutable) {
            return [
                'recovery_state' => self::selfAttestsRoute($node) ? 'recovering' : 'unknown',
                'route_recovery_hint' => self::selfAttestsRoute($node) ? 'route_recovering' : 'route_not_advertised',
            ];
        }

        if (($routing['routing_hint'] ?? null) === 'http_ipv6'
            && ($routing['route_evidence'] ?? null) !== 'directory_observed') {
            return [
                'recovery_state' => 'recovering',
                'route_recovery_hint' => 'direct_route_unverified',
            ];
        }

        if ((bool) $node->relay_capable && ! (bool) $node->public_reachable) {
            return [
                'recovery_state' => 'recovering',
                'route_recovery_hint' => 'relay_route_waiting',
            ];
        }

        if (in_array($node->exposure_mode, ['relay_required', 'tunnel_required', 'outbound_only'], true)
            && ! (bool) $node->public_reachable
            && ($routing['route_evidence'] ?? null) !== 'directory_observed') {
            return [
                'recovery_state' => 'recovering',
                'route_recovery_hint' => 'tunnel_recovering',
            ];
        }

        return [
            'recovery_state' => 'stable',
            'route_recovery_hint' => 'none',
        ];
    }

    public static function complianceSignals(Node $node): array
    {
        $sdkStatus = (new NodeReadinessPolicy)->sdkStatus($node->effectiveSdkCompatibilityVersion());
        $keyReady = $node->cx_public_key !== null;
        $latestSeen = config('app.iicp_sdk_latest_known_version');
        $latestSeen = is_string($latestSeen) && $latestSeen !== '' ? $latestSeen : null;
        $releaseRelation = match (true) {
            $node->effectiveSdkCompatibilityVersion() === null || $latestSeen === null => 'unknown',
            version_compare($node->effectiveSdkCompatibilityVersion(), $latestSeen, '=') => 'latest_known',
            version_compare($node->effectiveSdkCompatibilityVersion(), $latestSeen, '<') => 'behind_known',
            default => 'ahead_of_known',
        };

        return [
            'sdk_status' => $sdkStatus,
            'sdk_baseline_version' => self::SDK_BASELINE_VERSION,
            'upgrade_required' => $sdkStatus !== 'current',
            'sdk_release' => [
                'compatibility' => $sdkStatus,
                'relation' => $releaseRelation,
                'latest_known_version' => $latestSeen,
                'latest_known_source' => $latestSeen === null ? 'none' : 'directory_release_manifest',
            ],
            'key_ready' => $keyReady,
            'privacy_routing_status' => $keyReady ? 'key_ready' : 'transitional',
            'auto_update' => [
                'enabled' => $node->auto_update_enabled,
                'interval_s' => $node->auto_update_interval_s,
                'latest_seen' => $node->sdk_latest_seen,
                'last_checked_at' => $node->sdk_update_last_checked_at?->toIso8601String(),
                'error_class' => $node->sdk_update_error_class,
                'evidence' => $node->auto_update_enabled === null ? 'unknown' : 'self_reported',
            ],
        ];
    }

    /**
     * Public node policy manifest.
     *
     * Unsigned manifests remain backward-compatible self-attestations. Signed
     * manifests add tamper-evidence that strict clients can require before
     * prompt dispatch. The signature is not a complete legal/KYC proof.
     *
     * @return array<string,mixed>|null
     */
    public static function policyManifest(Node $node): ?array
    {
        $manifest = is_array($node->policy_manifest) ? $node->policy_manifest : null;
        if ($manifest === null || $manifest === []) {
            return null;
        }
        $operator = $node->operator_pubkey
            ? Operator::where('operator_pubkey', $node->operator_pubkey)->first()
            : null;
        $verification = NodePolicyManifestVerifier::verify($manifest, [
            'operator_pubkey' => $node->operator_pubkey,
            'operator_verified' => (bool) ($node->operator_verified ?? false),
            'operator_trust_tier' => $node->operator_trust_tier,
            'operator_known' => self::operatorGovernanceAccepted($operator),
        ]);
        $governance = self::operatorGovernanceSignals($operator);

        return [
            'version' => $manifest['version'] ?? null,
            'jurisdiction' => $manifest['jurisdiction'] ?? null,
            'policy_url' => $manifest['policy_url'] ?? null,
            'contact_url' => $manifest['contact_url'] ?? null,
            'remote_executor_can_read_prompt' => $manifest['remote_executor_can_read_prompt'] ?? true,
            'training_use' => $manifest['training_use'] ?? 'provider_defined',
            'retention' => [
                'task_payload' => $manifest['retention']['task_payload'] ?? 'provider_defined',
                'logs_days' => $manifest['retention']['logs_days'] ?? null,
            ],
            'subprocessors' => array_values($manifest['subprocessors'] ?? []),
            'unsupported_intents' => array_values($manifest['unsupported_intents'] ?? []),
            'signed_statement' => $manifest['signed_statement'] ?? null,
            // #602 — safe identity/accountability layer. These are display and
            // routing-policy hints only: signature validity, operator binding and
            // revocation state are not legal/DPA compliance proof.
            'manifest_identity_level' => $verification['manifest_identity_level'],
            'operator_fingerprint' => $verification['operator_fingerprint'],
            'policy_key_fingerprint' => $verification['policy_key_fingerprint'],
            'revoked_at' => $verification['revoked_at'],
            'rotation_epoch' => $verification['rotation_epoch'],
            'revocation_reason_class' => $verification['revocation_reason_class'],
            'operator_governance' => $governance,
            'verification' => [
                'status' => $verification['status'],
                'algorithm' => $verification['algorithm'],
                'key_id' => $verification['key_id'],
                'signed_at' => $verification['signed_at'],
                'expires_at' => $verification['expires_at'],
                'canonical_sha256' => $verification['canonical_sha256'],
                'public_key_sha256' => $verification['public_key_sha256'],
                'error' => $verification['error'],
            ],
            'evidence' => $verification['evidence'],
        ];
    }

    private static function operatorGovernanceAccepted(?Operator $operator): bool
    {
        if ($operator === null) {
            return false;
        }

        $requiredTerms = (string) config('app.iicp_operator_terms_version', '2026-07-09');
        $requiredDpa = (string) config('app.iicp_operator_dpa_version', '2026-07-09');

        return $operator->terms_accepted_at !== null
            && $operator->dpa_accepted_at !== null
            && $operator->terms_version === $requiredTerms
            && $operator->dpa_version === $requiredDpa;
    }

    /** @return array<string,mixed> */
    private static function operatorGovernanceSignals(?Operator $operator): array
    {
        if ($operator === null) {
            return [
                'known_operator' => false,
                'terms_status' => 'missing',
                'dpa_status' => 'missing',
                'evidence' => 'none',
            ];
        }

        $requiredTerms = (string) config('app.iicp_operator_terms_version', '2026-07-09');
        $requiredDpa = (string) config('app.iicp_operator_dpa_version', '2026-07-09');
        $termsCurrent = $operator->terms_accepted_at !== null && $operator->terms_version === $requiredTerms;
        $dpaCurrent = $operator->dpa_accepted_at !== null && $operator->dpa_version === $requiredDpa;

        return array_filter([
            'known_operator' => $termsCurrent && $dpaCurrent,
            'terms_status' => $termsCurrent ? 'current' : ($operator->terms_accepted_at ? 'outdated' : 'missing'),
            'terms_version' => $operator->terms_version,
            'dpa_status' => $dpaCurrent ? 'current' : ($operator->dpa_accepted_at ? 'outdated' : 'missing'),
            'dpa_version' => $operator->dpa_version,
            'acceptance_method' => $operator->acceptance_method,
            'evidence' => ($termsCurrent && $dpaCurrent) ? 'operator_key_challenge_record' : 'incomplete_or_outdated',
        ], fn ($value) => $value !== null);
    }

    /**
     * Task/inference latency and related QoS evidence (#560).
     *
     * These values describe model request performance, not operational
     * reachability. They are kept out of NodeHealthService's liveness score so
     * slow but reachable inference work does not make a node look network-degraded.
     *
     * @return array{performance: array<string,mixed>}
     */
    public static function performanceSignals(Node $node): array
    {
        $proxyObserved = self::positiveFloat($node->reputation?->observed_latency_ms);
        $selfRecent = self::positiveFloat($node->avg_latency_ms_recent);
        $selfLifetime = self::positiveFloat($node->avg_latency_ms);
        $taskLatency = $proxyObserved ?? $selfRecent ?? $selfLifetime;

        return [
            'performance' => [
                'task_latency_ms' => $taskLatency !== null ? (int) round($taskLatency) : null,
                'task_latency_ms_basis' => self::taskLatencyBasis($proxyObserved, $selfRecent, $selfLifetime),
                'proxy_observed_latency_ms' => $proxyObserved !== null ? round($proxyObserved, 2) : null,
                'self_reported_recent_latency_ms' => $selfRecent !== null ? round($selfRecent, 2) : null,
                'self_reported_lifetime_latency_ms' => $selfLifetime !== null ? round($selfLifetime, 2) : null,
                'health_impact' => 'separate_from_operational_health',
                'summary' => 'Task/inference latency is a performance signal, not a reachability-health input.',
            ],
        ];
    }

    private static function positiveFloat(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private static function taskLatencyBasis(?float $proxyObserved, ?float $selfRecent, ?float $selfLifetime): string
    {
        return match (true) {
            $proxyObserved !== null => 'proxy_observed_task',
            $selfRecent !== null => 'self_reported_recent_task',
            $selfLifetime !== null => 'self_reported_lifetime_task',
            default => 'none',
        };
    }

    /**
     * Redacted provider-local backend/model readiness report (#561).
     *
     * This is intentionally separate from directory/node reachability health:
     * - reachability says "can the directory or a client reach the serving surface?"
     * - backend_stability says "is the provider's local model backend ready for new work?"
     *
     * Backward compatibility: nodes that do not report the block are "unknown",
     * not unhealthy. Only explicit `draining` is used as a hard admission guard.
     *
     * @return array<string,mixed>
     */
    public static function backendStability(Node $node): array
    {
        return BackendStabilityPolicy::summarize($node);
    }

    /**
     * Privacy-preserving health digest for list views.
     *
     * The full registry detail endpoint exposes the complete health vector. The
     * list endpoint only needs enough evidence to explain why a routable/keyed
     * node is labelled ready vs. watch without leaking endpoint details.
     */
    public static function healthSummary(?array $health): ?array
    {
        if ($health === null) {
            return null;
        }

        return [
            'score' => $health['score'] ?? null,
            'label' => $health['label'] ?? null,
            'display' => self::healthDisplay($health),
            'confidence' => $health['confidence'] ?? null,
            'evidence_level' => $health['evidence_level'] ?? null,
            'latency_ms_basis' => $health['latency_ms_basis'] ?? null,
            'observed' => (bool) ($health['observed'] ?? false),
            'evaluated_at' => $health['evaluated_at'] ?? null,
            'components' => [
                'reachability' => $health['components']['reachability'] ?? null,
                'latency' => $health['components']['latency'] ?? null,
                'uptime' => $health['components']['uptime'] ?? null,
                'stability' => $health['components']['stability'] ?? null,
            ],
        ];
    }

    /**
     * Public display band for operational health.
     *
     * The raw ADR-044 label remains exposed as `label`. This display helper keeps
     * public list/detail labels from flipping at the 84/85 healthy threshold when
     * the practical signal is simply "usable, evidence still settling". It does
     * not change routing/scoring rules.
     */
    private static function healthDisplay(array $health): array
    {
        $score = isset($health['score']) && is_numeric($health['score'])
            ? (int) $health['score']
            : null;
        $raw = $health['label'] ?? null;

        if ($raw === 'offline') {
            return [
                'label' => 'offline',
                'tone' => 'neutral',
                'raw_label' => $raw,
                'near_threshold' => false,
                'message' => 'No fresh heartbeat evidence is available.',
            ];
        }

        if ($raw === 'critical' || ($score !== null && $score < 40)) {
            return [
                'label' => 'critical',
                'tone' => 'danger',
                'raw_label' => $raw,
                'near_threshold' => false,
                'message' => 'Current operational evidence indicates a real problem.',
            ];
        }

        if ($raw === 'impaired' || ($score !== null && $score < 65)) {
            return [
                'label' => 'impaired',
                'tone' => 'warning',
                'raw_label' => $raw,
                'near_threshold' => false,
                'message' => 'Current operational evidence is weak enough to watch before routing.',
            ];
        }

        if ($score !== null && $score >= 80 && $score < 85) {
            return [
                'label' => 'evidence limited',
                'tone' => 'steady',
                'raw_label' => $raw,
                'near_threshold' => true,
                'message' => 'The node is in the healthy-boundary confidence band; raw score and evidence remain visible.',
            ];
        }

        if ($raw === 'healthy' || ($score !== null && $score >= 85)) {
            return [
                'label' => 'healthy',
                'tone' => 'good',
                'raw_label' => $raw,
                'near_threshold' => false,
                'message' => 'Recent operational evidence supports normal use.',
            ];
        }

        return [
            'label' => 'watch',
            'tone' => 'warning',
            'raw_label' => $raw,
            'near_threshold' => false,
            'message' => 'The node is usable only with caution until more operational evidence improves.',
        ];
    }

    /**
     * Plain-language status summary for public dashboards.  This is not used for
     * scoring; it keeps UI wording honest by separating heartbeat presence,
     * public routability, privacy readiness, client currency, and health.
     */
    public static function statusSummary(Node $node, ?array $health = null): array
    {
        $routing = self::routingSignals($node, $health);
        $compliance = self::complianceSignals($node);
        $backend = self::backendStability($node);
        $trustProgress = self::trustProgress($node);
        $heartbeating = self::isHeartbeating($node);
        $publicRoutable = self::publicRoutable($node);
        $recovery = self::recoverySignals($node, $health);
        $reasons = self::statusSummaryReasons($node, $compliance, $heartbeating, $publicRoutable, $health);
        $posture = self::statusPosture($routing, $compliance, $heartbeating, $publicRoutable, $health);

        return [
            'state' => $posture['state'],
            'headline' => $posture['headline'],
            'description' => $posture['description'],
            'reasons' => $reasons,
            'heartbeating' => $heartbeating,
            'public_routable' => $publicRoutable,
            'browser_usable' => (bool) ($routing['browser_usable'] ?? false),
            'key_ready' => (bool) $compliance['key_ready'],
            'client_current' => $compliance['sdk_status'] === 'current',
            'recovery_state' => $recovery['recovery_state'],
            'route_recovery_hint' => $recovery['route_recovery_hint'],
            'backend_stability' => $backend,
            'trust_progress' => $trustProgress,
            'evidence_last_refreshed_at' => $health['evaluated_at'] ?? $node->last_seen?->toIso8601String(),
            'evidence_source' => $health['evidence_level'] ?? null,
            'health_basis' => $health['latency_ms_basis'] ?? null,
            'evidence_gaps' => self::evidenceGaps($health, $routing, $compliance, $trustProgress),
        ];
    }

    private static function statusSummaryReasons(
        Node $node,
        array $compliance,
        bool $heartbeating,
        bool $publicRoutable,
        ?array $health
    ): array {
        $reasons = [
            $heartbeating ? 'recent_heartbeat' : 'no_recent_heartbeat',
            $publicRoutable
                ? 'public_routable'
                : ($node->endpoint_verified_dead_at !== null ? 'endpoint_confirmed_dead' : 'limited_or_unverified_reach'),
            $compliance['key_ready'] ? 'key_ready' : 'encryption_key_missing',
            $compliance['sdk_status'] === 'current' ? 'client_current' : 'client_upgrade_needed',
        ];

        if (($health['label'] ?? null) !== null) {
            $reasons[] = 'health_'.$health['label'];
        }

        return $reasons;
    }

    private static function statusPosture(
        array $routing,
        array $compliance,
        bool $heartbeating,
        bool $publicRoutable,
        ?array $health
    ): array {
        if (! $heartbeating) {
            return [
                'state' => 'not_seen',
                'headline' => 'Not currently visible to the mesh.',
                'description' => 'The directory has not seen a fresh heartbeat, so this node should not be treated as usable.',
            ];
        }

        if (! $publicRoutable) {
            return [
                'state' => 'limited_reach',
                'headline' => 'Alive, but not public-routable yet.',
                'description' => 'The node is checking in, but normal public discovery cannot rely on reaching its serving endpoint.',
            ];
        }

        if (! $compliance['key_ready']) {
            return [
                'state' => 'upgrade_privacy_needed',
                'headline' => 'Reachable, but privacy upgrade needed.',
                'description' => 'The node can be reached, but it does not advertise an IICP-CX encryption key, so strict clients must avoid plaintext fallback.',
            ];
        }

        if ($compliance['sdk_status'] !== 'current') {
            return [
                'state' => 'upgrade_needed',
                'headline' => 'Reachable, but client upgrade needed.',
                'description' => 'The node is usable, but its client version is behind the current compatibility baseline.',
            ];
        }

        if (($routing['routing_hint'] ?? null) === 'http_ipv6'
            && ($routing['route_evidence'] ?? null) !== 'directory_observed') {
            return [
                'state' => 'direct_unverified',
                'headline' => 'Direct IPv6 route is live, but not directory-verified.',
                'description' => 'The node is checking in and advertises a direct IPv6 route, but this directory cannot currently confirm the endpoint from its own network path.',
            ];
        }

        if (self::healthIsUsableForPublicDisplay($health)) {
            $score = isset($health['score']) && is_numeric($health['score']) ? (int) $health['score'] : null;
            $nearThreshold = $score !== null && $score >= 80 && $score < 85;

            return [
                'state' => 'ready',
                'headline' => $nearThreshold
                    ? 'Usable; health evidence is near the healthy threshold.'
                    : 'Routable, key-ready, and currently healthy.',
                'description' => $nearThreshold
                    ? 'Raw health is still shown, but the public usability label is kept stable inside the healthy-boundary confidence band.'
                    : 'Recent evidence supports normal routing, while reputation maturity still depends on completed task history.',
            ];
        }

        return [
            'state' => 'watch',
            'headline' => 'Usable, but still building evidence.',
            'description' => 'The node is routable and key-ready, but health, latency, uptime, or task-history evidence is still limited or mixed.',
        ];
    }

    private static function healthIsUsableForPublicDisplay(?array $health): bool
    {
        if ($health === null) {
            return false;
        }

        $label = $health['label'] ?? null;
        if (in_array($label, ['offline', 'critical', 'impaired'], true)) {
            return false;
        }

        $score = isset($health['score']) && is_numeric($health['score'])
            ? (int) $health['score']
            : null;

        if ($score === null) {
            return $label === 'healthy';
        }

        // Confidence band: avoid public "ready/watch" flapping around the
        // healthy threshold. Raw score/label are still exposed separately.
        return $score >= 80;
    }

    private static function evidenceGaps(?array $health, array $routing, array $compliance, array $trustProgress): array
    {
        $gaps = [];

        if (($trustProgress['probation'] ?? true) === true) {
            $remaining = (int) ($trustProgress['tasks_until_gold'] ?? 100);
            $completed = (int) ($trustProgress['completed_tasks'] ?? 0);
            $gaps[] = [
                'code' => 'task_history_probation',
                'message' => "{$completed}/100 completed tasks observed; {$remaining} more before Gold can apply.",
            ];
        }

        if (($routing['routing_hint'] ?? null) === 'http_ipv6'
            && ($routing['route_evidence'] ?? null) !== 'directory_observed') {
            $gaps[] = [
                'code' => 'ipv6_not_directory_verified',
                'message' => 'Direct IPv6 endpoint is self-attested; this directory has not independently probed it.',
            ];
        } elseif (($routing['route_evidence'] ?? null) === 'self_attested') {
            $gaps[] = [
                'code' => 'route_self_attested',
                'message' => 'Serving route is based on node/declaration evidence, not a fresh directory probe.',
            ];
        }

        $latencyBasis = $health['latency_ms_basis'] ?? null;
        if ($latencyBasis === 'none') {
            $gaps[] = [
                'code' => 'latency_missing',
                'message' => 'No recent directory-observed route latency evidence is available yet.',
            ];
        } elseif ($latencyBasis === 'self_reported') {
            $gaps[] = [
                'code' => 'latency_self_reported',
                'message' => 'Latency is self-reported task performance; operational route latency evidence is still limited.',
            ];
        }

        foreach (['uptime', 'stability'] as $component) {
            $value = $health['components'][$component] ?? null;
            if ($value === null) {
                $gaps[] = [
                    'code' => "{$component}_evidence_missing",
                    'message' => ucfirst($component).' evidence is not available yet.',
                ];
            } elseif (is_numeric($value) && (float) $value < 0.8) {
                $gaps[] = [
                    'code' => "{$component}_evidence_limited",
                    'message' => ucfirst($component).' evidence is still limited or recently reset.',
                ];
            }
        }

        if (! (bool) ($compliance['key_ready'] ?? false)) {
            $gaps[] = [
                'code' => 'encryption_key_missing',
                'message' => 'Node does not advertise an IICP-CX key; strict clients avoid plaintext fallback.',
            ];
        }

        if (($compliance['sdk_status'] ?? 'unknown') !== 'current') {
            $gaps[] = [
                'code' => 'client_upgrade_needed',
                'message' => 'Client is behind the current compatibility baseline.',
            ];
        }

        if (($health['label'] ?? null) !== null && ($health['label'] ?? null) !== 'healthy') {
            $gaps[] = [
                'code' => 'health_not_healthy',
                'message' => 'Operational health is not yet in the healthy band.',
            ];
        }

        return $gaps;
    }

    public static function isHeartbeating(Node $node): bool
    {
        return (bool) $node->available
            && $node->status === 'active'
            && $node->last_seen !== null
            && $node->last_seen->gte(Carbon::now()->subSeconds(self::EXPIRY_SECONDS));
    }

    public static function publicRoutable(Node $node): bool
    {
        return $node->endpoint_verified_dead_at === null
            && (
                (bool) $node->public_reachable
                || in_array($node->exposure_mode, self::RELAY_REACHABLE_EXPOSURE_MODES, true)
            );
    }

    public static function sdkStatus(?string $version): string
    {
        return (new NodeReadinessPolicy)->sdkStatus($version);
    }

    private static function directoryObservedReachable(?array $health): ?bool
    {
        if ($health === null) {
            return null;
        }

        $evidence = $health['evidence_level'] ?? null;
        $latencyBasis = $health['latency_ms_basis'] ?? null;
        $reachability = $health['components']['reachability'] ?? null;

        if ($evidence === 'directory_observed' || $evidence === 'mixed') {
            return is_numeric($reachability) ? (float) $reachability > 0.0 : null;
        }

        // A directory probe latency exists only for a successful active probe.
        if ($latencyBasis === 'directory_probe') {
            return true;
        }

        return null;
    }

    private static function selfAttestsRoute(Node $node): bool
    {
        return (bool) $node->public_reachable
            || (bool) $node->relay_capable
            || $node->exposure_mode !== null;
    }

    private static function routingHint(Node $node): string
    {
        if ($node->relay_capable) {
            return 'relay_service';
        }

        $endpoint = (string) $node->endpoint;
        $scheme = strtolower((string) (parse_url($endpoint, PHP_URL_SCHEME) ?: ''));
        if ($scheme === 'https') {
            return 'https_direct';
        }
        if ($scheme === 'http') {
            return EndpointAddressFamilyClassifier::hostFamily($endpoint) === 'ipv6' ? 'http_ipv6' : 'http_direct';
        }

        return 'unknown';
    }

    private static function browserUsableEndpoint(?string $endpoint): bool
    {
        if (! $endpoint) {
            return false;
        }

        $scheme = strtolower((string) (parse_url($endpoint, PHP_URL_SCHEME) ?: ''));
        if ($scheme === 'https') {
            return true;
        }
        if ($scheme !== 'http') {
            return false;
        }

        $host = (string) (parse_url($endpoint, PHP_URL_HOST) ?: '');
        $host = strtolower(trim($host, '[]'));

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
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

    /**
     * Additive #548 capability summary. This is descriptive evidence, not a
     * claim that one model is better than another.
     *
     * @param  list<string>  $registeredModels
     * @param  list<string>  $liveModels
     */
    public function capabilitySummary(Node $node, ?array $registeredModels = null, ?array $liveModels = null): array
    {
        return $this->capabilityEvidence->summary($node, $registeredModels, $liveModels);
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
        return EndpointAddressFamilyClassifier::classify($endpoint, $transportEndpoint);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;
use App\Models\Operator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registration lifecycle for the Control Plane — validates, persists, and re-activates nodes.
 *
 * WHY liveness check on register: the directory issues node_token immediately on registration.
 * Issuing a token for a non-reachable node would pollute the active peer list with phantom nodes.
 * RegisterController calls assertLive() before persisting — timeout 5s (LIVENESS_TIMEOUT_S).
 * Liveness check failure returns 503 with IICP-E004 (node unreachable).
 *
 * WHY handle re-registration of active nodes: adapters restart frequently (upgrades, crash
 * recovery). Without re-registration support a restarted adapter would create a duplicate
 * directory entry (new node_id, old entry still showing active). NodeRegistry detects endpoint
 * + region match and reactivates the existing record — preserving reputation history and credit
 * balance — while issuing a fresh node_token.
 *
 * WHY JwtService injection: the JWT (HS256, 1h TTL) is the node_token returned to the operator.
 * NodeRegistry owns the issuance decision; JwtService owns the cryptographic implementation.
 * Injecting allows tests to stub issuance without needing a real app.key.
 *
 * Spec: spec/iicp-dir.md §register. ADR: ADR-006 (node token auth), ADR-021 (virtual nodes).
 */
class NodeRegistry
{
    public function __construct(
        private JwtService $jwt,
        private OperatorDelegationVerifier $delegationVerifier,
        private NodePricingPolicy $pricing,
        private NodeEndpointVerifier $endpointVerifier,
        private NodeRegistrationPersistence $persistence,
    ) {}

    public function register(array $data, string $observedIp = '0.0.0.0'): array
    {
        $isDeclaredReachable = $this->isDeclaredReachable($data);
        if (! $isDeclaredReachable) {
            $this->endpointVerifier->assertReachable($data['endpoint']);
        }
        $publicReachable = $this->computePublicReachable($isDeclaredReachable);

        // Bcrypt hashing is CPU-bound; intentionally outside the DB transaction.
        $plainToken = Str::random(40);
        $hashedToken = password_hash($plainToken, PASSWORD_BCRYPT);
        $plainProxyToken = Str::random(40);
        $hashedProxyToken = password_hash($plainProxyToken, PASSWORD_BCRYPT);
        $hmacKey = (! empty($data['node_hmac_key'])) ? $data['node_hmac_key'] : bin2hex(random_bytes(32));
        // #489 — collect advertised model names so the tier ceiling can be applied in resolvePricingBlock.
        $advertisedModels = [];
        foreach ($data['capabilities'] ?? [] as $cap) {
            foreach ($cap['models'] ?? [] as $m) {
                $advertisedModels[] = (string) $m;
            }
        }
        $pricingAttrs = $this->resolvePricingBlock($data['pricing'] ?? [], $hmacKey, $advertisedModels);

        // #370 — serialisable transaction prevents race conditions on concurrent re-registrations.
        // 3 automatic retries on deadlock (MySQL error 1213 / SQLSTATE 40001).
        return DB::transaction(function () use (
            $data, $observedIp,
            $plainToken, $hashedToken, $plainProxyToken, $hashedProxyToken,
            $hmacKey, $pricingAttrs, $publicReachable
        ) {
            [$node, $recovered] = $this->persistence->persist(
                $data, $observedIp, $hashedToken, $hashedProxyToken, $hmacKey, $pricingAttrs, $publicReachable
            );
            $this->bindCapabilitiesAndAvailability($node, $data, $recovered);
            $this->bindOperatorIdentity($node, $data);

            return [
                'node_id' => $node->id,
                'node_token' => $plainToken,
                'proxy_token' => $plainProxyToken,
                'node_hmac_key' => $hmacKey,
                'expires_at' => null,
                'jwt_token' => $this->jwt->issueNode($node->id),
                'jwt_expires_at' => now()->addSeconds(3600)->toIso8601String(),
                'directory' => config('app.url'),
                'observed_source_ip' => $observedIp,
                'recovered' => $recovered,
                'lifetime_jobs' => $node->lifetime_jobs ?? 0,
            ];
        }, 3);
    }

    /** Replace capabilities + availability on recovery; append on fresh registration. */
    private function bindCapabilitiesAndAvailability(Node $node, array $data, bool $recovered): void
    {
        if ($recovered) {
            $node->capabilities()->delete();
            $node->availabilityWindows()->delete();
        }

        foreach ($data['capabilities'] as $cap) {
            $node->capabilities()->create([
                'intent' => $cap['intent'],
                'variant_id' => $cap['variant_id'] ?? null,
                'models' => $cap['models'] ?? [],
                'max_tokens' => $cap['max_tokens'] ?? 0,
                'quantization' => $cap['quantization'] ?? null,
                'inference_engine' => $cap['inference_engine'] ?? null,
                // #408/ADR-046 — default text-only for pre-0.7.33 nodes.
                'input_modalities' => $cap['input_modalities'] ?? ['text'],
                'output_modalities' => $cap['output_modalities'] ?? null,
                'features' => $cap['features'] ?? null,
                'execution_capabilities' => $cap['execution_capabilities'] ?? null,
                'capability_limits' => $cap['limits'] ?? null,
                'supported_profiles' => $cap['supported_profiles'] ?? [],
                'claim_provenance' => $cap['claim_provenance'] ?? null,
                'extensions' => $cap['extensions'] ?? null,
            ]);
        }

        if (! empty($data['availability'])) {
            foreach ($data['availability'] as $window) {
                $node->availabilityWindows()->create([
                    'start_time' => $window['start'],
                    'end_time' => $window['end'],
                    'share' => $window['share'] ?? 1.0,
                ]);
            }
        }
    }

    /**
     * ADR-045 Phase A (#407) — bind a verified operator identity when a valid ed25519
     * delegation is presented. Lenient: invalid/absent leaves the node unverified.
     */
    private function bindOperatorIdentity(Node $node, array $data): void
    {
        if (empty($data['operator_delegation'])) {
            return;
        }

        $del = $data['operator_delegation'];
        [$ok] = $this->delegationVerifier->verify(
            $del,
            (string) $node->id,
            [$del['operator_pub'] ?? ''], // self-asserted; did:web trust-set layers on later (OPEN-2)
        );
        $existingOperator = $ok ? Operator::where('operator_pubkey', $del['operator_pub'])->first() : null;
        if ($existingOperator !== null && ($existingOperator->identity_status ?? Operator::IDENTITY_ACTIVE) !== Operator::IDENTITY_ACTIVE) {
            throw ValidationException::withMessages([
                'operator_delegation' => 'operator identity is rotated or revoked and cannot make new delegation claims (IICP-E063)',
            ]);
        }
        $node->update($ok ? [
            'operator_pubkey' => $del['operator_pub'],
            'operator_verified' => true,
            'operator_trust_tier' => 'did_key',
        ] : ['operator_verified' => false]);

        if ($ok) {
            $this->upsertOperator($del['operator_pub'], $data);
        }
    }

    public function validateToken(string $nodeId, string $plainToken): ?Node
    {
        $node = Node::find($nodeId);

        if (! $node) {
            return null;
        }

        if (! password_verify($plainToken, $node->node_token_hash)) {
            return null;
        }

        return $node;
    }

    public function validateProxyToken(string $nodeId, string $plainToken): ?Node
    {
        $node = Node::find($nodeId);

        if (! $node || ! $node->proxy_token_hash) {
            return null;
        }

        if (! password_verify($plainToken, $node->proxy_token_hash)) {
            return null;
        }

        return $node;
    }

    /**
     * Resolve and validate an ADR-019 pricing block.
     * If declaration_signature is present, verify HMAC-SHA256 against $hmacKey (IICP-E010).
     * $advertisedModels allows a compute-anchored ceiling check (#489): a 0.5B model
     * cannot claim a higher multiplier than its tier ceiling (tier_weight × 3).
     *
     * @param  array<string>  $advertisedModels  Model names from capabilities (e.g. ["qwen2.5:0.5b"])
     */
    public function resolvePricingBlock(array $pricing, string $hmacKey, array $advertisedModels = []): array
    {
        return $this->pricing->resolve($pricing, $hmacKey, $advertisedModels);
    }

    /**
     * Compute-anchored tier weights — 1 credit ≈ 1000 tokens at 7B reference cost.
     * Matches the display values in StatsController (single source of truth here).
     *
     * @internal
     */
    /**
     * Classify a set of model names to the highest applicable compute tier.
     * Parses patterns like "7b", "7.5B", "0.5b", "llama3.1:8b", "qwen2.5:0.5b".
     * Returns '7b' (reference tier) when no parameter count is detectable.
     *
     * @param  array<string>  $models
     */
    public static function classifyModelTier(array $models): string
    {
        return (new NodePricingPolicy)->classifyModelTier($models);
    }

    /**
     * #326 — does the registration declare a NATted node?
     * Mirrors the binary model from #334 (RoutableEndpoint validator):
     * nat_type non-empty + non-'none' AND transport_method non-empty + non-'direct'.
     */
    /**
     * Does the operator's register payload contain a self-declared reachability
     * statement we can trust? Used to short-circuit the directory's dial-back
     * probe (the directory can't validate from behind NAT anyway).
     *
     * #346/2026-05-27: previously named `isNattedDeclaration` and rejected
     * `transport_method='direct'`. That excluded the strongest case — operator
     * declares "my endpoint is directly routable" (Tier 0 v4/v6). Treating
     * 'direct' as untrusted forced these nodes through the (skipped) probe
     * and into `public_reachable=false`. Now: any non-empty, non-'none'
     * combination of nat_type + transport_method counts as a declaration.
     * RoutableEndpoint already rejects LAN / CGNAT / link-local / reserved
     * ranges at validation time, so we don't risk admitting unreachable hosts.
     */
    private function isDeclaredReachable(array $data): bool
    {
        $natType = $data['nat_type'] ?? null;
        $transportMethod = $data['transport_method'] ?? null;

        // RT-04 (#378): 'unknown' is the ABSENCE of a topology assertion, not a
        // trustworthy one. A node that does not know its NAT type must NOT be
        // granted public_reachable on declaration alone — it falls through to
        // assertLive() (probe). Only a concrete topology the directory cannot
        // probe from outside (UPnP/STUN/TURN/external_tunnel) or a direct
        // declaration (RoutableEndpoint already enforces a public host) bypasses
        // the probe. Without this, an attacker registers nat_type=unknown +
        // transport_method=direct and is marked publicly reachable with zero
        // verification (phantom-node flood).
        return is_string($natType) && $natType !== '' && $natType !== 'none' && $natType !== 'unknown'
            && is_string($transportMethod) && $transportMethod !== '';
    }

    /** Back-compat shim — older call sites use the old name. */
    private function isNattedDeclaration(array $data): bool
    {
        return $this->isDeclaredReachable($data);
    }

    /**
     * #463/#310/#464 — upsert the operator-identity record for a verified operator_pubkey
     * (== operator_id). display_name is public + mutable (a delegated re-register proves
     * key-control, so it may change it); contact is never accepted here. On FIRST register,
     * pin operator_integrity_hash + the directory-observed first_seen_ms (authoritative for
     * founder ordinals — never the backdatable created_at). A later register presenting a
     * different integrity hash means an edited created_at/operator_id → logged, pin kept
     * (fail-safe; never abort the node's registration over an identity edge case).
     */
    private function upsertOperator(string $operatorPub, array $data): void
    {
        $displayName = $data['operator_display_name'] ?? null;
        $normalizedDisplayName = $this->normalizeOperatorDisplayName($displayName);
        $existing = Operator::where('operator_pubkey', $operatorPub)->first();

        if ($normalizedDisplayName !== null) {
            $collision = Operator::query()
                ->whereNot('operator_pubkey', $operatorPub)
                ->where('identity_status', Operator::IDENTITY_ACTIVE)
                ->whereNotNull('display_name')
                ->get(['operator_pubkey', 'display_name'])
                ->first(fn (Operator $op) => $this->normalizeOperatorDisplayName($op->display_name) === $normalizedDisplayName);

            if ($collision !== null) {
                throw ValidationException::withMessages([
                    'operator_display_name' => 'operator_display_name is already claimed by another verified operator (IICP-E051)',
                ]);
            }
        }

        if ($existing === null) {
            Operator::create([
                'operator_pubkey' => $operatorPub,
                'display_name' => $displayName,
                'attested_created_at' => $data['operator_created_at'] ?? null,
                'operator_integrity_hash' => $data['operator_integrity_hash'] ?? null,
                'first_seen_ms' => (int) (microtime(true) * 1000),
            ]);

            return;
        }

        $presented = $data['operator_integrity_hash'] ?? null;
        if ($presented !== null && $existing->operator_integrity_hash !== null
            && $presented !== $existing->operator_integrity_hash) {
            Log::warning('iicp.operator.integrity_mismatch', [
                'operator_pubkey' => substr($operatorPub, 0, 12).'…',
            ]);
        }
        if ($displayName !== null) {
            $existing->update(['display_name' => $displayName]);
        }
    }

    private function normalizeOperatorDisplayName(?string $displayName): ?string
    {
        if ($displayName === null) {
            return null;
        }
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $displayName) ?? $displayName));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * #326 / #346 — set the public_reachable flag at registration time.
     *
     *  - Declared-reachable: operator's nat_type + transport_method block →
     *    trust → public_reachable=true. Covers Tier 0 (direct v4/v6) AND
     *    Tier 1+ (UPnP / STUN / TURN / external_tunnel). Layer 3 cron
     *    re-verifies periodically.
     *  - Dev env (local/testing): operator is iterating against their own
     *    mesh. Default true so include_internal=true is not required.
     *  - IICP_SKIP_LIVENESS_CHECK: alpha-trust mode. assertLive returned
     *    early without probing. RoutableEndpoint already validated the
     *    endpoint as publicly routable (LAN / CGNAT / reserved are
     *    rejected at validation), so the endpoint is at least *claimably*
     *    reachable. Default true — previously false, which silently put
     *    every legitimate-but-unverified node into internal_nodes and
     *    drove `Active nodes: 0` despite a population of healthy peers.
     *  - Else: assertLive succeeded → directly probed reachable.
     */
    private function computePublicReachable(bool $isDeclaredReachable): bool
    {
        if ($isDeclaredReachable) {
            return true;
        }
        $env = config('app.env');
        if ($env === 'local' || $env === 'testing') {
            return true;
        }

        // Whether skip-mode trusted the operator or assertLive verified the
        // probe, the endpoint passed RoutableEndpoint validation. Treat as
        // public. The cron-based reverifier (NodeLifecycleCommand) flips
        // this back to false on sustained dial-back failures.
        return true;
    }

    /**
     * IICP-E050 (F2): is the node's PREVIOUS endpoint still reachable?
     *
     * Returns true only if a `GET {endpoint}/iicp/health` succeeds. A failure
     * (connection refused, timeout, non-2xx) means the old endpoint is gone —
     * the legitimate-rotation signal that lets a token-less re-registration
     * move the endpoint. When liveness checks are explicitly skipped (the dev/
     * test skip flag), we treat the old endpoint as GONE (return false) so the
     * E050 guard does not gate token-less rotation in environments that have
     * deliberately opted out of liveness probing; production never sets the
     * flag, so the real probe always runs there.
     */
    public function isEndpointAlive(string $endpoint): bool
    {
        return $this->endpointVerifier->isAlive($endpoint);
    }
}

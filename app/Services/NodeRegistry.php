<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
    ) {}

    private const LIVENESS_TIMEOUT_S = 5;

    public function register(array $data, string $observedIp = '0.0.0.0'): array
    {
        // #326 + #334 — public_reachable flag controls default /v1/discover
        // visibility. The probe model:
        //   - NATted node (operator declared traversal): skip dial-back probe
        //     (directory can't reach behind NAT); trust the operator's claim;
        //     mark public_reachable=true. Layer 3 cron re-verifies periodically.
        //   - NOT-NATted: run the assertLive probe; success → public_reachable=true.
        //     Failure rejects the registration (existing behavior preserved).
        //   - Dev env (local/testing) or IICP_SKIP_LIVENESS_CHECK: probe is no-op;
        //     public_reachable defaults to false (operator can use include_internal).
        $isDeclaredReachable = $this->isDeclaredReachable($data);
        if (! $isDeclaredReachable) {
            $this->assertLive($data['endpoint']);
        }
        $publicReachable = $this->computePublicReachable($isDeclaredReachable);

        $plainToken = Str::random(40);
        $hashedToken = password_hash($plainToken, PASSWORD_BCRYPT);
        $plainProxyToken = Str::random(40);
        $hashedProxyToken = password_hash($plainProxyToken, PASSWORD_BCRYPT);
        // Operator may supply its own HMAC key (for pricing declaration signing); otherwise generate
        $hmacKey = (! empty($data['node_hmac_key'])) ? $data['node_hmac_key'] : bin2hex(random_bytes(32));

        // ADR-019 pricing block — verify declaration_signature if present (IICP-E010)
        $pricingAttrs = $this->resolvePricingBlock($data['pricing'] ?? [], $hmacKey);

        // Identity recovery: if node_id is provided, find any existing record (any status).
        // Active-node re-registration happens when the adapter restarts while still within the
        // heartbeat window — issue new tokens and restore to active without creating a duplicate.
        // Dormant/archived recovery preserves earned reputation for part-time providers.
        //
        // #370 — wrap the DB read-write section in a serialisable transaction with
        // lockForUpdate() on re-registration lookups. The bcrypt hashing above is
        // intentionally outside the transaction (it's CPU-bound and must not hold
        // a DB lock while running). The transaction covers the Node read → write →
        // capabilities delete+create sequence, preventing the race that caused
        // intermittent 500s under near-simultaneous registrations.
        $existingNodeId = $data['node_id'] ?? null;

        return DB::transaction(function () use (
            $data, $observedIp, $existingNodeId,
            $plainToken, $hashedToken, $plainProxyToken, $hashedProxyToken,
            $hmacKey, $pricingAttrs, $publicReachable
        ) {
            $recovered = false;
            $node = null;

            if ($existingNodeId) {
                // lockForUpdate ensures concurrent re-registrations for the same node_id
                // queue at the DB level rather than racing on the read-then-write sequence.
                $node = Node::where('id', $existingNodeId)->lockForUpdate()->first();

                if ($node) {
                    // RT-6-1 (#390): cx_public_key change requires ownership proof.
                    // A caller supplying a cx_public_key that differs from the stored value
                    // must also supply current_node_token validated against node_token_hash.
                    // This prevents unauthenticated key-substitution attacks on re-registration.
                    $incomingCxKey = $data['cx_public_key'] ?? null;
                    $storedCxKey = $node->cx_public_key;
                    if ($incomingCxKey !== null && $incomingCxKey !== $storedCxKey) {
                        $currentToken = $data['current_node_token'] ?? null;
                        if (! $currentToken || ! password_verify($currentToken, $node->node_token_hash)) {
                            throw new \InvalidArgumentException('IICP-E049: cx_public_key update requires valid current_node_token');
                        }
                    }

                    $node->update([
                        'endpoint' => $data['endpoint'],
                        'transport_endpoint' => $data['transport_endpoint'] ?? $node->transport_endpoint,
                        'region' => $data['region'],
                        'node_token_hash' => $hashedToken,
                        'proxy_token_hash' => $hashedProxyToken,
                        'node_hmac_key' => $hmacKey,
                        'max_concurrent' => $data['limits']['max_concurrent'],
                        'tokens_per_min' => $data['limits']['tokens_per_min'],
                        'available' => true,
                        'status' => 'active',
                        'dormant_since' => null,
                        'public_listing' => (bool) ($data['listing']['public_listing'] ?? false),
                        'operator_url' => $data['listing']['operator_url'] ?? null,
                        'operator_contact' => $data['listing']['operator_contact'] ?? null,
                        'last_seen' => now(),
                        'observed_source_ip' => $observedIp,
                        // #331 Phase A.1 / ADR-041 — re-register can update NAT details (env may have changed)
                        'transport_method' => $data['transport_method'] ?? $node->transport_method,
                        'nat_type' => $data['nat_type'] ?? $node->nat_type,
                        'transport_metadata' => $data['transport_metadata'] ?? $node->transport_metadata,
                        // #326 — re-register refreshes the public_reachable flag
                        'public_reachable' => $publicReachable,
                        // SDK identification (free-form for future languages)
                        'sdk_language' => $data['sdk_language'] ?? $node->sdk_language,
                        'sdk_version' => $data['sdk_version'] ?? $node->sdk_version,
                        // Detected backend server flavor (ollama/lmstudio/vllm/...).
                        'backend' => $data['backend'] ?? $node->backend,
                        // ADR-043 §9 — exposure_mode if re-registering with updated qualification
                        'exposure_mode' => $data['exposure_mode'] ?? $node->exposure_mode,
                        // IICP-CX S.16 §3.1 — ownership-gated cx_public_key update (RT-6-1 #390)
                        'cx_public_key' => $incomingCxKey ?? $storedCxKey,
                    ]);
                    $recovered = true;
                }
            }

            $nodeId = $existingNodeId ?? (string) Str::uuid();

            if (! $node) {
                // #370 — UniqueConstraintViolationException can occur if two concurrent
                // requests race on a node_id that was not found by lockForUpdate() (e.g.
                // a fresh UUID collision, which is astronomically rare, or a narrow window
                // where the SELECT ran before the INSERT committed). Treat as re-registration.
                try {
                    $node = Node::create([
                        'id' => $nodeId,
                        'endpoint' => $data['endpoint'],
                        'transport_endpoint' => $data['transport_endpoint'] ?? null,
                        'region' => $data['region'],
                        'node_token_hash' => $hashedToken,
                        'proxy_token_hash' => $hashedProxyToken,
                        'node_hmac_key' => $hmacKey,
                        'max_concurrent' => $data['limits']['max_concurrent'],
                        'tokens_per_min' => $data['limits']['tokens_per_min'],
                        'available' => true,
                        'relay_capable' => (bool) ($data['relay_capable'] ?? false),
                        // #331 Phase A.1 / ADR-041 — NAT-traversal observability (all nullable)
                        'transport_method' => $data['transport_method'] ?? null,
                        'nat_type' => $data['nat_type'] ?? null,
                        'transport_metadata' => $data['transport_metadata'] ?? null,
                        // #326 — fresh registration sets the flag per probe outcome
                        'public_reachable' => $publicReachable,
                        // SDK identification (free-form for future languages)
                        'sdk_language' => $data['sdk_language'] ?? null,
                        'sdk_version' => $data['sdk_version'] ?? null,
                        // Detected backend server flavor (ollama/lmstudio/vllm/...).
                        'backend' => $data['backend'] ?? null,
                        // ADR-043 §9 — 8-category network exposure classification
                        'exposure_mode' => $data['exposure_mode'] ?? null,
                        // IICP-CX S.16 §3.1 — X25519 public key advertisement (#360)
                        'cx_public_key' => $data['cx_public_key'] ?? null,
                        'status' => 'active',
                        'dormant_since' => null,
                        'identity_key' => hash('sha256', $nodeId),
                        'lifetime_jobs' => 0,
                        // CIP-D1: Provider opt-in policy block (spec S.12 §2.1) — all default false
                        'allow_remote_inference' => (bool) ($data['policy']['allow_remote_inference'] ?? false),
                        'allow_tool_execution' => (bool) ($data['policy']['allow_tool_execution'] ?? false),
                        'allow_file_access' => (bool) ($data['policy']['allow_file_access'] ?? false),
                        'pricing_credits_per_1000' => isset($data['policy']['pricing_credits_per_1000'])
                            ? (float) $data['policy']['pricing_credits_per_1000']
                            : null,
                        // ADR-019 declarative pricing
                        'credit_cost_multiplier' => $pricingAttrs['credit_cost_multiplier'],
                        'pricing_model' => $pricingAttrs['pricing_model'],
                        'declaration_signature' => $pricingAttrs['declaration_signature'],
                        'attested' => $pricingAttrs['attested'],
                        'pricing_effective_from' => $pricingAttrs['effective_from'],
                        'pricing_effective_until' => $pricingAttrs['effective_until'],
                        // ADR-017: Operator opt-in public listing
                        'public_listing' => (bool) ($data['listing']['public_listing'] ?? false),
                        'operator_url' => $data['listing']['operator_url'] ?? null,
                        'operator_contact' => $data['listing']['operator_contact'] ?? null,
                        'last_seen' => now(),
                        'observed_source_ip' => $observedIp,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Race: another request created this node_id between our SELECT and INSERT.
                    // Treat as re-registration — reload with a lock and update tokens.
                    $node = Node::where('id', $nodeId)->lockForUpdate()->firstOrFail();
                    $node->update([
                        'node_token_hash' => $hashedToken,
                        'proxy_token_hash' => $hashedProxyToken,
                        'node_hmac_key' => $hmacKey,
                        'available' => true,
                        'status' => 'active',
                        'last_seen' => now(),
                        'observed_source_ip' => $observedIp,
                        'public_reachable' => $publicReachable,
                    ]);
                    $recovered = true;
                }
            }

            // On recovery the provider declares new capabilities — replace existing.
            // On fresh registration the node has no prior capabilities.
            if ($recovered) {
                $node->capabilities()->delete();
                $node->availabilityWindows()->delete();
            }

            foreach ($data['capabilities'] as $cap) {
                $node->capabilities()->create([
                    'intent' => $cap['intent'],
                    'models' => $cap['models'],
                    'max_tokens' => $cap['max_tokens'],
                    'quantization' => $cap['quantization'] ?? null,
                    'inference_engine' => $cap['inference_engine'] ?? null,
                    // #408/ADR-046 — input modalities; default text-only when the
                    // SDK doesn't declare it (back-compatible with pre-0.7.33 nodes).
                    'input_modalities' => $cap['input_modalities'] ?? ['text'],
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

            // ADR-045 Phase A (#407) — bind a verified operator identity when a valid
            // ed25519 delegation is presented. Lenient: a valid delegation → verified
            // binding (did_key tier, self-asserted); an invalid/absent one leaves the
            // node unverified (no false binding is possible without the operator's
            // signature, so this fails safe without aborting the registration).
            if (! empty($data['operator_delegation'])) {
                $del = $data['operator_delegation'];
                [$ok] = $this->delegationVerifier->verify(
                    $del,
                    (string) $node->id,
                    [$del['operator_pub'] ?? ''], // self-asserted; did:web trust-set layers on later (OPEN-2)
                );
                $node->update($ok ? [
                    'operator_pubkey' => $del['operator_pub'],
                    'operator_verified' => true,
                    'operator_trust_tier' => 'did_key',
                ] : ['operator_verified' => false]);
            }

            return [
                'node_id' => $node->id,
                'node_token' => $plainToken,
                'proxy_token' => $plainProxyToken,
                'node_hmac_key' => $hmacKey,
                'expires_at' => null,
                'jwt_token' => $this->jwt->issue($node->id),
                'jwt_expires_at' => now()->addSeconds(3600)->toIso8601String(),
                'directory' => config('app.url'),
                'observed_source_ip' => $observedIp,
                'recovered' => $recovered,
                'lifetime_jobs' => $node->lifetime_jobs ?? 0,
            ];

            // 3 automatic retries on deadlock (MySQL error 1213 / SQLSTATE 40001).
            // The transaction closure above is the full DB section of register().
        }, 3);
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
     */
    public function resolvePricingBlock(array $pricing, string $hmacKey): array
    {
        $multiplier = isset($pricing['credit_cost_multiplier'])
            ? (float) $pricing['credit_cost_multiplier']
            : 1.0;
        $model = $pricing['pricing_model'] ?? 'per_token';
        $sig = $pricing['declaration_signature'] ?? null;
        $attested = false;

        if ($sig !== null) {
            // Body is the pricing block WITHOUT declaration_signature, keys sorted
            $body = ['credit_cost_multiplier' => $multiplier, 'pricing_model' => $model];
            ksort($body);
            $expected = hash_hmac('sha256', json_encode($body, JSON_THROW_ON_ERROR), $hmacKey);
            if (! hash_equals($expected, $sig)) {
                throw ValidationException::withMessages([
                    'pricing.declaration_signature' => 'Invalid declaration signature (IICP-E010)',
                ]);
            }
            $attested = true;
        }

        return [
            'credit_cost_multiplier' => $multiplier,
            'pricing_model' => $model,
            'declaration_signature' => $sig,
            'attested' => $attested,
            'effective_from' => isset($pricing['effective_from']) ? $pricing['effective_from'] : null,
            'effective_until' => isset($pricing['effective_until']) ? $pricing['effective_until'] : null,
        ];
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

    private function assertLive(string $endpoint): void
    {
        if (env('IICP_SKIP_LIVENESS_CHECK', false)) {
            return;
        }

        try {
            $response = Http::timeout(self::LIVENESS_TIMEOUT_S)
                ->withoutVerifying()
                ->get($endpoint.'/iicp/health');

            if ($response->failed()) {
                // #331 Phase B / IICP-E036: directory-side dial-back failed.
                throw ValidationException::withMessages([
                    'endpoint' => 'IICP-E036: endpoint unreachable from directory (HTTP '
                        .$response->status().'). Verify port-forwarding / public_endpoint.',
                ]);
            }
        } catch (ConnectionException $e) {
            throw ValidationException::withMessages([
                'endpoint' => 'IICP-E036: endpoint unreachable from directory (cannot reach '
                    .$endpoint.'). Verify port-forwarding / public_endpoint.',
            ]);
        }
    }
}

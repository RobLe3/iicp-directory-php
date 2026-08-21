<?php

// SPDX-License-Identifier: Apache-2.0

/**
 * POST /api/v1/register — open node registration entry point.
 *
 * Validates a candidate node's capability envelope (endpoint, region, intent URNs,
 * model list, concurrency / token limits, optional availability windows) and hands
 * the validated payload to {@see NodeRegistry} for persistence. On success the
 * Control plane issues a node_token in the response body; the operator stores it
 * in iicp-node config and presents it on subsequent heartbeats and authenticated
 * calls.
 *
 * Implements:
 *   - ADR-006 — open registration (no pre-shared key required)
 *   - ADR-009 — IICP-DIR sub-protocol contract (see spec/iicp-dir.md §register)
 *   - DIR-ADDR-06 — observed-IP recording on every register event
 *
 * Trust posture: directory is semi-trusted, nodes are UNTRUSTED (ADR-001). The
 * controller never trusts client-supplied node_id beyond uniqueness; the
 * authoritative identifier is the row id returned by the registry.
 */

namespace App\Http\Controllers;

use App\Models\Node;
use App\Rules\RoutableEndpoint;
use App\Services\CreditService;
use App\Services\IntentPolicyGuard;
use App\Services\NodeAddressObserver;
use App\Services\NodeEventLogger;
use App\Services\NodePolicyManifestVerifier;
use App\Services\NodeRegistry;
use App\Services\OtelTracer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    private const CAPABILITY_LIMIT_UNITS = ['tokens', 'items', 'bytes', 'milliseconds', 'dimensions'];

    /**
     * Intent URN format (ADR-007 + iter-245 / issue #244):
     *   Standard:  urn:iicp:intent:<domain>:<verb>:v<major>
     *   Custom:    urn:iicp:intent:x.<vendor>:<verb>:v<major>   (vendor-controlled, no registry approval)
     *
     * Enforced here because invalid URNs become un-discoverable rows that pollute the
     * score table. Custom x.<vendor> URNs were added in iter-245 to let vendors ship
     * private intents without protocol-registry round-trips; the directory MUST accept
     * them (#244).
     */
    private const INTENT_PATTERN = '/^urn:iicp:intent:(?:[a-z][a-z0-9_]*|x\.[a-z][a-z0-9-]*):[a-z][a-z0-9_-]*:v[1-9][0-9]*$/';

    private function sortCapability(array $value): array
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item) && ! array_is_list($item)) {
                $item = $this->sortCapability($item);
            }
        }

        return $value;
    }

    private function validateEffectiveCapabilityMaps(array $capability, int $index): void
    {
        foreach (($capability['limits'] ?? []) as $name => $limit) {
            if (! $this->isValidCapabilityLimit($name, $limit)) {
                throw ValidationException::withMessages(["capabilities.$index.limits" => ['Invalid typed capability limit.']]);
            }
        }
        foreach (($capability['extensions'] ?? []) as $name => $extension) {
            if (! $this->isValidCapabilityExtension($name, $extension)) {
                throw ValidationException::withMessages(["capabilities.$index.extensions" => ['Invalid namespaced capability extension.']]);
            }
        }
    }

    private function isValidCapabilityLimit(mixed $name, mixed $limit): bool
    {
        return is_string($name)
            && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) === 1
            && is_array($limit)
            && count($limit) === 2
            && array_key_exists('value', $limit)
            && array_key_exists('unit', $limit)
            && is_numeric($limit['value'])
            && $limit['value'] >= 0
            && in_array($limit['unit'], self::CAPABILITY_LIMIT_UNITS, true);
    }

    private function isValidCapabilityExtension(mixed $name, mixed $extension): bool
    {
        return is_string($name)
            && preg_match('/^[a-z0-9][a-z0-9._:-]{2,127}$/', $name) === 1
            && str_contains($name, '.')
            && is_array($extension)
            && array_key_exists('required', $extension)
            && is_bool($extension['required'])
            && array_key_exists('value', $extension)
            && count($extension) === 2;
    }

    /**
     * Region character set — safe alphanumeric-plus-separator format.
     *
     * Regions are free-form per iicp-core.md §2.1 (unknown values MUST NOT be
     * rejected). This regex is character-safety enforcement, not a whitelist:
     * it prevents injection characters (< > / \ " ; null-bytes) from reaching
     * downstream log renderers or HTML contexts while allowing any realistic
     * geographic identifier (eu-central, us-east-1, my_custom_region, etc.).
     *
     * W-033 mitigation (iter-591).
     */
    private const REGION_PATTERN = '/^[a-zA-Z][a-zA-Z0-9\-_]*$/';

    /**
     * Maximum registrations per source IP per window (W-033, #346).
     *
     * Raised from 10/900s (2026-05-27, #346) — the prior cap was crippling
     * operator iteration during 0.5.6 SDK rollout, and the deregister path
     * (iter-1471 Python / iter-1474 TS / iter-1475 Rust) is now reliable,
     * so re-register-after-deregister is the normal flow rather than an
     * abuse vector.
     */
    private const REGISTER_RATE_LIMIT = 60;

    private const REGISTER_RATE_TTL = 60; // 1 minute

    public function __construct(
        private NodeRegistry $registry,
        private NodeAddressObserver $addressObserver,
        private NodeEventLogger $eventLogger,
        private CreditService $credits,
        private IntentPolicyGuard $intentPolicy,
    ) {}

    /**
     * Register (or re-register) a node and persist the observed source IP.
     *
     * Side effects:
     *   - Inserts/updates a row in `nodes` plus capability + availability rows
     *   - Writes an entry to `node_address_observations` (DIR-ADDR-06)
     *   - Returns 201 with a freshly minted node_token; callers must store it
     *
     * The address observation happens AFTER the registry call so a validation
     * failure does not leave dangling history rows.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if ($rateLimited = $this->rateLimitResponse($request)) {
            return $rateLimited;
        }

        $span = OtelTracer::startSpan($request, 'iicp.directory.register');

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:255', new RoutableEndpoint(['http', 'https'])],
            // spec v0.7.0 — native IICP binary endpoint (ADR-040); separate from HTTP control plane.
            // `url:iicp,iicpsec` overrides Laravel's default http/https-only protocol list.
            'transport_endpoint' => ['sometimes', 'nullable', 'string', 'url:iicp,iicpsec', 'max:255', new RoutableEndpoint(['iicp', 'iicpsec'])],
            // Character-safety check — not a semantic whitelist (unknown regions still accepted).
            'region' => ['required', 'string', 'max:64', 'regex:'.self::REGION_PATTERN],
            // #346 — accept both UUIDs (Laravel-style) and SDK-default
            // identifiers like `sdk-qwen2.5-0.5b-12345678`. The character
            // class matches the SDK identity name validator; length cap
            // 36 matches the underlying `uuid('id')` CHAR(36) column on
            // the `nodes` table.
            'node_id' => ['sometimes', 'string', 'max:36', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/'],
            'current_node_token' => ['sometimes', 'nullable', 'string', 'max:64'],
            'public_key' => ['sometimes', 'nullable', 'string'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*.intent' => ['required', 'string', 'regex:'.self::INTENT_PATTERN],
            'capabilities.*.version' => ['sometimes', 'string', 'min:1', 'max:32'],
            'capabilities.*.phase' => ['sometimes', 'integer', 'min:1'],
            'capabilities.*.variant_id' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'capabilities.*.models' => ['sometimes', 'array', 'max:64'],
            'capabilities.*.models.*' => ['string', 'max:255', 'distinct'],
            'capabilities.*.max_tokens' => ['sometimes', 'integer', 'min:1'],
            // Advisory fields per iicp-core.md §2.1 v1.2.4 (#118) — nullable, no enum restriction
            'capabilities.*.quantization' => ['sometimes', 'nullable', 'string', 'max:32'],
            'capabilities.*.inference_engine' => ['sometimes', 'nullable', 'string', 'max:32'],
            // #408/ADR-046 — input modalities a capability accepts (default text-only).
            'capabilities.*.input_modalities' => ['sometimes', 'nullable', 'array'],
            'capabilities.*.input_modalities.*' => ['string', 'in:text,image,audio,video'],
            'capabilities.*.output_modalities' => ['sometimes', 'array', 'max:16'],
            'capabilities.*.output_modalities.*' => ['string', 'in:text,image,audio,video', 'distinct'],
            'capabilities.*.features' => ['sometimes', 'array', 'max:64'],
            'capabilities.*.features.*' => ['string', 'max:255', 'distinct'],
            'capabilities.*.execution_capabilities' => ['sometimes', 'array', 'max:64'],
            'capabilities.*.execution_capabilities.*' => ['string', 'max:255', 'distinct'],
            'capabilities.*.limits' => ['sometimes', 'array', 'max:32'],
            'capabilities.*.claim_provenance' => ['sometimes', 'array'],
            'capabilities.*.claim_provenance.source' => ['required_with:capabilities.*.claim_provenance', 'string', 'in:heuristic_fallback,operator_assertion,provider_metadata,runtime_introspection,conformance_probe'],
            'capabilities.*.claim_provenance.observed_at' => ['sometimes', 'date'],
            'capabilities.*.claim_provenance.valid_until' => ['sometimes', 'date'],
            'capabilities.*.claim_provenance.evidence_ref' => ['sometimes', 'string', 'max:255'],
            'capabilities.*.extensions' => ['sometimes', 'array', 'max:32'],
            'capabilities.*.supported_profiles' => ['sometimes', 'array', 'max:16'],
            'capabilities.*.supported_profiles.*' => [
                'string',
                'max:160',
                'distinct',
                'regex:/^urn:iicp:profile:[a-z0-9][a-z0-9._:-]*$/',
            ],
            // ADR-045 Phase A (#407) — optional verifiable operator→node delegation (ed25519).
            'operator_delegation' => ['sometimes', 'nullable', 'array'],
            'operator_delegation.node_id' => ['required_with:operator_delegation', 'string'],
            'operator_delegation.operator_pub' => ['required_with:operator_delegation', 'string', 'max:64'],
            'operator_delegation.not_after' => ['required_with:operator_delegation', 'integer', 'min:1'],
            'operator_delegation.sig' => ['required_with:operator_delegation', 'string', 'max:128'],
            // #463/#464 — operator-identity attributes (only bound when a delegation verifies).
            // display_name is the public handle; operator_created_at + operator_integrity_hash
            // are the identity-integrity fields. contact/email is NEVER accepted here (private).
            'operator_display_name' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[^\x00-\x1f\x7f]*$/'],
            'operator_created_at' => ['sometimes', 'nullable', 'string', 'max:40'],
            'operator_integrity_hash' => ['sometimes', 'nullable', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
            'limits' => ['required', 'array'],
            'limits.max_concurrent' => ['required', 'integer', 'min:1', 'max:256'],
            'limits.tokens_per_min' => ['required', 'integer', 'min:1'],
            'relay_capable' => ['sometimes', 'boolean'],
            // Detected backend server flavor (SDK-advertised) — node-detail field.
            'backend' => ['sometimes', 'nullable', 'string', 'in:ollama,lmstudio,vllm,llamacpp,meshllm,anthropic,custom'],
            // CIP-D1: Provider opt-in policy block (spec S.12 §2.1)
            'policy' => ['sometimes', 'array'],
            'policy.allow_remote_inference' => ['sometimes', 'boolean'],
            'policy.allow_tool_execution' => ['sometimes', 'boolean'],
            'policy.allow_file_access' => ['sometimes', 'boolean'],
            'policy.pricing_credits_per_1000' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],
            // Phase-1 compliance manifest: self-attested public policy facts.
            // The directory stores and echoes this small, safe, machine-readable
            // declaration; clients may later enforce it before sending prompts.
            'policy_manifest' => ['sometimes', 'nullable', 'array'],
            'policy_manifest.version' => ['sometimes', 'nullable', 'string', 'max:32'],
            'policy_manifest.jurisdiction' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9 ._:-]*$/'],
            'policy_manifest.policy_url' => ['sometimes', 'nullable', 'url', 'max:256'],
            'policy_manifest.contact_url' => ['sometimes', 'nullable', 'url', 'max:256'],
            'policy_manifest.remote_executor_can_read_prompt' => ['sometimes', 'boolean'],
            'policy_manifest.training_use' => ['sometimes', 'nullable', 'string', 'in:none,opt_in,provider_defined'],
            'policy_manifest.retention' => ['sometimes', 'nullable', 'array'],
            'policy_manifest.retention.task_payload' => ['sometimes', 'nullable', 'string', 'in:none,transient,provider_defined'],
            'policy_manifest.retention.logs_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'policy_manifest.subprocessors' => ['sometimes', 'nullable', 'array', 'max:20'],
            'policy_manifest.subprocessors.*' => ['string', 'max:80', 'regex:/^[^\x00-\x1f\x7f<>]*$/'],
            'policy_manifest.unsupported_intents' => ['sometimes', 'nullable', 'array', 'max:100'],
            'policy_manifest.unsupported_intents.*' => ['string', 'max:255', 'regex:/^urn:iicp:intent:[a-z0-9_:.\\/-]+$/'],
            'policy_manifest.signed_statement' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'policy_manifest.signature' => ['sometimes', 'nullable', 'array'],
            'policy_manifest.signature.algorithm' => ['required_with:policy_manifest.signature', 'string', 'in:Ed25519'],
            'policy_manifest.signature.key_id' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._:-]+$/'],
            'policy_manifest.signature.public_key' => ['required_with:policy_manifest.signature', 'string', 'max:128'],
            'policy_manifest.signature.signature' => ['required_with:policy_manifest.signature', 'string', 'max:128'],
            'policy_manifest.signature.signed_at' => ['sometimes', 'nullable', 'date'],
            'policy_manifest.signature.expires_at' => ['sometimes', 'nullable', 'date'],
            // #602 — safe policy-signing-key lifecycle hints. These fields are
            // part of the signed manifest payload (canonicalized by the verifier)
            // and are public-safe only after redaction/class normalization.
            'policy_manifest.signature.rotation_epoch' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
            'policy_manifest.signature.revoked_at' => ['sometimes', 'nullable', 'date'],
            'policy_manifest.signature.revocation_reason_class' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/'],
            'availability' => ['sometimes', 'array'],
            'availability.*.start' => ['required_with:availability', 'date_format:H:i'],
            'availability.*.end' => ['required_with:availability', 'date_format:H:i'],
            'availability.*.share' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            // ADR-017: Operator opt-in public listing (default off — REG-01)
            'listing' => ['sometimes', 'array'],
            'listing.public_listing' => ['sometimes', 'boolean'],
            'listing.operator_url' => ['sometimes', 'nullable', 'url', 'max:256'],
            'listing.operator_contact' => ['sometimes', 'nullable', 'string', 'max:256'],
            // ADR-019 declarative pricing (optional; operator-provided signing key for attestation)
            'node_hmac_key' => ['sometimes', 'nullable', 'string', 'max:128'],
            'pricing' => ['sometimes', 'array'],
            'pricing.credit_cost_multiplier' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'pricing.pricing_model' => ['sometimes', 'string', 'in:per_token,per_request,flat'],
            'pricing.declaration_signature' => ['sometimes', 'nullable', 'string', 'max:128'],
            'pricing.effective_from' => ['sometimes', 'nullable', 'date'],
            'pricing.effective_until' => ['sometimes', 'nullable', 'date', 'after:pricing.effective_from'],
            // #331 Phase A.1 / ADR-041: NAT-traversal observability (all optional, back-compat)
            'transport_method' => ['sometimes', 'nullable', 'string', 'in:direct,upnp_mapped,stun_hole_punch,turn_relay,external_tunnel,unknown'],
            'nat_type' => ['sometimes', 'nullable', 'string', 'in:full_cone,restricted_cone,port_restricted,symmetric,unknown'],
            'transport_metadata' => ['sometimes', 'nullable', 'array'],
            'transport_candidates' => ['sometimes', 'nullable', 'array'],  // forward-compat shorthand; folded into transport_metadata
            'relay_endpoint' => ['sometimes', 'nullable', 'string', 'max:255'],  // forward-compat; folded into transport_metadata
            // SDK identification — free-form lowercase string so future
            // C/C++/Java/Go SDKs can self-tag without a directory migration.
            'sdk_language' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[a-z0-9_-]+$/'],
            'implementation_name' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9@][A-Za-z0-9._\/@+\-]{0,63}$/'],
            'implementation_version' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z][0-9A-Za-z._+\-]{0,31}$/'],
            'sdk_compatibility_version' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z][0-9A-Za-z._+\-]{0,31}$/'],
            'sdk_version' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z][0-9A-Za-z._+\-]{0,31}$/'],
            // Pre-normative receipt-profile adoption signal. Stored for migration
            // measurement only; it MUST NOT alter routing, trust, credits or settlement.
            'supported_receipt_profiles' => ['sometimes', 'array', 'max:4'],
            'supported_receipt_profiles.*' => ['string', 'in:consumer_cosignature_v1'],
            // Optional updater evidence. Advisory only: clients are untrusted, but
            // this lets the directory distinguish "old and self-updating" from
            // "old and stuck" without weakening SDK-baseline policy.
            'auto_update_enabled' => ['sometimes', 'nullable', 'boolean'],
            'auto_update_interval_s' => ['sometimes', 'nullable', 'integer', 'min:300', 'max:86400'],
            'sdk_latest_seen' => ['sometimes', 'nullable', 'string', 'max:32'],
            'sdk_update_last_checked_at' => ['sometimes', 'nullable', 'date'],
            'sdk_update_error_class' => ['sometimes', 'nullable', 'string', 'max:64'],
            // ADR-043 §9 — 8-category network exposure classification
            'exposure_mode' => ['sometimes', 'nullable', 'string', 'in:outbound_only,ipv4_public_direct,ipv4_cgnat_blocked,ipv6_direct_firewall_required,ipv6_direct_pinhole_available,relay_required,tunnel_required,dual_stack_available'],
            // IICP-CX S.16 §3.1 — X25519 public key advertisement for E2E payload
            // confidentiality (#360). The directory only stores+echoes this opaque
            // key material; it never uses it (PA-3: directory sees no plaintext).
            'cx_public_key' => ['sometimes', 'nullable', 'array'],
            'cx_public_key.algorithm' => ['required_with:cx_public_key', 'string', 'in:X25519'],
            'cx_public_key.encoding' => ['required_with:cx_public_key', 'string', 'in:base64url'],
            'cx_public_key.key' => ['required_with:cx_public_key', 'string', 'max:128'],
            'cx_public_key.key_id' => ['required_with:cx_public_key', 'string', 'max:32'],
            'cx_public_key.not_after' => ['sometimes', 'nullable', 'date'],
            'cx_public_key.hybrid_pq' => ['sometimes', 'nullable'],
            'cx_public_key.features' => ['sometimes', 'array', 'max:16'],
            'cx_public_key.features.*' => ['string', 'max:64'],
        ]);

        $validated = $this->prepareValidatedPayload($request, $validated);

        $observedIp = $this->addressObserver->getObservedIp($request);
        $registration = $this->registerNode($validated, $observedIp);
        if ($registration instanceof JsonResponse) {
            return $registration;
        }
        $result = $registration;

        // Persist address history for this registration event (DIR-ADDR-06)
        $node = Node::findOrFail($result['node_id']);
        $this->addressObserver->observe($node, $observedIp, 'register');

        // Emit signed event log entry (Phase 6 prereq — spec §3.4).
        // Replicas MUST receive cip_policy, cip_conformance_level, and pricing to serve
        // CIP-Full consumer discovery (spec iicp-federated-directory.md §9).
        $this->logRegistration($node, $result, $validated);

        $this->allocateRegistrationCredits($result['node_id'], $request);

        $span->setAttribute('iicp.node_id', (string) $result['node_id'])
            ->setAttribute('iicp.region', (string) $validated['region'])
            ->setAttribute('iicp.capabilities_count', count($validated['capabilities']));
        $span->end();

        return response()->json($result, 201);
    }

    private function rateLimitResponse(Request $request): ?JsonResponse
    {
        $rateLimitKey = 'reg_rate:'.($request->ip() ?? 'unknown');
        Cache::add($rateLimitKey, 0, self::REGISTER_RATE_TTL);
        if (Cache::increment($rateLimitKey) <= self::REGISTER_RATE_LIMIT) {
            return null;
        }

        return response()->json([
            'error' => 'IICP-E034',
            'message' => 'TooManyRegistrationAttempts',
            'retry_after' => self::REGISTER_RATE_TTL,
        ], 429);
    }

    private function prepareValidatedPayload(Request $request, array $validated): array
    {
        $validated = $this->normalizeSdkVersions($validated);
        $cxKeyInput = $request->input('cx_public_key');
        $cxFeatures = is_array($cxKeyInput) ? ($cxKeyInput['features'] ?? null) : null;
        if (is_array($cxFeatures) && isset($validated['cx_public_key'])) {
            $validated['cx_public_key']['features'] = array_values($cxFeatures);
        }

        $this->validateCapabilities($validated['capabilities'] ?? []);
        $this->validatePolicyManifest($validated['policy_manifest'] ?? null);

        return $this->normalizeTransportMetadata($validated);
    }

    private function validateCapabilities(array $capabilities): void
    {
        $seenVariants = [];
        $seenObjects = [];
        foreach ($capabilities as $idx => $capability) {
            $intent = (string) ($capability['intent'] ?? '');
            if ($message = $this->intentPolicy->refusalMessage($intent)) {
                throw ValidationException::withMessages(["capabilities.$idx.intent" => [$message]]);
            }
            $canonical = json_encode($this->sortCapability($capability), JSON_THROW_ON_ERROR);
            if (isset($seenObjects[$canonical])) {
                throw ValidationException::withMessages(["capabilities.$idx" => ['Exact duplicate capability variants are not allowed.']]);
            }
            $seenObjects[$canonical] = true;
            $this->recordCapabilityVariant($capability, $idx, $seenVariants);
            $this->validateEffectiveCapabilityMaps($capability, $idx);
        }
    }

    private function recordCapabilityVariant(array $capability, int $idx, array &$seenVariants): void
    {
        if (! isset($capability['variant_id'])) {
            return;
        }
        $identity = $capability['intent']."\0".$capability['variant_id'];
        if (isset($seenVariants[$identity])) {
            throw ValidationException::withMessages(["capabilities.$idx.variant_id" => ['variant_id must be unique for this intent.']]);
        }
        $seenVariants[$identity] = true;
    }

    private function validatePolicyManifest(mixed $manifest): void
    {
        if (! is_array($manifest)) {
            return;
        }
        $verification = NodePolicyManifestVerifier::verify($manifest);
        if (in_array($verification['status'], [
            NodePolicyManifestVerifier::STATUS_SIGNED_INVALID,
            NodePolicyManifestVerifier::STATUS_SIGNED_EXPIRED,
            NodePolicyManifestVerifier::STATUS_SIGNED_REVOKED,
            NodePolicyManifestVerifier::STATUS_SIGNED_SUPERSEDED,
        ], true)) {
            throw ValidationException::withMessages([
                'policy_manifest.signature' => ['Invalid node policy manifest signature: '.$verification['status']],
            ]);
        }
    }

    private function normalizeTransportMetadata(array $validated): array
    {
        if (! isset($validated['transport_candidates']) && ! isset($validated['relay_endpoint'])) {
            return $validated;
        }
        $validated['transport_metadata'] = array_merge(
            $validated['transport_metadata'] ?? [],
            array_filter([
                'candidates' => $validated['transport_candidates'] ?? null,
                'relay_endpoint' => $validated['relay_endpoint'] ?? null,
            ], fn ($v) => $v !== null),
        );
        unset($validated['transport_candidates'], $validated['relay_endpoint']);

        return $validated;
    }

    private function registerNode(array $validated, string $observedIp): array|JsonResponse
    {
        try {
            return $this->registry->register($validated, $observedIp);
        } catch (\InvalidArgumentException $e) {
            return $this->registrationErrorResponse($e);
        }
    }

    private function registrationErrorResponse(\InvalidArgumentException $exception): JsonResponse
    {
        $message = $exception->getMessage();
        if (str_starts_with($message, 'IICP-E049:')) {
            return response()->json([
                'error' => ['code' => 'IICP-E049', 'message' => 'cx_public_key update requires valid current_node_token'],
            ], 403);
        }
        if (str_starts_with($message, 'IICP-E050:')) {
            return response()->json([
                'error' => ['code' => 'IICP-E050', 'message' => 'routing-field change requires current_node_token ownership or the previous endpoint to be unreachable'],
            ], 403);
        }
        throw $exception;
    }

    private function logRegistration(Node $node, array $result, array $validated): void
    {
        $cipPolicy = [
            'allow_remote_inference' => (bool) ($node->allow_remote_inference ?? false),
            'allow_tool_execution' => (bool) ($node->allow_tool_execution ?? false),
            'allow_file_access' => (bool) ($node->allow_file_access ?? false),
        ];
        $this->eventLogger->log('REGISTER', $result['node_id'], [
            'endpoint' => $validated['endpoint'],
            'region' => $validated['region'],
            'cip_policy' => $cipPolicy,
            'cip_conformance_level' => $cipPolicy['allow_remote_inference'] ? 'CIP-Provider' : 'CIP-None',
            // #439 — carry reachability so a replica can serve /v1/discover (not just
            // registry/nodes) for mirrored nodes. Discover filters on public_reachable=true
            // OR exposure_mode in the relay-reachable set (NodeScorer); without these fields
            // a replicated node was listed in registry but invisible to discover.
            'public_reachable' => (bool) ($node->public_reachable ?? false),
            'exposure_mode' => $node->exposure_mode,
            'pricing' => [
                'credit_cost_multiplier' => $node->credit_cost_multiplier ?? 1.0,
                'pricing_model' => $node->pricing_model ?? 'per_token',
                'pricing_credits_per_1000' => $node->pricing_credits_per_1000 ?? null,
            ],
            'policy_manifest' => $node->policy_manifest,
            'supported_receipt_profiles' => $node->supported_receipt_profiles ?? [],
            // #438 — carry the node's capabilities so a replica can serve /v1/discover
            // for nodes registered AFTER its snapshot (the event log was otherwise
            // capability-less → post-bootstrap nodes invisible on replicas). Only the
            // discover-relevant fields; replicas rebuild capability rows from these.
            'capabilities' => array_map(fn ($c) => [
                'intent' => $c['intent'],
                'version' => $c['version'] ?? null,
                'phase' => $c['phase'] ?? null,
                'variant_id' => $c['variant_id'] ?? null,
                'models' => $c['models'] ?? [],
                'max_tokens' => $c['max_tokens'] ?? null,
                'input_modalities' => $c['input_modalities'] ?? ['text'],
                'output_modalities' => $c['output_modalities'] ?? null,
                'features' => $c['features'] ?? null,
                'execution_capabilities' => $c['execution_capabilities'] ?? null,
                'limits' => $c['limits'] ?? null,
                'supported_profiles' => $c['supported_profiles'] ?? [],
                'claim_provenance' => $c['claim_provenance'] ?? null,
                'extensions' => $c['extensions'] ?? null,
            ], $validated['capabilities']),
        ]);
    }

    private function allocateRegistrationCredits(string $nodeId, Request $request): void
    {
        $allocated = $this->credits->maybeAllocateFreeCredits($nodeId, $request->ip() ?? '0.0.0.0');
        if ($allocated !== null) {
            $this->eventLogger->log('CREDIT_ALLOCATION', $nodeId, [
                'amount' => $allocated,
                'reason' => 'free_allocation_on_register',
                'period_h' => CreditService::FREE_CREDITS_PERIOD_HOURS,
            ]);
        }
    }

    /**
     * Keep the preferred and legacy SDK-version projections equivalent during
     * the compatibility window.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeSdkVersions(array $validated): array
    {
        $preferredSdk = $validated['sdk_compatibility_version'] ?? null;
        $legacySdk = $validated['sdk_version'] ?? null;
        if ($preferredSdk !== null && $legacySdk !== null && $preferredSdk !== $legacySdk) {
            throw ValidationException::withMessages([
                'sdk_compatibility_version' => ['Must match sdk_version when both fields are supplied.'],
            ]);
        }

        $effectiveSdk = $preferredSdk ?? $legacySdk;
        if ($effectiveSdk !== null) {
            $validated['sdk_compatibility_version'] = $effectiveSdk;
            $validated['sdk_version'] = $effectiveSdk;
        }

        return $validated;
    }
}

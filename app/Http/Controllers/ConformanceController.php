<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\ConformanceBadge;
use App\Services\ConformanceBadgeValidator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Badge ingestion, verification, and listing — three-endpoint conformance surface (BADGE-01..05).
 *
 * WHY two verification modes (genesis-verified vs self-attested): self-attested allows any
 * operator to claim compliance without directory involvement — low barrier, low trust signal.
 * Genesis-verified requires the submitter to obtain an Ed25519 signature from the directory's
 * Genesis Seed key (did:web:iicp.network), giving a cryptographic anchor. The two modes
 * coexist because different deployment contexts have different trust requirements: a
 * self-hosted sandbox can be self-attested; a public mesh node should aim for genesis-verified.
 *
 * WHY signature verification is deferred for self-attested: the directory stores the
 * submitter's sig field as-is without verifying it. Spec BADGE-02 says self-attested
 * badges carry the submitter's own signature; the directory is not the verifier for
 * third-party keys. Ed25519 key management infrastructure (#96) is needed before the
 * directory can verify arbitrary third-party sigs.
 *
 * WHY 409 on duplicate badge_id: badge_id is a UUID v4 issued by the submitter. Idempotent
 * re-submission of the same badge should be rejected rather than silently accepted —
 * duplication likely indicates a replay or a misconfigured submitter, not intentional
 * resubmission. 409 signals "this badge already exists; check your state."
 *
 * WHY ConformanceBadgeValidator is a separate class (not inline rules): cross-field
 * logic (mode-specific required fields, expiry range) makes inline rules unreadable.
 * See ConformanceBadgeValidator docblock for full rationale.
 *
 * Spec: spec/iicp-recognition.md (BADGE-01..BADGE-05). ADR: ADR-013 (federation prereq).
 */
class ConformanceController extends Controller
{
    // BADGE-03: 90-day TTL; accept up to 1 day over for clock skew
    private const MAX_EXPIRY_DAYS = 91;

    // S.14 §3 — Genesis Seed issuer DID
    private const GENESIS_ISSUER_DID = 'did:web:iicp.network';

    /**
     * POST /v1/conformance/submit
     * Accept a badge attestation record (self-attested or genesis-verified).
     * Genesis-verified mode requires issuer_did=did:web:iicp.network; signature
     * verification is deferred until Ed25519 key management is in place (#96).
     */
    public function submit(Request $request): JsonResponse
    {
        $data = $request->only([
            'badge_id', 'tier', 'subject_did', 'subject_component',
            'suite_version', 'passed_at', 'expires_at',
            'test_results_url', 'issuer_did', 'verification_mode', 'sig',
        ]);

        $mode = $data['verification_mode'] ?? 'self-attested';

        $errors = (new ConformanceBadgeValidator)->validate($data, $mode);
        if (! empty($errors)) {
            return response()->json([
                'error' => 'BADGE-INVALID',
                'message' => 'Badge record failed validation.',
                'details' => $errors,
            ], 422);
        }

        if ($mode === 'genesis-verified') {
            if ($data['issuer_did'] !== self::GENESIS_ISSUER_DID) {
                return response()->json([
                    'error' => 'BADGE-ISSUER-INVALID',
                    'message' => 'genesis-verified mode requires issuer_did='.self::GENESIS_ISSUER_DID,
                ], 422);
            }

            $secretKey = $this->loadGenesisSecretKey();
            if ($secretKey === null) {
                return response()->json([
                    'error' => 'BADGE-GENESIS-UNAVAILABLE',
                    'message' => 'Genesis Seed signing key not configured on this instance.',
                ], 503);
            }
        }

        $badgeId = $data['badge_id'];
        if (ConformanceBadge::where('badge_id', $badgeId)->exists()) {
            return response()->json([
                'error' => 'BADGE-DUPLICATE',
                'message' => 'A badge with this badge_id already exists.',
            ], 409);
        }

        $passedAt = Carbon::parse($data['passed_at'])->utc();
        $expiresAt = Carbon::parse($data['expires_at'])->utc();

        // For genesis-verified mode, directory generates the Ed25519 signature.
        // For self-attested, the submitter's sig is stored as-is (not verified by directory).
        if ($mode === 'genesis-verified') {
            $canonical = $this->canonicalMessage($data, $passedAt);
            $sig = sodium_bin2base64(
                sodium_crypto_sign_detached($canonical, $secretKey),
                SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING
            );
        } else {
            $sig = $data['sig'];
        }

        ConformanceBadge::create([
            'badge_id' => $badgeId,
            'tier' => $data['tier'],
            'subject_did' => $data['subject_did'],
            'subject_component' => $data['subject_component'],
            'suite_version' => $data['suite_version'],
            'passed_at' => $passedAt->format('Y-m-d H:i:s'),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'test_results_url' => $data['test_results_url'],
            'issuer_did' => $data['issuer_did'],
            'verification_mode' => $mode,
            'sig' => $sig,
            'status' => $expiresAt->isFuture() ? 'active' : 'expired',
        ]);

        return response()->json([
            'badge_id' => $badgeId,
            'status' => 'accepted',
            'expires_at' => $expiresAt->toIso8601String(),
            'sig' => $sig,
        ], 201);
    }

    /**
     * GET /v1/conformance/verify?did=&tier=
     * Check whether a valid (non-expired) badge exists for the given DID and tier.
     */
    public function verify(Request $request): JsonResponse
    {
        $did = $request->query('did');
        $tier = $request->query('tier');

        if (! $did || ! $tier) {
            return response()->json([
                'error' => 'BADGE-PARAMS-MISSING',
                'message' => 'Both did and tier query parameters are required.',
            ], 422);
        }

        if (! in_array($tier, ConformanceBadgeValidator::validTiers(), true)) {
            return response()->json([
                'is_valid' => false,
                'reason' => 'unknown_tier',
            ]);
        }

        $badge = ConformanceBadge::where('subject_did', $did)
            ->where('tier', $tier)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->orderBy('expires_at', 'desc')
            ->first();

        if (! $badge) {
            return response()->json(['is_valid' => false, 'reason' => 'not_found']);
        }

        return response()->json([
            'is_valid' => true,
            'badge_id' => $badge->badge_id,
            'tier' => $badge->tier,
            'subject_component' => $badge->subject_component,
            'suite_version' => $badge->suite_version,
            'passed_at' => Carbon::parse($badge->passed_at)->toIso8601String(),
            'expires_at' => Carbon::parse($badge->expires_at)->toIso8601String(),
            'verification_mode' => $badge->verification_mode,
        ]);
    }

    /**
     * GET /v1/conformance/badges?did=
     * List all active badges for the given DID.
     */
    public function badges(Request $request): JsonResponse
    {
        $did = $request->query('did');

        if (! $did) {
            return response()->json([
                'error' => 'BADGE-PARAMS-MISSING',
                'message' => 'The did query parameter is required.',
            ], 422);
        }

        $badges = ConformanceBadge::where('subject_did', $did)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->orderBy('passed_at', 'desc')
            ->get()
            ->map(fn ($b) => [
                'badge_id' => $b->badge_id,
                'tier' => $b->tier,
                'subject_component' => $b->subject_component,
                'suite_version' => $b->suite_version,
                'passed_at' => Carbon::parse($b->passed_at)->toIso8601String(),
                'expires_at' => Carbon::parse($b->expires_at)->toIso8601String(),
                'verification_mode' => $b->verification_mode,
            ]);

        return response()->json([
            'did' => $did,
            'count' => $badges->count(),
            'badges' => $badges,
        ]);
    }

    private function canonicalMessage(array $data, Carbon $passedAtUtc): string
    {
        return implode(':', [
            $data['badge_id'],
            $data['tier'],
            $data['subject_did'],
            $passedAtUtc->toIso8601String(),
        ]);
    }

    private function loadGenesisSecretKey(): ?string
    {
        $encoded = env('GENESIS_SEED_SECRET_KEY');
        if (! $encoded) {
            return null;
        }
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return null;
        }

        return $decoded;
    }
}

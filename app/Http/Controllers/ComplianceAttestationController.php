<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\TelemetryProbe;
use App\Services\NodeEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * #508 — Signed compliance attestation (spec/iicp-dir.md §12, SHOULD).
 *
 * One signed JSON fetch lets an external verifier confirm the directory's
 * conformance state (<100ms) instead of re-running the whole probe suite —
 * the fast path for federation bootstrap and third-party audits. The live
 * REACH suite keeps probing independently, so a compromised directory falsely
 * attesting compliance is still caught by out-of-band probes (#508 Q4).
 *
 * Signing: the genesis Ed25519 key (same trust root as the event log —
 * did:web:iicp.network verificationMethod[0]). Cross-protocol replay is
 * prevented by the `purpose` field bound into the signed canonical document.
 * Canonicalization: NodeEventLogger::canonicalJson — ONE rule for all
 * signed directory documents.
 */
class ComplianceAttestationController extends Controller
{
    private const SPEC_VERSION = 'iicp-dir v1.1.0';

    private const PURPOSE = 'compliance-attestation';

    /** Freshness window (#508 Q3): structure probes are stable over 15 minutes. */
    private const VALIDITY_S = 900;

    /** Response cache — bounded staleness well inside the validity window. */
    private const CACHE_S = 60;

    public function index(): JsonResponse
    {
        $hexKey = (string) config('app.genesis_ed25519_secret_key');
        if (! $hexKey || strlen($hexKey) !== 128) {
            // Fail closed: an unsigned attestation is unverifiable, so don't emit one.
            return response()->json([
                'error' => [
                    'code' => 'attestation_unavailable',
                    'message' => 'Attestation signing key not configured on this directory',
                ],
            ], 503);
        }

        $attestation = Cache::remember('compliance.attestation', self::CACHE_S, function () use ($hexKey) {
            return $this->build($hexKey);
        });

        if ($attestation === null) {
            return response()->json([
                'error' => [
                    'code' => 'no_probe_data',
                    'message' => 'No conformance probe run recorded yet',
                ],
            ], 503);
        }

        return response()->json($attestation);
    }

    /** Build + sign the attestation from the most recent conformance probe run. */
    private function build(string $hexKey): ?array
    {
        // The latest completed REACH run: newest probed_at among conformance probes,
        // then every result that shares its run_id.
        $latest = TelemetryProbe::where('probe_type', 'conformance')
            ->orderByDesc('probed_at')
            ->orderByDesc('id')
            ->first(['run_id', 'probed_at']);
        if ($latest === null) {
            return null;
        }

        $rows = TelemetryProbe::where('run_id', $latest->run_id)
            ->where('probe_type', 'conformance')
            ->get(['test_id', 'passed', 'level']);

        $passed = $rows->where('passed', true)->pluck('test_id')->unique()->sort()->values()->all();
        $failed = $rows->where('passed', false)->pluck('test_id')->unique()->sort()->values()->all();

        $generatedAt = now();
        $document = [
            'endpoint' => rtrim((string) config('app.url'), '/'),
            'spec_version' => self::SPEC_VERSION,
            'purpose' => self::PURPOSE,
            'probe_run_id' => (string) $latest->run_id,
            'probe_run_at' => $latest->probed_at->toIso8601String(),
            'passed_probes' => $passed,
            'failed_probes' => $failed,
            'generated_at' => $generatedAt->toIso8601String(),
            'valid_until' => $generatedAt->copy()->addSeconds(self::VALIDITY_S)->toIso8601String(),
        ];

        $canonical = NodeEventLogger::canonicalJson($document);
        $hash = hash('sha256', $canonical);
        $signature = bin2hex(sodium_crypto_sign_detached(
            hash('sha256', $canonical, true),
            sodium_hex2bin($hexKey)
        ));

        return $document + [
            'attestation_hash' => $hash,
            'signature' => $signature,
            'signer_did' => 'did:web:iicp.network',
        ];
    }
}

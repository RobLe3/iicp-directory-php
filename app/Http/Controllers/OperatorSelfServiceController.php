<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\Operator;
use App\Services\DataSubjectRightsService;
use App\Services\OperatorIdentityLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Operator-key authenticated acceptance and DSR self-service API (#599/#609). */
class OperatorSelfServiceController extends Controller
{
    private const TS_WINDOW = 300;

    private const CHALLENGE_TTL = 300;

    public function challenge(Request $request): JsonResponse
    {
        $validated = $request->validate(['operator_pub' => ['required', 'string', 'max:64']]);
        $operator = Operator::where('operator_pubkey', $validated['operator_pub'])->first();
        if ($operator === null) {
            return $this->error('IICP-E044', 'unknown operator (register a delegated node first)', 404);
        }
        if (($operator->identity_status ?? Operator::IDENTITY_ACTIVE) !== Operator::IDENTITY_ACTIVE) {
            return $this->error('IICP-E063', 'operator identity is no longer active', 409);
        }

        $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        Cache::put($this->challengeKey($validated['operator_pub'], $nonce), true, self::CHALLENGE_TTL);

        return response()->json([
            'nonce' => $nonce,
            'expires_at' => now()->addSeconds(self::CHALLENGE_TTL)->toIso8601String(),
            'operator_fingerprint' => Operator::publicFingerprint($validated['operator_pub']),
            'terms_version' => (string) config('app.iicp_operator_terms_version'),
            'dpa_version' => (string) config('app.iicp_operator_dpa_version'),
            'signing_contract' => 'iicp.operator.self-service.v1',
        ])->header('Cache-Control', 'no-store');
    }

    public function accept(Request $request): JsonResponse
    {
        $validated = $this->validateSigned($request, 'accept', [
            'terms_version' => ['required', 'string', 'max:64'],
            'dpa_version' => ['required', 'string', 'max:64'],
        ]);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        if ($validated['terms_version'] !== (string) config('app.iicp_operator_terms_version')
            || $validated['dpa_version'] !== (string) config('app.iicp_operator_dpa_version')) {
            return $this->error('IICP-E061', 'terms or DPA version is not current', 409);
        }

        /** @var Operator $operator */
        $operator = $validated['_operator'];
        $operator->update([
            'terms_version' => $validated['terms_version'],
            'terms_accepted_at' => now(),
            'dpa_version' => $validated['dpa_version'],
            'dpa_accepted_at' => now(),
            'acceptance_method' => 'operator_key_challenge',
            'acceptance_nonce_sha256' => hash('sha256', $validated['nonce']),
        ]);

        return response()->json([
            'status' => 'accepted',
            'operator_fingerprint' => Operator::publicFingerprint($operator->operator_pubkey),
            'terms_version' => $operator->terms_version,
            'dpa_version' => $operator->dpa_version,
            'accepted_at' => $operator->terms_accepted_at?->toIso8601String(),
            'receipt_id_prefix' => substr(hash('sha256', $validated['nonce'].$operator->operator_pubkey), 0, 12),
            'legal_certification' => false,
        ])->header('Cache-Control', 'no-store');
    }

    public function export(Request $request, DataSubjectRightsService $dsr): JsonResponse
    {
        return $this->dsr($request, $dsr, 'export');
    }

    public function restrict(Request $request, DataSubjectRightsService $dsr): JsonResponse
    {
        return $this->dsr($request, $dsr, 'restrict');
    }

    public function anonymize(Request $request, DataSubjectRightsService $dsr): JsonResponse
    {
        return $this->dsr($request, $dsr, 'anonymize');
    }

    /**
     * Rotate an accountless operator identity. Both the current and successor
     * Ed25519 keys must sign the same one-use challenge before any continuity is
     * moved. Policy manifests remain independently signed and therefore need to
     * be re-issued by the successor rather than being silently rewritten.
     */
    public function rotate(Request $request, OperatorIdentityLifecycleService $lifecycle): JsonResponse
    {
        $validated = $this->validateSigned($request, 'key_rotate', [
            'new_operator_pub' => ['required', 'string', 'max:64'],
            'new_key_sig' => ['required', 'string', 'max:128'],
            'rotation_epoch' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'reason_class' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z0-9_-]{1,64}$/'],
        ]);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $newPubRaw = base64_decode($validated['new_operator_pub'], true);
        $newSigRaw = base64_decode($validated['new_key_sig'], true);
        if ($newPubRaw === false || strlen($newPubRaw) !== 32 || $newSigRaw === false || strlen($newSigRaw) !== 64) {
            return $this->error('IICP-E064', 'malformed successor operator key or signature', 401);
        }
        $proof = self::canonicalBytes('key_rotate_successor', [
            'operator_pub' => $validated['operator_pub'],
            'new_operator_pub' => $validated['new_operator_pub'],
            'nonce' => $validated['nonce'],
            'ts' => $validated['ts'],
            'rotation_epoch' => $validated['rotation_epoch'] ?? null,
        ]);
        try {
            $successorProofValid = sodium_crypto_sign_verify_detached($newSigRaw, $proof, $newPubRaw);
        } catch (\SodiumException) {
            $successorProofValid = false;
        }
        if (! $successorProofValid) {
            return $this->error('IICP-E064', 'successor operator signature verification failed', 401);
        }

        try {
            $result = $lifecycle->rotate(
                $validated['_operator'],
                $validated['new_operator_pub'],
                $validated['rotation_epoch'] ?? null,
                $validated['reason_class'] ?? 'operator_rotation',
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error('IICP-E063', 'operator identity rotation cannot be completed', 409);
        }

        return response()->json([
            'status' => 'rotated',
            'operator_fingerprint' => Operator::publicFingerprint($result['operator']->operator_pubkey),
            'linked_nodes' => $result['linked_nodes'],
            'rotation_epoch' => $result['rotation_epoch'],
            'receipt_id_prefix' => substr(hash('sha256', $validated['nonce'].$result['operator']->operator_pubkey), 0, 12),
            'legal_certification' => false,
        ])->header('Cache-Control', 'no-store');
    }

    /** Revoke the current operator key and fail closed for its node bindings. */
    public function revoke(Request $request, OperatorIdentityLifecycleService $lifecycle): JsonResponse
    {
        $validated = $this->validateSigned($request, 'key_revoke', [
            'confirm' => ['required', 'accepted'],
            'reason_class' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z0-9_-]{1,64}$/'],
        ]);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }
        try {
            $result = $lifecycle->revoke($validated['_operator'], $validated['reason_class'] ?? 'operator_request');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error('IICP-E063', 'operator identity revocation cannot be completed', 409);
        }

        return response()->json([
            'status' => 'revoked',
            'operator_fingerprint' => Operator::publicFingerprint($validated['operator_pub']),
            'linked_nodes' => $result['linked_nodes'],
            'revoked_at' => $result['revoked_at'],
            'receipt_id_prefix' => substr(hash('sha256', $validated['nonce'].$validated['operator_pub']), 0, 12),
            'legal_certification' => false,
        ])->header('Cache-Control', 'no-store');
    }

    private function dsr(Request $request, DataSubjectRightsService $dsr, string $action): JsonResponse
    {
        $extra = ['tracking_id' => ['sometimes', 'nullable', 'string', 'max:64']];
        if ($action !== 'export') {
            $extra['confirm'] = ['required', 'accepted'];
        }
        $validated = $this->validateSigned($request, "dsr_{$action}", $extra);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $trackingId = (string) ($validated['tracking_id'] ?? 'dsr-'.Str::uuid());
        $selector = ['operator_pubkey' => $validated['operator_pub']];
        $result = match ($action) {
            'export' => $dsr->export($selector, $trackingId),
            'restrict' => $dsr->restrict($selector, $trackingId),
            'anonymize' => $dsr->anonymize($selector, $trackingId),
        };

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    /** @param array<string,array<int,string>|string> $extraRules */
    private function validateSigned(Request $request, string $action, array $extraRules): array|JsonResponse
    {
        $validated = $request->validate(array_merge([
            'operator_pub' => ['required', 'string', 'max:64'],
            'nonce' => ['required', 'string', 'min:16', 'max:64'],
            'ts' => ['required', 'integer'],
            'sig' => ['required', 'string', 'max:128'],
        ], $extraRules));

        if (abs(time() - (int) $validated['ts']) > self::TS_WINDOW) {
            return $this->error('IICP-E041', 'stale or future-dated operator request', 401);
        }
        $operator = Operator::where('operator_pubkey', $validated['operator_pub'])->first();
        if ($operator === null) {
            return $this->error('IICP-E044', 'unknown operator', 404);
        }
        if (($operator->identity_status ?? Operator::IDENTITY_ACTIVE) !== Operator::IDENTITY_ACTIVE) {
            return $this->error('IICP-E063', 'operator identity is no longer active', 409);
        }
        $challengeKey = $this->challengeKey($validated['operator_pub'], $validated['nonce']);
        if (! Cache::pull($challengeKey)) {
            return $this->error('IICP-E062', 'challenge is missing, expired or already used', 401);
        }

        $pubRaw = base64_decode($validated['operator_pub'], true);
        $sigRaw = base64_decode($validated['sig'], true);
        if ($pubRaw === false || strlen($pubRaw) !== 32 || $sigRaw === false || strlen($sigRaw) !== 64) {
            return $this->error('IICP-E042', 'malformed operator key or signature', 401);
        }

        $signed = $validated;
        unset($signed['sig']);
        $message = self::canonicalBytes($action, $signed);
        try {
            $valid = sodium_crypto_sign_verify_detached($sigRaw, $message, $pubRaw);
        } catch (\SodiumException) {
            $valid = false;
        }
        if (! $valid) {
            return $this->error('IICP-E043', 'operator signature verification failed', 401);
        }

        $validated['_operator'] = $operator;

        return $validated;
    }

    /** @param array<string,mixed> $fields */
    public static function canonicalBytes(string $action, array $fields): string
    {
        unset($fields['_operator'], $fields['sig'], $fields['new_key_sig']);
        $payload = ['action' => $action, ...$fields];
        ksort($payload);

        return "iicp:operator:self-service:v1\n".json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function challengeKey(string $operatorPub, string $nonce): string
    {
        return 'operator_self_service_challenge:'.hash('sha256', $operatorPub."\n".$nonce);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status)
            ->header('Cache-Control', 'no-store');
    }
}

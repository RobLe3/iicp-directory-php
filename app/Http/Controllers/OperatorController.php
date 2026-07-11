<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\Operator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #460/#463 — operator-identity mutations, authenticated by the operator's OWN ed25519
 * signature (not a node token): only the holder of the operator key (operator_id ==
 * operator_pubkey, #464) may change their record.
 *
 * `POST /v1/operator/rename` — change the public, mutable `display_name` (the universal
 * handle: node detail + recognition leaderboard + future community surfaces). The immutable
 * operator_id and any earned founder ordinal stay bound to the key; only the floating
 * display_name changes. The operator never needs to re-register every node — one signed
 * call updates the single operator-keyed record, reflected everywhere.
 */
class OperatorController extends Controller
{
    /** Replay window for the signed timestamp (seconds). */
    private const TS_WINDOW = 300;

    /**
     * Canonical bytes the operator signs for a rename. Alphabetical key order
     * (display_name < operator_pub < ts), no whitespace, slashes/unicode unescaped —
     * MUST match the SDK signer byte-for-byte (cross-impl).
     */
    public static function canonicalBytes(string $displayName, string $operatorPub, int $ts): string
    {
        return json_encode(
            ['display_name' => $displayName, 'operator_pub' => $operatorPub, 'ts' => $ts],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    public function rename(Request $request): JsonResponse
    {
        $v = $request->validate([
            'operator_pub' => ['required', 'string', 'max:64'],
            'display_name' => ['required', 'string', 'max:64', 'regex:/^[^\x00-\x1f\x7f]*$/'],
            'ts' => ['required', 'integer'],
            'sig' => ['required', 'string', 'max:128'],
        ]);

        // Replay protection: the signed ts must be recent.
        if (abs(time() - (int) $v['ts']) > self::TS_WINDOW) {
            return response()->json([
                'error' => ['code' => 'IICP-E041', 'message' => 'stale or future-dated rename request'],
            ], 401);
        }

        $pubRaw = base64_decode($v['operator_pub'], true);
        $sigRaw = base64_decode($v['sig'], true);
        // ed25519 sizes hardcoded (pubkey 32 B, signature 64 B) — the SODIUM_CRYPTO_SIGN_* globals are
        // not reliably defined on the prod PHP (sodium polyfill) and 500 inside this namespace.
        if ($pubRaw === false || $sigRaw === false
            || strlen($pubRaw) !== 32
            || strlen($sigRaw) !== 64) {
            return response()->json([
                'error' => ['code' => 'IICP-E042', 'message' => 'malformed operator key or signature'],
            ], 401);
        }

        $message = self::canonicalBytes($v['display_name'], $v['operator_pub'], (int) $v['ts']);
        try {
            $valid = sodium_crypto_sign_verify_detached($sigRaw, $message, $pubRaw);
        } catch (\SodiumException) {
            $valid = false;
        }
        if (! $valid) {
            return response()->json([
                'error' => ['code' => 'IICP-E043', 'message' => 'operator signature verification failed'],
            ], 401);
        }

        // The operator must already exist (bound via a verified delegation at register).
        $operator = Operator::where('operator_pubkey', $v['operator_pub'])->first();
        if ($operator === null) {
            return response()->json([
                'error' => ['code' => 'IICP-E044', 'message' => 'unknown operator (register a node with this operator delegation first)'],
            ], 404);
        }
        if (($operator->identity_status ?? Operator::IDENTITY_ACTIVE) !== Operator::IDENTITY_ACTIVE) {
            return response()->json([
                'error' => ['code' => 'IICP-E063', 'message' => 'operator identity is no longer active'],
            ], 409)->header('Cache-Control', 'no-store');
        }

        $operator->update(['display_name' => $v['display_name']]);

        return response()->json(['display_name' => $operator->display_name]);
    }
}

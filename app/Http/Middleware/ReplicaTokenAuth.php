<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Middleware;

use App\Models\Replica;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ReplicaTokenAuth — verifies the replica profile issued by JwtService.
 *
 * Differs from NodeTokenAuth: expects role=replica in JWT claims, resolves
 * Replica (not Node), and rejects opaque-token fallback (replica tokens are
 * always JWTs, issued by §7.1 handshake).
 *
 * On success: attaches the Replica model to the request as `_authenticated_replica`.
 *
 * Spec: S.13 §5.5 (snapshot endpoint auth), §7.1 (token issuance).
 * Charter: P6-1.3b-iii (snapshot endpoint auth gate).
 */
class ReplicaTokenAuth
{
    public function __construct(private JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if (! $bearer) {
            return response()->json([
                'error' => ['code' => 'unauthorized', 'message' => 'Missing Authorization header'],
            ], 401);
        }

        $verification = $this->jwt->verifyReplica($bearer);
        if (! $verification->isValid()) {
            if ($verification->isExpired()) {
                return response()->json([
                    'error' => ['code' => 'token_expired', 'message' => 'Replica JWT has expired; re-register via POST /v1/replicas/register'],
                ], 401);
            }

            return response()->json([
                'error' => ['code' => 'unauthorized', 'message' => 'Invalid replica token'],
            ], 401);
        }

        $replica = Replica::where('replica_id', $verification->claims['sub'])->first();
        if (! $replica) {
            return response()->json([
                'error' => ['code' => 'unauthorized', 'message' => 'Replica not registered'],
            ], 401);
        }

        if ($replica->status !== Replica::STATUS_ACTIVE) {
            return response()->json([
                'error' => ['code' => 'unauthorized', 'message' => 'Replica is not active; re-register to reactivate'],
            ], 401);
        }

        if (! $replica->expires_at->isFuture()) {
            return response()->json([
                'error' => ['code' => 'token_expired', 'message' => 'Replica registration has expired; re-register via POST /v1/replicas/register'],
            ], 401);
        }

        if (($verification->claims['scope'] ?? null) === JwtService::LEGACY_REPLICA_SCOPE) {
            Log::notice('Accepted deprecated replica JWT scope for snapshot bootstrap.', [
                'replica_id' => $verification->claims['sub'],
                'replacement_scope' => JwtService::REPLICA_SCOPE,
            ]);
        }

        // Replica MAY rotate its token via re-registration; reject if hash differs.
        $presentedHash = hash('sha256', $bearer);
        if ($replica->replica_token_hash && $replica->replica_token_hash !== $presentedHash) {
            return response()->json([
                'error' => ['code' => 'unauthorized', 'message' => 'Replica token has been rotated; re-register to obtain a fresh token'],
            ], 401);
        }

        $request->merge(['_authenticated_replica' => $replica]);

        return $next($request);
    }
}

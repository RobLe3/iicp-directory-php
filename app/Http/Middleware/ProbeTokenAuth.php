<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Middleware;

use App\Models\ProbeToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProbeTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return response()->json([
                'error' => ['code' => 'unauthorized', 'message' => 'Missing Authorization header'],
            ], 401);
        }

        // Guard against oversized inputs before touching the DB
        if (strlen($bearer) > 512) {
            return response()->json([
                'error' => ['code' => 'unauthorized', 'message' => 'Invalid probe token'],
            ], 401);
        }

        $hash = hash('sha256', $bearer);

        $token = ProbeToken::where('token_hash', $hash)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        if (! $token) {
            return response()->json([
                'error' => ['code' => 'unauthorized', 'message' => 'Invalid probe token'],
            ], 401);
        }

        $token->touchLastSeen();
        $request->attributes->set('probe_token', $token);

        return $next($request);
    }
}

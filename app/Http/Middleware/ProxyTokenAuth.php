<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Middleware;

use App\Services\NodeRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// #114: Accepts POST /v1/telemetry ONLY from nodes that present their proxy_token.
// proxy_node_id from the payload identifies which node is acting as the proxy,
// and its proxy_token_hash is used for verification.
class ProxyTokenAuth
{
    public function __construct(private NodeRegistry $registry) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return response()->json([
                'error' => ['code' => 'IICP-E032', 'message' => 'Invalid or missing proxy_token'],
            ], 401);
        }

        $proxyNodeId = $request->input('proxy_node_id');

        if (! $proxyNodeId) {
            return response()->json([
                'error' => ['code' => 'IICP-E032', 'message' => 'proxy_node_id required for proxy_token auth'],
            ], 401);
        }

        $proxy = $this->registry->validateProxyToken($proxyNodeId, $bearer);

        if (! $proxy) {
            return response()->json([
                'error' => ['code' => 'IICP-E032', 'message' => 'Invalid or missing proxy_token'],
            ], 401);
        }

        $request->merge(['_authenticated_node' => $proxy]);

        return $next($request);
    }
}

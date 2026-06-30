<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Middleware;

use App\Models\Node;
use App\Services\JwtService;
use App\Services\NodeRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NodeTokenAuth
{
    public function __construct(private NodeRegistry $registry, private JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return response()->json([
                'error' => ['code' => 'unauthorized', 'message' => 'Missing Authorization header'],
            ], 401);
        }

        // Phase 2: try JWT first (compact token format — three dot-separated segments)
        $node = $this->tryJwt($bearer);

        // Spec requires token_expired to be distinct from unauthorized.
        // Only return it when the JWT has a valid signature but has expired — skip
        // the opaque fallback in that case, since the caller clearly intended JWT auth.
        if (! $node && $this->jwt->isExpiredJwt($bearer)) {
            return response()->json([
                'error' => ['code' => 'token_expired', 'message' => 'JWT has expired; re-register to obtain a new token'],
            ], 401);
        }

        // Phase 1 fallback: opaque node_token verified against bcrypt hash.
        // proxy_node_id is checked first for endpoints where node_id refers to a target node.
        // X-Node-Id header is accepted as a convenience for CLI clients that can't easily
        // inject a query-param into every authenticated GET request.
        if (! $node) {
            $nodeId = $request->route('id')
                ?? $request->input('proxy_node_id')
                ?? $request->input('node_id')
                ?? $request->header('X-Node-Id');
            if (! $nodeId) {
                return response()->json([
                    'error' => ['code' => 'unauthorized', 'message' => 'node_id required for token auth'],
                ], 401);
            }
            $node = $this->registry->validateToken($nodeId, $bearer);
        }

        if (! $node) {
            return response()->json([
                'error' => ['code' => 'unauthorized', 'message' => 'Invalid credentials'],
            ], 401);
        }

        $request->merge(['_authenticated_node' => $node]);

        return $next($request);
    }

    private function tryJwt(string $bearer): ?Node
    {
        $claims = $this->jwt->verify($bearer);
        if (! $claims || ! isset($claims['sub'])) {
            return null;
        }

        return Node::find($claims['sub']);
    }
}

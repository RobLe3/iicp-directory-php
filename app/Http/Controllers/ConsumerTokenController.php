<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\Node;
use App\Services\ConsumerTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consumer token endpoints (#496).
 *
 * GET  /api/v1/directory-key     — returns directory Ed25519 public key (nodes cache for offline verify)
 * POST /api/v1/consumer-token    — issues a short-lived consumer token (requires node_token auth)
 *
 * Spec: spec/iicp-dir.md §3.10
 */
class ConsumerTokenController extends Controller
{
    public function __construct(private ConsumerTokenService $tokens) {}

    /** GET /api/v1/directory-key — public key for consumer token offline verification. */
    public function publicKey(): JsonResponse
    {
        $pubHex = $this->tokens->publicKeyHex();

        if ($pubHex === null) {
            return response()->json([
                'error' => [
                    'code' => 'not_configured',
                    'message' => 'Consumer token signing key not configured on this directory.',
                ],
            ], 503);
        }

        return response()->json(['public_key' => $pubHex, 'algorithm' => 'ed25519']);
    }

    /** POST /api/v1/consumer-token — issue consumer token for a target node + intent. */
    public function issue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target_node_id' => ['required', 'string', 'max:64'],
            'intent' => ['required', 'string', 'max:256'],
        ]);

        // Caller identity comes from NodeTokenAuth middleware.
        /** @var Node $caller */
        $caller = $request->input('_authenticated_node');

        if ($this->tokens->publicKeyHex() === null) {
            return response()->json([
                'error' => [
                    'code' => 'not_configured',
                    'message' => 'Consumer token signing key not configured on this directory.',
                ],
            ], 503);
        }

        // Target node must exist.
        $target = Node::find($validated['target_node_id']);
        if ($target === null) {
            return response()->json([
                'error' => ['code' => 'not_found', 'message' => 'Target node not found.'],
            ], 404);
        }

        $result = $this->tokens->issue($caller->node_id, $target->node_id, $validated['intent']);

        return response()->json([
            'token' => $result['token'],
            'expires_at' => $result['expires_at'],
            'caller_node_id' => $caller->node_id,
            'target_node_id' => $target->node_id,
            'intent' => $validated['intent'],
        ], 201);
    }
}

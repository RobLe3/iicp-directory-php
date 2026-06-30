<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\Node;
use App\Services\RelayBindTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Directory-mediated relay bind tickets (#510 / DIR-RELAY-03).
 *
 * POST /api/v1/relay/ticket — authenticated with the worker's node_token.
 * The ticket authorizes only that worker node_id to bind to a relay audience.
 */
class RelayTicketController extends Controller
{
    public function __construct(private RelayBindTicketService $tickets) {}

    public function issue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'relay_node_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        /** @var Node $worker */
        $worker = $request->input('_authenticated_node');
        $relayAudience = (string) ($validated['relay_node_id'] ?? '*');

        $result = $this->tickets->issue($worker->id, $relayAudience);
        if ($result === null) {
            return response()->json([
                'error' => [
                    'code' => 'not_configured',
                    'message' => 'Relay bind ticket signing key not configured on this directory.',
                ],
            ], 503);
        }

        return response()->json([
            'ticket' => $result['token'],
            'expires_at' => $result['expires_at'],
            'worker_node_id' => $worker->id,
            'relay_node_id' => $relayAudience,
            'algorithm' => 'ed25519',
        ], 201);
    }
}

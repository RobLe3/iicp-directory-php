<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\Node;
use App\Services\NodeEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DeregisterController extends Controller
{
    public function __construct(private NodeEventLogger $eventLogger) {}

    public function __invoke(Request $request): JsonResponse
    {
        $node = $request->input('_authenticated_node');

        if (! $node instanceof Node) {
            return response()->json([
                'error' => ['code' => 'IICP-E003', 'message' => 'Node not found'],
            ], 404);
        }

        // Emit signed event log entry before deletion (Phase 6 prereq — spec §3.4)
        $this->eventLogger->log('DEREGISTER', $node->id, [
            'endpoint' => $node->endpoint,
            'region' => $node->region,
        ]);

        $node->delete();

        // #346 — release one slot from the per-IP register rate-limit window
        // so an operator rotating identity can re-register without waiting
        // out the cooldown. Floor at 0 (decrement on a missing key is a
        // no-op via Cache::decrement returning false).
        $rateLimitKey = 'reg_rate:'.($request->ip() ?? 'unknown');
        if (Cache::get($rateLimitKey) > 0) {
            Cache::decrement($rateLimitKey);
        }

        return response()->json(['deregistered' => true], 200);
    }
}

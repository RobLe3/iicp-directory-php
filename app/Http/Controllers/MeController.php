<?php

// SPDX-License-Identifier: Apache-2.0

/**
 * GET /api/v1/me — echo the directory's view of the calling node.
 *
 * Diagnostic endpoint operators hit to verify "what does iicp.network think my
 * node looks like right now?" — most useful when the observed source IP drifts
 * from the declared endpoint (NAT, mismatched DNS, port-forward typos). Pairs
 * with the install.sh public_endpoint auto-detect logic (W-019 / iter-319).
 *
 * Implements:
 *   - DIR-ADDR-08 — observed-IP echo for client-side debugging
 *
 * Returns ONLY data the caller is already entitled to know: their own
 * node_id, the IP we last saw them from, and the endpoint they registered.
 * No reputation, no credits, no peer-list — those have their own endpoints
 * with their own auth posture.
 */

namespace App\Http\Controllers;

use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Node $node */
        $node = $request->get('_authenticated_node');

        return response()->json([
            'node_id' => $node->id,
            'observed_source_ip' => $node->observed_source_ip,
            'endpoint' => $node->endpoint,
        ]);
    }
}

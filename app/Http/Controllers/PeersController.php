<?php

// SPDX-License-Identifier: Apache-2.0

/**
 * GET /api/v1/peers — directory-side fallback for gossip-driven peer discovery.
 *
 * Sister endpoint to Bootstrap: where Bootstrap is used by cold-start nodes,
 * Peers is consulted by warm nodes that want to expand their known peer set
 * without a full re-bootstrap. Clients submit their current `known_peers`;
 * the directory replies with up to MAX_PEERS_OUT additional candidates
 * (i.e. set difference, not the whole list).
 *
 * Implements:
 *   - ADR-009 §peers — IICP-DIR peer exchange contract
 *   - spec/iicp-dir.md §peers — request/response shape
 *
 * Mesh design intent (ADR-001): the directory is a discovery convenience, not
 * the gossip substrate. Adapters speak peer-to-peer via POST /v1/peers on each
 * other (signed, nonce-protected). This endpoint exists so nodes can heal
 * partitions without depending on a continuously-reachable peer.
 */

namespace App\Http\Controllers;

use App\Models\Node;
use App\Services\OtelTracer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PeersController extends Controller
{
    /** Same 3× heartbeat liveness window as Bootstrap (see BootstrapController). */
    private const EXPIRY_SECONDS = 90;

    /** Cap on incoming `known_peers` to keep validation costs bounded. */
    private const MAX_KNOWN_IN = 20;

    /** Cap on emitted peers — matches the typical adapter peer-manager budget. */
    private const MAX_PEERS_OUT = 10;

    /**
     * Return up-to-MAX_PEERS_OUT alive peers not already in `known_peers`.
     *
     * Filtering by `whereNotIn` is small-N-safe because MAX_KNOWN_IN bounds the
     * incoming set; for large mesh sizes the query plan still scans an index on
     * `available + last_seen`.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $span = OtelTracer::startSpan($request, 'iicp.directory.peers');

        $validated = $request->validate([
            'known_peers' => ['sometimes', 'array', 'max:'.self::MAX_KNOWN_IN],
            'known_peers.*' => ['string', 'max:36', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/'],
        ]);

        $cutoff = Carbon::now()->subSeconds(self::EXPIRY_SECONDS);

        $query = Node::query()
            ->where('available', true)
            ->where('last_seen', '>=', $cutoff)
            ->orderByDesc('last_seen')
            ->limit(self::MAX_PEERS_OUT)
            ->select(['id', 'endpoint', 'region', 'last_seen']);

        if (! empty($validated['known_peers'])) {
            $query->whereNotIn('id', $validated['known_peers']);
        }

        $peers = $query->get()
            ->map(fn (Node $node) => [
                'node_id' => $node->id,
                'endpoint' => $node->endpoint,
                'region' => $node->region,
                'last_seen' => $node->last_seen?->toIso8601String(),
            ])
            ->values()
            ->all();

        $knownCount = count($validated['known_peers'] ?? []);
        $span->setAttribute('iicp.peers.known_in', $knownCount)
            ->setAttribute('iicp.peers.returned', count($peers));
        $span->end();

        return response()->json([
            'peers' => $peers,
            'count' => count($peers),
        ]);
    }
}

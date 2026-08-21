<?php

// SPDX-License-Identifier: Apache-2.0

/**
 * GET /api/v1/bootstrap — seed peer list for newly-joined nodes.
 *
 * When `iicp-node` starts cold it has no peers and cannot participate in the
 * gossip mesh. Bootstrap returns a small (DEFAULT_LIMIT) list of recently
 * heartbeating nodes so the new node can seed its peer manager and begin
 * gossiping autonomously. Once seeded, nodes prefer Peers / gossip and stop
 * polling Bootstrap — this keeps the directory off the hot path.
 *
 * Implements:
 *   - ADR-009 §bootstrap — IICP-DIR sub-protocol bootstrap contract
 *   - spec/iicp-dir.md §bootstrap — endpoint shape and recency window
 *
 * Liveness filter: only nodes whose `last_seen` is within EXPIRY_SECONDS are
 * returned (3× the 30s heartbeat cadence). This bounds the "ghost peer" rate
 * during partition recovery without being so tight that a network blip wipes
 * the seed list.
 */

namespace App\Http\Controllers;

use App\Models\Node;
use App\Models\TrustDomainMembership;
use App\Services\OtelTracer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    /**
     * Liveness window — 3× the 30s heartbeat cadence.
     *
     * Trade-off: a tighter window (e.g. 60s) churns seed lists during normal
     * Cloudflare reconnects; a looser window (180s+) leaks dead peers that
     * waste a new node's gossip budget on unreachable endpoints.
     */
    private const EXPIRY_SECONDS = 90;

    /** Small default — enough to bootstrap gossip without flooding the new node. */
    private const DEFAULT_LIMIT = 5;

    /** Hard cap — prevents abuse as a "list every node" enumeration endpoint. */
    private const MAX_LIMIT = 20;

    /**
     * Return up to {limit} recently-active peers, sorted by recency.
     *
     * Public mode exposes the legacy bootstrap list. Restricted mode is
     * authenticated by route middleware and projects peer-verifiable membership
     * evidence for independently admitted peers.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $span = OtelTracer::startSpan($request, 'iicp.directory.bootstrap');

        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $limit = $validated['limit'] ?? self::DEFAULT_LIMIT;
        $cutoff = Carbon::now()->subSeconds(self::EXPIRY_SECONDS);

        $restricted = (bool) config('iicp.restricted_domain.enabled');
        $memberships = $restricted ? TrustDomainMembership::query()
            ->where('domain_id', (string) config('iicp.restricted_domain.domain_id'))
            ->where('subject_kind', 'node')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where('generation', '>=', (int) config('iicp.restricted_domain.membership_epoch', 1))
            ->whereNotNull('membership_envelope')
            ->get()
            ->filter(fn (TrustDomainMembership $membership): bool => in_array('*', $membership->scopes ?? [], true)
                || in_array('bootstrap', $membership->scopes ?? [], true)
                || in_array('peers', $membership->scopes ?? [], true))
            ->keyBy('subject_id') : collect();

        $nodes = Node::query()
            ->where('available', true)
            ->where('last_seen', '>=', $cutoff)
            ->orderByDesc('last_seen')
            ->limit($limit)
            ->get(['id', 'endpoint', 'region', 'last_seen'])
            ->map(function (Node $node) use ($restricted, $memberships): ?array {
                $membership = $memberships->get($node->id);
                if ($restricted && ! $membership) {
                    return null;
                }
                $peer = [
                    'node_id' => $node->id,
                    'endpoint' => $node->endpoint,
                    'region' => $node->region,
                    'last_seen' => $node->last_seen?->toIso8601String(),
                ];
                if ($restricted) {
                    $peer['membership'] = $membership->membership_envelope;
                }

                return $peer;
            })
            ->filter()
            ->values()
            ->all();

        $span->setAttribute('iicp.bootstrap.limit', $limit)
            ->setAttribute('iicp.bootstrap.peers_returned', count($nodes));
        $span->end();

        return response()->json([
            'peers' => $nodes,
            'count' => count($nodes),
        ]);
    }
}

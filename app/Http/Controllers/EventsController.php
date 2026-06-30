<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\NodeEvent;
use App\Services\OtelTracer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 6 prerequisite: signed event log endpoint.
 *
 * Spec: spec/iicp-federated-directory.md §3.4
 * GET /api/v1/events?since_seq=<N>&limit=<M>&event_types=<csv>
 *
 * Replicas consume this feed to maintain locally consistent state.
 * Signatures are Ed25519 over the canonical per-event byte string (§3.4 signing input).
 */
class EventsController extends Controller
{
    private const DEFAULT_LIMIT = 100;

    private const MAX_LIMIT = 1000;

    public function index(Request $request): JsonResponse
    {
        $span = OtelTracer::startSpan($request, 'iicp.directory.events.index');

        $validated = $request->validate([
            'since_seq' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'event_types' => ['sometimes', 'nullable', 'string', 'max:256'],
        ]);

        $sinceSeq = (int) ($validated['since_seq'] ?? 0);
        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $eventTypes = isset($validated['event_types'])
            ? array_filter(array_map('trim', explode(',', $validated['event_types'])))
            : [];

        $query = NodeEvent::query()
            ->where('seq', '>', $sinceSeq)
            ->orderBy('seq');

        if (! empty($eventTypes)) {
            $valid = array_intersect($eventTypes, NodeEvent::TYPES);
            if (empty($valid)) {
                return response()->json(['error' => 'No valid event_types specified'], 422);
            }
            $query->whereIn('event_type', $valid);
        }

        $events = $query->limit($limit)->get();

        $nextSeq = $events->isEmpty() ? $sinceSeq : $events->last()->seq;
        $hasMore = $events->count() === $limit;

        $span->setAttribute('iicp.events.count', $events->count())
            ->setAttribute('iicp.events.next_seq', $nextSeq)
            ->setAttribute('iicp.events.has_more', $hasMore);
        $span->end();

        $signerDid = 'did:web:'.parse_url(config('app.url'), PHP_URL_HOST);

        return response()->json([
            'events' => $events->map(fn ($e) => [
                'event_id' => $e->event_id,
                'event_type' => $e->event_type,
                'seq' => $e->seq,
                'ts_ms' => $e->ts_ms,
                'node_id' => $e->node_id,
                'payload' => $e->payload,
                'prev_hash' => $e->prev_hash,
                'sig' => $e->signature,
                'signer_did' => $signerDid,
            ])->all(),
            'count' => $events->count(),
            'next_seq' => $nextSeq,
            'has_more' => $hasMore,
            'genesis_hash' => $this->genesisHash(),
            'replica_lag_ms' => $this->replicaLagMs(),
        ]);
    }

    /** SHA256 of the first (genesis) event's canonical payload — spec DIR-FED-07. */
    private function genesisHash(): ?string
    {
        return Cache::remember('events:genesis_hash', 3600, function (): ?string {
            $genesis = NodeEvent::orderBy('seq')->first();
            if ($genesis === null) {
                return null;
            }
            $canonical = json_encode($genesis->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            return hash('sha256', $canonical);
        });
    }

    /**
     * Milliseconds between the DB-wide latest event's ts_ms and now — spec §5.2.
     *
     * Must use the absolute latest event, not just the last event on the returned page.
     * Paginated responses otherwise underreport lag (e.g. a replica 3 900 events behind
     * with limit=100 would see near-zero lag from the page's last item).
     */
    private function replicaLagMs(): int
    {
        $latest = NodeEvent::orderByDesc('seq')->value('ts_ms');
        if ($latest === null) {
            return 0;
        }

        return max(0, (int) (microtime(true) * 1000) - (int) $latest);
    }
}

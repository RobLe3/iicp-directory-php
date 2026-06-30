<?php

// SPDX-License-Identifier: Apache-2.0

/**
 * GET /api/v1/nodes/{id} — single-node read endpoint for the public registry.
 *
 * Returns the latest known state (endpoint, region, load, capabilities) for a
 * given node UUID. Unauthenticated by design: discovery + node detail are part
 * of the public registry surface (ADR-017) so anyone can verify a node exists,
 * inspect its declared capabilities, and audit its current load. No secrets
 * (node_token, keys, billing internals) are ever returned here.
 *
 * Used by:
 *   - website /nodes detail page (operator audit)
 *   - REACH probes verifying capability advertisements
 *   - proxy debug tooling when a fallback exhausts and we want full state
 *
 * Side effects: none. Eager-loads capabilities + availability windows in a
 * single query to keep the response shape stable across phases.
 */

namespace App\Http\Controllers;

use App\Models\Node;
use App\Services\NodeHealthService;
use App\Services\NodeScorer;
use App\Services\UptimeService;
use Illuminate\Http\JsonResponse;

class NodeController extends Controller
{
    public function __construct(
        private NodeHealthService $health,
        private UptimeService $uptime,
        private NodeScorer $scorer,
    ) {}

    /**
     * Render a single Node + capabilities + availability windows.
     *
     * Returns 404 with a structured `error` envelope when the row is absent —
     * the envelope shape matches the rest of the API (code + message) so SDK
     * error handlers can branch on `error.code` without parsing free text.
     */
    public function show(string $id): JsonResponse
    {
        $node = Node::with('capabilities', 'availabilityWindows', 'reputation')->find($id);

        if (! $node) {
            return response()->json([
                'error' => ['code' => 'not_found', 'message' => 'Node not found'],
            ], 404);
        }

        $rep = $node->reputation;
        $completedTasksCount = $rep?->completed_tasks_count ?? 0;
        $health = $this->health->forNode($node);

        return response()->json([
            'node_id' => $node->id,
            'endpoint' => $node->endpoint,
            'region' => $node->region,
            'available' => $node->available,
            'load' => $node->load,
            'active_jobs' => $node->active_jobs,
            'last_seen' => $node->last_seen?->toISOString(),
            'reputation_score' => $rep?->score ?? 0.5,
            'probation' => $completedTasksCount < 100,
            'completed_tasks_count' => $completedTasksCount,
            'observed_latency_ms' => $rep?->observed_latency_ms,
            ...NodeScorer::performanceSignals($node),
            // ADR-044 (#372) — composed per-node health vector (score 0–100,
            // label, component sub-scores). `observed` is true once an
            // independent (proxy/directory) signal backs a component.
            'health' => $health,
            ...NodeScorer::routingSignals($node, $health),
            ...NodeScorer::complianceSignals($node),
            'capability_summary' => $this->scorer->capabilitySummary($node),
            // #508 — verified cumulative uptime from signed event log. Used for
            // merit-based badge assignment (measured uptime, not approximation).
            'uptime' => $this->uptime->uptimeForNode($node->id),
            'exposure_mode' => $node->exposure_mode,
            // #503 — indicates whether this node has a key-backed operator identity
            // (ran `iicp-node init`). False = anonymous; no founder/recognition standing.
            'operator_verified' => (bool) $node->operator_verified,
            // #495 §3.6 — Ed25519 gossip signing key hex. Receivers resolve this to verify
            // incoming PEER_EXCHANGE signatures without holding any directory credential.
            'public_key' => $node->gossip_public_key,
            'capabilities' => $node->capabilities->map(fn ($c) => [
                'intent' => $c->intent,
                'models' => $c->models,
                'max_tokens' => $c->max_tokens,
                'input_modalities' => $c->input_modalities ?: ['text'],
            ]),
        ]);
    }
}

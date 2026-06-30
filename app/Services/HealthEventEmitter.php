<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;

/**
 * HealthEventEmitter — ADR-048 / #374, seed side.
 *
 * Publishes each active provider node's health vector (ADR-044 {@see NodeHealthService::forNode})
 * to the signed event log as a `HEALTH` event, stamped with this directory's `evaluator_did`.
 * Replicas apply these via {@see ReplicaEventApplier::applyHealth} into per-evaluator snapshots;
 * the federation-wide mesh_health read then resolves each node by majority-vote across evaluators.
 *
 * Emission is idempotent in effect (a replica keeps only the latest snapshot per evaluator by
 * `evaluated_at_ms`), so re-emitting on a cadence is safe. Caller owns the cadence (a scheduled
 * command or the probe loop) — this service just maps health → signed events.
 */
class HealthEventEmitter
{
    public function __construct(
        private readonly NodeHealthService $health,
        private readonly NodeEventLogger $logger,
    ) {}

    /**
     * Emit one signed HEALTH event per active provider node. Returns the number emitted.
     */
    public function emitForActiveNodes(): int
    {
        $did = $this->evaluatorDid();
        $count = 0;
        foreach ($this->health->activeProviderNodes() as $node) {
            $this->emitForNode($node, $did);
            $count++;
        }

        return $count;
    }

    /**
     * Build the HEALTH payload for one node and append it to the signed log. The wire
     * `score` is the ADR-044 forNode score normalized to [0,1]; `evaluated_at_ms` is the
     * monotonic staleness key the replica resolves on.
     */
    public function emitForNode(Node $node, ?string $evaluatorDid = null): void
    {
        $vector = $this->health->forNode($node);
        $this->logger->log('HEALTH', (string) $node->id, [
            'score' => round(((int) $vector['score']) / 100.0, 3),
            'label' => $vector['label'] ?? null,
            'components' => $vector['components'] ?? null,
            'evaluated_at' => $vector['evaluated_at'] ?? now()->toIso8601String(),
            'evaluated_at_ms' => (int) (microtime(true) * 1000),
            'evaluator_did' => $evaluatorDid ?? $this->evaluatorDid(),
        ]);
    }

    /**
     * This directory's evaluator identity. Prefers an explicit `app.seed_did`; otherwise
     * derives `did:web:<host>` from `app.url` (stable per seed identity).
     */
    public function evaluatorDid(): string
    {
        $explicit = config('app.seed_did');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'iicp.network';

        return 'did:web:'.$host;
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Services\HealthEventEmitter;
use App\Services\ReplicaEventApplier;
use Illuminate\Console\Command;

/**
 * Emit signed HEALTH events for the seed's active provider nodes — ADR-048 / #374.
 *
 * Each run publishes one HEALTH event per active node (the ADR-044 per-node health
 * vector, stamped with this directory's evaluator_did) onto the signed event log, so
 * replicas can apply them ({@see ReplicaEventApplier::applyHealth}) and
 * compute a federation-consistent mesh_health by majority-vote across evaluators.
 *
 * Cadence is an operator concern (cron / scheduler), mirroring iicp:probe-nodes. A
 * minute-ish cadence keeps the replicated health fresh relative to the 90s heartbeat
 * TTL; re-emitting is safe (replicas keep only the latest per evaluator by evaluated_at).
 */
class EmitHealthCommand extends Command
{
    protected $signature = 'iicp:emit-health';

    protected $description = 'Emit signed HEALTH events for active provider nodes (ADR-048 #374)';

    public function handle(HealthEventEmitter $emitter): int
    {
        $count = $emitter->emitForActiveNodes();
        $this->info("Emitted {$count} HEALTH event(s) as ".$emitter->evaluatorDid());

        return Command::SUCCESS;
    }
}

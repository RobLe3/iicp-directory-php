<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase A stopgap / W-044 partial fix: prune HEARTBEAT rows from node_events.
 *
 * HEARTBEAT events are state-observation, not state-change. Spec amendment to
 * exclude them from the federated event log (D4prime / S.13 §5.3) requires
 * ARCS sign-off and is not yet shipped. This command is the operational
 * stopgap: weekly delete of HEARTBEAT rows older than --days (default 7).
 *
 * The cleanup is safe because:
 *   - Heartbeat liveness is independently tracked on `nodes.last_seen_at`
 *   - REGISTER/DEREGISTER/CREDIT_AWARD/REPLICA_REGISTERED events are NOT touched
 *   - Replicas joining outside the hot window bootstrap from snapshot anyway
 *
 * When D4prime ships (HeartbeatController stops emitting HEARTBEAT to event log),
 * this command becomes a no-op and can be retired alongside the schedule entry.
 *
 * Usage:
 *   php artisan iicp:prune-heartbeat-events
 *   php artisan iicp:prune-heartbeat-events --days=14 --dry-run
 *
 * WARDEN entry: W-044 (HEARTBEAT/NodeAddressObserver bleed).
 */
class PruneHeartbeatEventsCommand extends Command
{
    protected $signature = 'iicp:prune-heartbeat-events '
        .'{--days=7 : Delete HEARTBEAT rows older than this many days} '
        .'{--dry-run : Count rows that would be deleted without actually deleting}';

    protected $description = 'Prune HEARTBEAT events from node_events (W-044 stopgap until D4prime)';

    public const DEFAULT_RETENTION_DAYS = 7;

    public const EVENT_TYPE = 'HEARTBEAT';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');

        // ts_ms is unix-epoch milliseconds (per S.13 event format)
        $cutoffMs = (int) ((microtime(true) - ($days * 86400)) * 1000);

        $query = DB::table('node_events')
            ->where('event_type', self::EVENT_TYPE)
            ->where('ts_ms', '<', $cutoffMs);

        $rowsToPrune = (int) $query->count();

        if ($dryRun) {
            $this->info("DRY-RUN: would prune {$rowsToPrune} HEARTBEAT rows older than {$days}d (cutoff ts_ms={$cutoffMs})");

            return self::SUCCESS;
        }

        if ($rowsToPrune === 0) {
            $this->info("No HEARTBEAT rows older than {$days}d to prune");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        Log::info('node_events HEARTBEAT prune', [
            'rows_deleted' => $deleted,
            'retention_days' => $days,
            'cutoff_ts_ms' => $cutoffMs,
        ]);

        $this->info("Pruned {$deleted} HEARTBEAT rows older than {$days}d");

        return self::SUCCESS;
    }
}

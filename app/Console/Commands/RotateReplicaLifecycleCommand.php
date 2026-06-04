<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Models\Replica;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase A.3 / ADR-039 §5.5: replicas lifecycle auto-decommission.
 *
 * Mirrors the nodes lifecycle pattern:
 *   active → dormant  after 7d  no heartbeat (last_seen_at)
 *   dormant → archived after 30d (drops from /.well-known/iicp-replicas.json)
 *   archived → decommissioned after 1y (soft-purge; row retained for audit)
 *
 * Schedule: daily at 03:45 (after reputation window rotation at 03:30).
 *
 * Usage:
 *   php artisan iicp:rotate-replica-lifecycle
 *   php artisan iicp:rotate-replica-lifecycle --dry-run
 *
 * Spec/ADR: ADR-039 §5.5; verified-retention-plan-2026-05-26.md §1.10.
 */
class RotateReplicaLifecycleCommand extends Command
{
    protected $signature = 'iicp:rotate-replica-lifecycle '
        .'{--dry-run : List transitions without applying changes}';

    protected $description = 'Transition replica lifecycle states based on last_seen_at age (Phase A.3 / ADR-039)';

    // Lifecycle thresholds (days)
    public const DORMANT_AFTER_DAYS = 7;

    public const ARCHIVED_AFTER_DAYS = 30;

    public const DECOMMISSIONED_AFTER_DAYS = 365;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();

        $transitions = [
            'dormant' => 0,
            'archived' => 0,
            'decommissioned' => 0,
        ];

        // Get all non-decommissioned replicas
        $replicas = Replica::query()
            ->where('status', '!=', Replica::STATUS_DECOMMISSIONED)
            ->get();

        foreach ($replicas as $replica) {
            $lastSeen = $replica->last_seen_at;
            $newStatus = $this->determineStatus($replica->status, $lastSeen, $now);

            if ($newStatus === $replica->status) {
                continue;
            }

            if ($dryRun) {
                $daysSinceSeen = $lastSeen ? $now->diffInDays($lastSeen) : 'never';
                $this->line(sprintf(
                    '  [dry-run] %s: %s → %s (last_seen %s days ago)',
                    $replica->replica_id,
                    $replica->status,
                    $newStatus,
                    $daysSinceSeen,
                ));
            } else {
                $replica->update(['status' => $newStatus]);
                Log::info('iicp.replica.lifecycle_transition', [
                    'replica_id' => $replica->replica_id,
                    'from' => $replica->status,
                    'to' => $newStatus,
                    'last_seen_at' => optional($lastSeen)->toIso8601String(),
                ]);
            }
            $transitions[$newStatus]++;
        }

        $verb = $dryRun ? 'Would transition' : 'Transitioned';
        $this->info(sprintf(
            '%s: %d → dormant, %d → archived, %d → decommissioned',
            $verb,
            $transitions['dormant'],
            $transitions['archived'],
            $transitions['decommissioned'],
        ));

        return self::SUCCESS;
    }

    /**
     * Determine the target status given current status + last_seen_at age.
     * Transitions are unidirectional (active → dormant → archived → decommissioned).
     */
    private function determineStatus(string $currentStatus, $lastSeen, $now): string
    {
        // If never seen, treat as freshly registered (last_seen_at NULL → keep current)
        if ($lastSeen === null) {
            return $currentStatus;
        }
        // Carbon 3 diffInDays returns signed float; need absolute integer
        // (sign depends on which side is later — here $lastSeen is always
        // in the past relative to $now)
        $daysSinceSeen = (int) abs($now->diffInDays($lastSeen));

        if ($daysSinceSeen >= self::DECOMMISSIONED_AFTER_DAYS) {
            return Replica::STATUS_DECOMMISSIONED;
        }
        if ($daysSinceSeen >= self::ARCHIVED_AFTER_DAYS) {
            return Replica::STATUS_ARCHIVED;
        }
        if ($daysSinceSeen >= self::DORMANT_AFTER_DAYS) {
            return Replica::STATUS_DORMANT;
        }

        return Replica::STATUS_ACTIVE;
    }
}

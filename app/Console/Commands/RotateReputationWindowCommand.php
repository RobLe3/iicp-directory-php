<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A.2 / ADR-036: rotate the rolling reputation window.
 *
 * For each node whose `recent_window_start` is older than the configured
 * window length (= credit_economy.TTL_days, currently 90):
 *   1. Snapshot current rolling counters into reputation_archive
 *      (quarterly snapshot path — preserves audit history)
 *   2. Reset rolling counters to 0
 *   3. Set recent_window_start = NOW()
 *
 * Schedule: daily at off-peak hours (registered via routes/console.php).
 *
 * Usage:
 *   php artisan iicp:rotate-reputation-window
 *   php artisan iicp:rotate-reputation-window --dry-run
 *
 * Spec/ADR: ADR-036; verified-retention-plan-2026-05-26.md §1.7
 */
class RotateReputationWindowCommand extends Command
{
    protected $signature = 'iicp:rotate-reputation-window '
        .'{--dry-run : List nodes that would be rotated without applying changes} '
        .'{--window-days= : Override window length in days (default: 90)}';

    protected $description = 'Rotate per-node reputation rolling window (Phase A.2 / ADR-036)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        // Window length pinned to credit_economy.TTL_days per ADR-035 / ADR-036.
        // Default 90; can be overridden by --window-days for testing.
        $windowDays = (int) ($this->option('window-days') ?? 90);
        $cutoff = now()->subDays($windowDays);

        $candidates = Node::query()
            ->whereNotNull('recent_window_start')
            ->where('recent_window_start', '<', $cutoff)
            ->whereNotIn('status', ['archived'])
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No nodes due for window rotation.');

            return self::SUCCESS;
        }

        $rotated = 0;
        foreach ($candidates as $node) {
            if ($dryRun) {
                $this->line(sprintf(
                    '  [dry-run] %s — window opened %s; tasks_recent=%d/%d',
                    $node->id,
                    optional($node->recent_window_start)->toDateString(),
                    $node->tasks_total_recent ?? 0,
                    $node->tasks_failed_recent ?? 0,
                ));

                continue;
            }

            DB::transaction(function () use ($node) {
                // Snapshot to reputation_archive (audit preservation).
                // The table is created by deploy/patches/reputation_persistence_v0.5.0.sql
                // in prod; skip gracefully in test DBs without the SQL patch — the
                // rolling-counter reset is the load-bearing change.
                if (Schema::hasTable('reputation_archive')) {
                    DB::table('reputation_archive')->insert([
                        'node_id' => $node->id,
                        'identity_key' => hash('sha256', (string) ($node->node_token_hash ?? $node->id)),
                        'archived_score' => $node->reputation_score ?? 0.5,
                        'tasks_total_at_archive' => $node->tasks_total_recent ?? 0,
                        'tasks_failed_at_archive' => $node->tasks_failed_recent ?? 0,
                        'archive_reason' => 'window_rotation',
                        'archived_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Reset rolling counters (load-bearing change)
                $node->update([
                    'tasks_total_recent' => 0,
                    'tasks_failed_recent' => 0,
                    'avg_latency_ms_recent' => 0,
                    'recent_window_start' => now(),
                ]);
            });

            $rotated++;
        }

        if ($dryRun) {
            $this->info(sprintf('Dry-run: %d node(s) would rotate.', $candidates->count()));
        } else {
            $this->info(sprintf(
                'Rotated %d node(s); window length = %d days.',
                $rotated,
                $windowDays,
            ));
            Log::info('iicp.reputation_window.rotated', [
                'count' => $rotated,
                'window_days' => $windowDays,
            ]);
        }

        return self::SUCCESS;
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Models\Node;
use App\Models\Reputation;
use App\Services\NodeEventLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Apply idle reputation decay — spec §11.3 λ=0.005/hr.
 *
 * Runs hourly via the Laravel scheduler. Applies a fixed -0.005 decrement to
 * every registered node's reputation score. The idle decay floor is 0.30 (REP1
 * §2: nodes cannot decay below bronze/silver boundary through idle alone). Nodes
 * that fall below 0.30 via task-failure penalties are not further harmed by idle
 * decay. Nodes actively serving tasks offset idle decay via ReputationService::upsert()
 * (+0.01 per success).
 *
 * Emits REPUTATION_DECAY events for score changes ≥ 0.001 so audit logs
 * capture meaningful drift, not floating-point noise.
 */
class ReputationDecayCommand extends Command
{
    protected $signature = 'iicp:reputation-decay';

    protected $description = 'Apply idle reputation decay (λ=0.005/hr) to all registered nodes (spec §11.3)';

    private const LAMBDA = 0.005;

    // REP1 §2: idle decay floor — nodes at/below this threshold are not further
    // harmed by idle decay (they may have fallen below via task-failure penalties).
    private const DECAY_FLOOR = 0.30;

    public function handle(NodeEventLogger $logger): int
    {
        $decayed = 0;

        // D2-READ (W-042/D5prime prep): decay over canonical nodes.reputation_score
        // (not legacy reputations.score). After Phase 2 SQL drop, this is the
        // sole source-of-truth.
        $nodes = Node::query()
            ->whereNotIn('status', ['archived'])
            ->select('id', 'reputation_score')
            ->get();

        foreach ($nodes as $node) {
            $oldScore = (float) ($node->reputation_score ?? 0.5);
            $nodeId = $node->id;

            // REP1 §2: idle decay stops at DECAY_FLOOR.
            if ($oldScore <= self::DECAY_FLOOR) {
                continue;
            }

            $newScore = round(max(self::DECAY_FLOOR, $oldScore - self::LAMBDA), 4);

            if ($newScore === round($oldScore, 4)) {
                continue;
            }

            // Optimistic lock on nodes.reputation_score directly. The WHERE
            // on old score ensures we never overwrite a task-driven update
            // (ReputationService::upsert dual-writes the same column).
            $affected = DB::table('nodes')
                ->where('id', $nodeId)
                ->where('reputation_score', round($oldScore, 4))
                ->update(['reputation_score' => $newScore]);

            if ($affected === 0) {
                continue;
            }

            // Keep the legacy `reputations.score` in sync during the transition
            // window — Phase 2 drop removes this write entirely.
            DB::table('reputations')
                ->where('node_id', $nodeId)
                ->update(['score' => $newScore]);

            if (abs($newScore - $oldScore) >= 0.001) {
                $logger->log('REPUTATION_DECAY', $nodeId, [
                    'old_score' => round($oldScore, 4),
                    'new_score' => $newScore,
                    'delta' => round($newScore - $oldScore, 4),
                    'lambda' => self::LAMBDA,
                ]);
            }

            $decayed++;
        }

        $this->info("Reputation decay applied to {$decayed} node(s) (λ=".self::LAMBDA.'/hr).');

        return Command::SUCCESS;
    }
}

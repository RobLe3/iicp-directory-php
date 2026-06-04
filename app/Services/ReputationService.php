<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;
use App\Models\Reputation;

/**
 * Delta-based reputation scoring for the Control Plane (spec/iicp-semantics.md §11.2).
 *
 * WHY delta-based (not rolling average): deltas are additive across heartbeat batches, so a node
 * that consistently delivers fast responses accumulates reputation gradually. A pure rolling
 * average would be gamed by mixing a few successes with many failures.
 *
 * WHY EMA for latency (not raw average): EMA with α=0.1 smooths latency spikes that would
 * otherwise heavily penalize transient load bursts (α=0.1 means the current reading contributes
 * 10%, history contributes 90%). Score deltas still use the EMA-smoothed latency.
 *
 * WHY LATENCY_BUDGET_MS = 2000: spec §11.1 defines interactive QoS latency budget as 2s.
 * Tasks delivered within 2s → +0.01; within 4s → ±0.0; above 4s → -0.05 (DELTA_LATENCY_BREACH).
 *
 * WHY emit REPUTATION_UPDATE event (Phase 6 prereq): the event log is the federated control
 * plane's audit trail (spec §3.4). Replicas must be able to reconstruct reputation state from
 * events. Writing a REPUTATION_UPDATE on every score change ensures that invariant holds.
 *
 * Spec: spec/iicp-semantics.md §11.2. ADR: ADR-008 (reputation weight in scoring).
 */
class ReputationService
{
    public function __construct(private NodeEventLogger $eventLogger) {}

    // EMA decay for latency smoothing only
    private const EMA_ALPHA = 0.1;

    // Normative delta constants — spec §11.2 (iicp-semantics.md)
    private const DELTA_SUCCESS = 0.01;

    private const DELTA_OK = 0.0;

    private const DELTA_LATENCY_BREACH = -0.05;

    private const DELTA_FAILURE = -0.05;

    // Default latency budget — interactive QoS class (spec §11.1)
    private const LATENCY_BUDGET_MS = 2000.0;

    // RT-01 (#375): cap positive delta per heartbeat call to prevent instant score
    // inflation. One heartbeat can contribute at most +0.10 (10 tasks' worth),
    // requiring sustained genuine throughput to reach max score.
    private const MAX_POSITIVE_DELTA_PER_HEARTBEAT = 0.10;

    // RT-01b (#381): per-node hourly reputation velocity ceiling.
    // Limits total positive gain to +0.20/hour/node regardless of heartbeat frequency.
    // Prevents fleet-scale attack: 60 nodes × MAX_POSITIVE_DELTA_PER_HEARTBEAT × many calls.
    public const MAX_HOURLY_REPUTATION_GAIN = 0.20;

    public function upsert(string $nodeId, int $tasksSuccess, int $tasksFailed, float $avgLatencyMs): void
    {
        $rep = Reputation::firstOrCreate(
            ['node_id' => $nodeId],
            ['score' => 0.5, 'tasks_total' => 0, 'tasks_failed' => 0, 'completed_tasks_count' => 0, 'avg_latency_ms' => 0.0],
        );

        $newTotal = $rep->tasks_total + $tasksSuccess + $tasksFailed;
        $newFailed = $rep->tasks_failed + $tasksFailed;
        $newCompleted = $rep->completed_tasks_count + $tasksSuccess;

        $newLatency = $rep->avg_latency_ms === 0.0
            ? $avgLatencyMs
            : (self::EMA_ALPHA * $avgLatencyMs + (1 - self::EMA_ALPHA) * $rep->avg_latency_ms);

        // Delta-based score update — spec §11.2: sum deltas, then clamp to [0.0, 1.0]
        $delta = $this->computeDelta($tasksSuccess, $tasksFailed, $avgLatencyMs);

        // RT-01b (#381): per-node hourly velocity ceiling. Check rolling 1h window on nodes.
        $node = Node::find($nodeId);
        if ($node && $delta > 0) {
            $windowStart = $node->rep_hourly_window_start;
            $hourlyGain = (float) ($node->rep_hourly_gain ?? 0.0);

            if ($windowStart === null || $windowStart->diffInSeconds(now()) >= 3600) {
                // Window expired — reset
                $hourlyGain = 0.0;
                $node->rep_hourly_window_start = now();
            }

            // Cap delta to remaining hourly budget
            $remaining = max(0.0, self::MAX_HOURLY_REPUTATION_GAIN - $hourlyGain);
            if ($delta > $remaining) {
                $delta = $remaining;
            }
        }

        $newScore = min(1.0, max(0.0, $rep->score + $delta));

        $oldScore = $rep->score;

        $rep->update([
            'tasks_total' => $newTotal,
            'tasks_failed' => $newFailed,
            'completed_tasks_count' => $newCompleted,
            'avg_latency_ms' => $newLatency,
            'score' => round($newScore, 4),
        ]);

        // W-042 / db-D2prime + D2prime-followup: dual-write all canonical
        // denormalized fields to nodes. After Phase 2 SQL drop of `reputations`,
        // these columns become the sole source-of-truth.
        //
        // Phase A.2 / ADR-036: also increment rolling-window counters
        // (window = credit_economy.TTL_days; ReputationDecayCommand rotates
        // when window expires). Lifetime totals stay for audit; scoring
        // uses _recent fields.
        if ($node) {
            $nodeUpdate = [
                'reputation_score' => round($newScore, 4),
                'tasks_total' => $newTotal,
                'tasks_failed' => $newFailed,
                'avg_latency_ms' => $newLatency,
                // Rolling window — same delta as lifetime; nightly cron resets to 0 on window boundary
                'tasks_total_recent' => ($node->tasks_total_recent ?? 0) + $tasksSuccess + $tasksFailed,
                'tasks_failed_recent' => ($node->tasks_failed_recent ?? 0) + $tasksFailed,
                'avg_latency_ms_recent' => $newLatency,  // EMA already smoothed
                'recent_window_start' => $node->recent_window_start ?? now(),
            ];

            // RT-01b: persist velocity window state
            if ($delta > 0) {
                $nodeUpdate['rep_hourly_gain'] = ($node->rep_hourly_gain ?? 0.0) + $delta;
                $nodeUpdate['rep_hourly_window_start'] = $node->rep_hourly_window_start ?? now();
            }

            $node->update($nodeUpdate);
        }

        // W-042 / db-D4prime: REPUTATION_UPDATE event emission removed.
        // Per S.13 v0.3.0 (db-D3prime): HEARTBEAT / SCORE_UPDATE /
        // REPUTATION_UPDATE are no longer part of the federated event log;
        // replicas derive current reputation from `nodes.reputation_score`
        // (snapshot+event-tail model). REPUTATION_DECAY events still emit
        // (audit-load-bearing — automated hourly cron, kept for audit chain).
    }

    private function computeDelta(int $tasksSuccess, int $tasksFailed, float $avgLatencyMs): float
    {
        $delta = 0.0;

        if ($tasksSuccess > 0) {
            $perSuccess = match (true) {
                $avgLatencyMs <= self::LATENCY_BUDGET_MS => self::DELTA_SUCCESS,
                $avgLatencyMs <= self::LATENCY_BUDGET_MS * 2.0 => self::DELTA_OK,
                default => self::DELTA_LATENCY_BREACH,
            };
            $delta += $tasksSuccess * $perSuccess;
        }

        $delta += $tasksFailed * self::DELTA_FAILURE;

        // RT-01: cap positive delta so a single heartbeat cannot inflate score from
        // 0.5 → 1.0 in one call (prevents self-attestation abuse via inflated task counts).
        if ($delta > self::MAX_POSITIVE_DELTA_PER_HEARTBEAT) {
            $delta = self::MAX_POSITIVE_DELTA_PER_HEARTBEAT;
        }

        return $delta;
    }
}

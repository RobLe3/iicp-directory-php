<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;
use App\Models\TelemetryProbe;
use Illuminate\Support\Collection;

/**
 * NodeHealthService — ADR-044, Phase A (issue #372).
 *
 * Health is a per-node property first; mesh health is a derived aggregate.
 *
 * Prior to ADR-044 the only health surface was `mesh_health`, a single global
 * score dominated by directory-infrastructure signals (discover latency +
 * conformance) plus a global self-reported task sum — it never reflected the
 * average health of the nodes in the mesh. This service computes a per-node
 * health vector from signals already collected (no new node-side wire
 * contract, no schema change) and aggregates them into a mesh-health figure
 * that is the *median* of active provider nodes, with the full distribution
 * exposed so a few good nodes cannot mask a tail of bad ones.
 *
 * Phase A uses self-attested reachability; Phase B (#373) swaps in directory
 * active-probe verification once origin IPv6 egress exists.
 */
class NodeHealthService
{
    /** A heartbeat older than this means the node is offline (gate → score 0, excluded from mesh). */
    private const HEARTBEAT_TTL_SECONDS = 90;

    /** Below this many active nodes, a single mesh number is not meaningful. */
    private const MIN_MESH_SAMPLE = 3;

    // Component weights — sum to 1.0. Reachability leads because an unreachable
    // node cannot serve regardless of how good its other signals look.
    private const W_REACHABILITY = 0.30;

    private const W_LATENCY = 0.25;

    private const W_SUCCESS = 0.25;

    private const W_REPUTATION = 0.20;

    /**
     * Per-node health vector. Returns score (0–100), label, the component
     * sub-scores, and `observed` — true when at least one component is backed
     * by an independent signal (proxy-observed latency) rather than the node's
     * own self-report.
     */
    public function forNode(Node $node): array
    {
        if ($this->liveness($node) === 0.0) {
            return [
                'score' => 0,
                'label' => 'offline',
                'observed' => false,
                'components' => [
                    'liveness' => 0.0,
                    'reachability' => null,
                    'latency' => null,
                    'success_rate' => null,
                    'reputation' => null,
                ],
                'evaluated_at' => now()->toIso8601String(),
            ];
        }

        $reach = $this->reachabilityScore($node);
        [$lat, $latObserved] = $this->latencyScore($node);
        $succ = $this->successScore($node);
        $rep = $this->reputationScore($node);

        $score01 = self::W_REACHABILITY * $reach
            + self::W_LATENCY * $lat
            + self::W_SUCCESS * $succ
            + self::W_REPUTATION * $rep;
        $score = (int) round($score01 * 100);

        return [
            'score' => $score,
            'label' => $this->label($score),
            'observed' => $latObserved,
            'components' => [
                'liveness' => 1.0,
                'reachability' => round($reach, 3),
                'latency' => round($lat, 3),
                'success_rate' => round($succ, 3),
                'reputation' => round($rep, 3),
            ],
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Mesh health — median of per-node health over active provider nodes, with
     * the distribution and 10th-percentile (worst-decile) exposed. Clients are
     * never modeled in the directory, so this is providers only by construction.
     */
    public function meshHealth(Collection $activeNodes): array
    {
        $healths = $activeNodes->map(fn (Node $n) => $this->forNode($n));
        $scores = $healths->pluck('score')
            ->reject(fn ($s) => $s === null)
            ->sort()
            ->values();
        $sample = $scores->count();

        if ($sample === 0) {
            return [
                // 0.0 satisfies the float[0,1] contract (DIR-STATS-01 probe);
                // label:"unavailable" carries the "no active nodes" semantic.
                'score' => 0.0,
                'label' => 'unavailable',
                'mean' => null,
                'p10' => null,
                'distribution' => $this->emptyDistribution(),
                'sample' => 0,
                'basis' => 'active_provider_nodes',
                'window' => 'live',
            ];
        }

        $median = $this->percentile($scores, 50);

        // Normalize internal 0–100 integer scores to [0, 1] floats for the wire format.
        // Internal label() + percentile() remain on the 0–100 scale.
        return [
            'score' => round($median / 100.0, 3),
            'label' => $sample < self::MIN_MESH_SAMPLE ? 'insufficient_sample' : $this->label($median),
            'mean' => round($scores->avg() / 100.0, 3),
            'p10' => round($this->percentile($scores, 10) / 100.0, 3),
            'distribution' => $this->distribution($healths),
            'sample' => $sample,
            'basis' => 'active_provider_nodes',
            'window' => 'live',
        ];
    }

    /** Provider nodes that are available with a fresh heartbeat — the mesh's serving set. */
    public function activeProviderNodes(): Collection
    {
        return Node::with('reputation')
            ->where('available', true)
            ->where('last_seen', '>=', now()->subSeconds(self::HEARTBEAT_TTL_SECONDS))
            ->get();
    }

    private function liveness(Node $node): float
    {
        if ($node->last_seen === null) {
            return 0.0;
        }

        return $node->last_seen->gte(now()->subSeconds(self::HEARTBEAT_TTL_SECONDS)) ? 1.0 : 0.0;
    }

    /**
     * Phase B (#373): uses directory active-probe result when a recent probe exists.
     * Falls back to Phase A self-attested signal when no probe is on record.
     *
     * A probe is "recent" when it is within the last 10 minutes — matches the
     * every-5-minute probe cadence with a 2× safety margin.
     */
    private function reachabilityScore(Node $node): float
    {
        $recentProbe = TelemetryProbe::where('node_id', $node->id)
            ->where('probe_type', 'reachability')
            ->where('probed_at', '>=', now()->subMinutes(10))
            ->orderByDesc('probed_at')
            ->first(['passed']);

        if ($recentProbe !== null) {
            return $recentProbe->passed ? 1.0 : 0.0;
        }

        // Phase A fallback: self-attested public_reachable flag.
        if ($node->public_reachable) {
            return 1.0;
        }

        return $node->relay_capable ? 0.5 : 0.0;
    }

    /**
     * Prefer proxy-observed latency (independent) over the node's self-reported
     * average; prefer the rolling recent window over lifetime. Returns
     * [score, observed] where observed=true means an independent signal was used.
     * ≤ 50ms → 1.0, ≥ 500ms → 0.0; no data → neutral 0.5.
     */
    private function latencyScore(Node $node): array
    {
        // observed_latency_ms (proxy-observed) is the independent signal. The
        // self-reported columns default to 0.0 for a never-served node, so a
        // value of 0 means "no measurement yet", not "0ms" — treat ≤0 as no data
        // and prefer the rolling recent window over lifetime when it has data.
        $observed = $node->reputation?->observed_latency_ms;
        if ($observed !== null && $observed > 0) {
            return [$this->latencyCurve((float) $observed), true];
        }

        foreach ([$node->avg_latency_ms_recent, $node->avg_latency_ms] as $self) {
            if ($self !== null && $self > 0) {
                return [$this->latencyCurve((float) $self), false];
            }
        }

        return [0.5, false];
    }

    private function latencyCurve(float $ms): float
    {
        return max(0.0, min(1.0, 1.0 - ($ms - 50.0) / 450.0));
    }

    /**
     * Success ratio from the rolling recent window when present, else lifetime.
     * 100% → 1.0, ≤ 70% → 0.0; no completed tasks yet → neutral 0.5.
     */
    private function successScore(Node $node): float
    {
        [$total, $failed] = $this->taskCounts($node);

        if ($total <= 0) {
            return 0.5;
        }

        $pct = (($total - $failed) / $total) * 100.0;

        return max(0.0, min(1.0, ($pct - 70.0) / 30.0));
    }

    /**
     * Prefer the rolling recent window when it has any completed tasks, else
     * lifetime totals. The recent columns default to 0 (not null), so we test
     * for > 0 rather than for presence.
     */
    private function taskCounts(Node $node): array
    {
        if ((int) ($node->tasks_total_recent ?? 0) > 0) {
            return [(int) $node->tasks_total_recent, (int) ($node->tasks_failed_recent ?? 0)];
        }

        return [(int) ($node->tasks_total ?? 0), (int) ($node->tasks_failed ?? 0)];
    }

    private function reputationScore(Node $node): float
    {
        $score = $node->reputation_score ?? $node->reputation?->score ?? 0.5;

        return max(0.0, min(1.0, (float) $score));
    }

    private function label(int $score): string
    {
        return match (true) {
            $score >= 85 => 'healthy',
            $score >= 65 => 'degraded',
            $score >= 40 => 'impaired',
            default => 'critical',
        };
    }

    /** Nearest-rank percentile over a pre-sorted, 0-indexed collection of ints. */
    private function percentile(Collection $sorted, int $p): int
    {
        $n = $sorted->count();
        if ($n === 0) {
            return 0;
        }
        $rank = (int) ceil(($p / 100.0) * $n);
        $idx = max(0, min($n - 1, $rank - 1));

        return (int) $sorted->get($idx);
    }

    private function distribution(Collection $healths): array
    {
        $dist = $this->emptyDistribution();
        foreach ($healths as $h) {
            $label = $h['label'];
            if (isset($dist[$label])) {
                $dist[$label]++;
            }
        }

        return $dist;
    }

    private function emptyDistribution(): array
    {
        return ['healthy' => 0, 'degraded' => 0, 'impaired' => 0, 'critical' => 0, 'offline' => 0];
    }
}

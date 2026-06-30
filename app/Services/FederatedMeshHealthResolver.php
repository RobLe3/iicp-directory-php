<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\NodeHealthObservation;
use Illuminate\Support\Collection;

/**
 * FederatedMeshHealthResolver — ADR-048 / #374, slice 2 (the read).
 *
 * Resolves each node's canonical health from the per-evaluator snapshots replicated via
 * HEALTH events (slice 1), then aggregates a federation-wide mesh_health over the union of
 * known nodes. Any replica that has applied the same HEALTH events produces the same number.
 *
 * Conflict/staleness rule (ADR-048, maintainer choice = MAJORITY-VOTE / quorum):
 *   - Each distinct evaluator_did casts ONE vote per node (its latest snapshot).
 *   - Votes are bucketed by ADR-044 health LABEL (the discrete agreement unit — exact
 *     float equality across independent evaluators is unrealistic). The label held by a
 *     strict majority (> half) of evaluators wins; the canonical score is the median of
 *     that bucket's scores.
 *   - No majority (tie/split) → most-recent by evaluated_at_ms, node flagged `contested`.
 *   - Fewer than QUORUM distinct evaluators → freshest single snapshot, flagged
 *     `unconfirmed` (the small-mesh degradation ADR-048 documents).
 *
 * Pure function of the stored observations + ADR-044 label thresholds — no node-side wire
 * contract, no local serving signals (so a replica with only mirrored rows agrees with the seed).
 */
class FederatedMeshHealthResolver
{
    /** Distinct evaluator DIDs required before a majority is "confirmed" (RT-03b spirit). */
    public const QUORUM = 3;

    private const MIN_MESH_SAMPLE = 3;

    public function __construct(private readonly NodeHealthService $health) {}

    /**
     * Resolve one node's canonical health from its per-evaluator observations.
     *
     * @param  Collection<int, NodeHealthObservation>  $obs  one row per evaluator for this node
     * @return array{score: float, label: string, resolution: string, evaluators: int, contested: bool}
     */
    public function resolveNode(Collection $obs): array
    {
        $evaluators = $obs->count();
        if ($evaluators === 0) {
            return ['score' => 0.0, 'label' => 'unavailable', 'resolution' => 'none', 'evaluators' => 0, 'contested' => false];
        }

        $freshest = $obs->sortByDesc('evaluated_at_ms')->first();

        // Below quorum: the freshest single snapshot, marked unconfirmed.
        if ($evaluators < self::QUORUM) {
            $score = (float) $freshest->score;

            return [
                'score' => round($score, 3),
                'label' => $this->health->labelForScore((int) round($score * 100)),
                'resolution' => 'unconfirmed',
                'evaluators' => $evaluators,
                'contested' => false,
            ];
        }

        // Bucket each evaluator's vote by health label; find a strict-majority label.
        $byLabel = $obs->groupBy(fn (NodeHealthObservation $o) => $this->health->labelForScore((int) round(((float) $o->score) * 100)));
        $needed = intdiv($evaluators, 2) + 1;
        $majority = $byLabel->first(fn (Collection $bucket) => $bucket->count() >= $needed);

        if ($majority !== null) {
            $score = $this->median($majority->map(fn (NodeHealthObservation $o) => (float) $o->score));

            return [
                'score' => round($score, 3),
                'label' => $this->health->labelForScore((int) round($score * 100)),
                'resolution' => 'majority',
                'evaluators' => $evaluators,
                'contested' => false,
            ];
        }

        // Quorum present but no majority label → most-recent wins, flagged contested.
        $score = (float) $freshest->score;

        return [
            'score' => round($score, 3),
            'label' => $this->health->labelForScore((int) round($score * 100)),
            'resolution' => 'most_recent',
            'evaluators' => $evaluators,
            'contested' => true,
        ];
    }

    /**
     * Federation-wide mesh_health over the union of nodes that have any HEALTH observation.
     * Mirrors the ADR-044 wire shape (score/label/mean/p10/distribution/sample) so it is a
     * drop-in alongside the single-directory figure, plus federation provenance.
     */
    public function federatedMeshHealth(): array
    {
        $grouped = NodeHealthObservation::all()->groupBy('node_id');

        if ($grouped->isEmpty()) {
            return [
                'score' => 0.0,
                'label' => 'unavailable',
                'mean' => null,
                'p10' => null,
                'distribution' => $this->emptyDistribution(),
                'sample' => 0,
                'contested' => 0,
                'unconfirmed' => 0,
                'basis' => 'federated_union',
                'window' => 'replicated',
            ];
        }

        $resolved = $grouped->map(fn (Collection $obs) => $this->resolveNode($obs))->values();
        $scores = $resolved->map(fn ($r) => $r['score'])->sort()->values();
        $sample = $scores->count();

        return [
            'score' => round($this->percentile($scores, 50), 3),
            'label' => $sample < self::MIN_MESH_SAMPLE
                ? 'insufficient_sample'
                : $this->health->labelForScore((int) round($this->percentile($scores, 50) * 100)),
            'mean' => round($scores->avg(), 3),
            'p10' => round($this->percentile($scores, 10), 3),
            'distribution' => $this->distribution($resolved),
            'sample' => $sample,
            'contested' => $resolved->where('contested', true)->count(),
            'unconfirmed' => $resolved->where('resolution', 'unconfirmed')->count(),
            'basis' => 'federated_union',
            'window' => 'replicated',
        ];
    }

    /** Median over an unsorted collection of [0,1] floats. */
    private function median(Collection $values): float
    {
        return $this->percentile($values->sort()->values(), 50);
    }

    /** Nearest-rank percentile over a pre-sorted, 0-indexed collection of floats. */
    private function percentile(Collection $sorted, int $p): float
    {
        $n = $sorted->count();
        if ($n === 0) {
            return 0.0;
        }
        $rank = (int) ceil(($p / 100.0) * $n);
        $idx = max(0, min($n - 1, $rank - 1));

        return (float) $sorted->get($idx);
    }

    private function distribution(Collection $resolved): array
    {
        $dist = $this->emptyDistribution();
        foreach ($resolved as $r) {
            $label = $r['label'];
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

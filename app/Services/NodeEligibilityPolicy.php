<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;
use App\Models\Reputation;
use Illuminate\Support\Collection;

final class NodeEligibilityPolicy
{
    /**
     * @param  Collection<int,Node>  $nodes
     * @return Collection<int,Node>
     */
    public function filter(
        Collection $nodes,
        ?string $model,
        ?string $qos,
        float $minReputation,
    ): Collection {
        $nodes = $nodes->filter(
            fn (Node $node) => $node->health_models === null || count($node->health_models) > 0
        );
        $nodes = $nodes->filter(
            fn (Node $node) => BackendStabilityPolicy::allowsAdmission($node)
        );

        if ($model !== null) {
            $nodes = $nodes->filter(fn (Node $node) => $this->matchesModel($node, $model));
        }

        if ($qos === 'realtime') {
            $nodes = $nodes->filter(function (Node $node): bool {
                $reputation = $this->reputation($node);

                return $reputation !== null
                    && $reputation->completed_tasks_count >= 1000
                    && $reputation->score >= 0.8;
            });
        } elseif ($qos === 'interactive') {
            $nodes = $nodes->filter(
                fn (Node $node) => $this->completedTasks($node) >= 100
            );
        }

        if ($minReputation > 0.0) {
            $nodes = $nodes->filter(
                fn (Node $node) => $this->reputationScore($node) >= $minReputation
            );
        }

        return $nodes;
    }

    private function matchesModel(Node $node, string $model): bool
    {
        if ($node->health_models !== null) {
            return in_array($model, $node->health_models, true);
        }

        foreach ($node->capabilities as $capability) {
            if (in_array($model, $capability->models ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    private function reputation(Node $node): ?Reputation
    {
        $reputation = $node->reputation;

        return $reputation instanceof Reputation ? $reputation : null;
    }

    private function completedTasks(Node $node): int
    {
        $reputation = $this->reputation($node);

        return $reputation === null ? 0 : $reputation->completed_tasks_count;
    }

    private function reputationScore(Node $node): float
    {
        $reputation = $this->reputation($node);

        return $reputation === null ? 0.5 : $reputation->score;
    }
}

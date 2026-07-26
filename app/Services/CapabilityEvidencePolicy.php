<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Capability;
use App\Models\Node;

/**
 * Descriptive model and modality evidence used by discovery scoring.
 *
 * This policy does not query, filter, rank, or project nodes. NodeScorer keeps
 * those protocol responsibilities and delegates only capability calculations.
 */
class CapabilityEvidencePolicy
{
    /**
     * @param  list<string>|null  $registeredModels
     * @param  list<string>|null  $liveModels
     * @return array{model_count_registered:int,model_count_live:int,model_family_count:int,modalities:list<mixed>,context_window_max:mixed,quality_evidence:string}
     */
    public function summary(Node $node, ?array $registeredModels = null, ?array $liveModels = null): array
    {
        $registeredModels ??= $this->registeredModels($node);
        $liveModels ??= $this->liveModels($node, $registeredModels);
        $families = array_values(array_unique(array_map($this->modelFamily(...), $liveModels)));
        $families = array_values(array_filter($families, fn (string $family) => $family !== 'unknown'));

        return [
            'model_count_registered' => count($registeredModels),
            'model_count_live' => count($liveModels),
            'model_family_count' => count($families),
            'modalities' => $node->capabilities
                ->flatMap(fn (Capability $capability) => $capability->input_modalities ?: ['text'])->unique()->values()->all(),
            'context_window_max' => $node->capabilities->pluck('max_tokens')->filter()->max(),
            'quality_evidence' => count($liveModels) > 0 ? 'self_declared' : 'none',
        ];
    }

    /** @param array<string,mixed> $summary */
    public function fitScore(array $summary, ?string $requestedModel): float
    {
        $breadth = min(1.0, log(1 + max(0, (int) $summary['model_count_live']), 2) / log(9, 2));
        $modality = in_array('text', $summary['modalities'] ?? [], true) ? 1.0 : 0.5;
        $qualityEvidence = ($summary['quality_evidence'] ?? 'none') === 'none' ? 0.0 : 0.5;
        $exactModel = $requestedModel === null ? 0.5 : 1.0;

        return round(0.45 * $exactModel + 0.30 * $breadth + 0.15 * $modality + 0.10 * $qualityEvidence, 4);
    }

    public function exactModelMatch(Node $node, string $model): float
    {
        foreach ($node->capabilities as $capability) {
            if (in_array($model, $capability->models ?? [], true)) {
                return 1.0;
            }
        }

        return 0.0;
    }

    /** @return list<string> */
    public function registeredModels(Node $node): array
    {
        return $node->capabilities->flatMap(fn ($capability) => $capability->models ?? [])->unique()->values()->all();
    }

    /** @param list<string> $registeredModels
     * @return list<string>
     */
    public function liveModels(Node $node, array $registeredModels): array
    {
        return $node->health_models !== null
            ? array_values(array_intersect($registeredModels, $node->health_models))
            : $registeredModels;
    }

    private function modelFamily(string $model): string
    {
        $normalized = strtolower($model);
        foreach (['llama', 'qwen', 'mistral', 'deepseek', 'phi', 'gemma', 'nomic', 'mixtral', 'codellama'] as $family) {
            if (str_contains($normalized, $family)) {
                return $family;
            }
        }

        return 'unknown';
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;
use App\Models\Reputation;

/**
 * Computes the established discovery scores without querying or ordering nodes.
 */
final class NodeRankingPolicy
{
    private const W_AVAILABILITY = 0.35;

    private const W_LOAD = 0.28;

    private const W_CAPACITY = 0.18;

    private const W_REGION = 0.09;

    private const W_REPUTATION = 0.10;

    private const P5_W_AVAILABILITY = 0.25;

    private const P5_W_LOAD = 0.20;

    private const P5_W_CAPACITY = 0.15;

    private const P5_W_REGION = 0.10;

    private const P5_W_REPUTATION = 0.10;

    private const P5_W_PRICE = 0.10;

    private const P5_W_MODEL = 0.10;

    public function __construct(
        private CapabilityEvidencePolicy $capabilityEvidence,
        private AvailabilityWindowPolicy $availabilityWindows,
        private NodeReadinessPolicy $readiness,
    ) {}

    public function score(Node $node, ?string $requestedRegion, ?string $requestedModel = null): float
    {
        $availabilityScore = $this->availabilityWindows->score($node);
        $loadScore = 1.0 - min($node->load, 1.0);
        $capacityScore = $node->max_concurrent > 0
            ? 1.0 - ($node->active_jobs / $node->max_concurrent)
            : 0.0;
        $regionScore = match (true) {
            $requestedRegion === null => 0.5,
            $node->region === $requestedRegion => 1.0,
            default => 0.0,
        };
        $reputationScore = $this->reputationScore($node);

        if ($requestedModel !== null) {
            $modelMatch = $this->capabilityEvidence->exactModelMatch($node, $requestedModel);
            $priceScore = $node->pricing_credits_per_1000 !== null
                ? max(0.0, 1.0 - ($node->pricing_credits_per_1000 / 10.0))
                : 0.5;

            $score = (self::P5_W_AVAILABILITY * $availabilityScore)
                + (self::P5_W_LOAD * $loadScore)
                + (self::P5_W_CAPACITY * max(0.0, $capacityScore))
                + (self::P5_W_REGION * $regionScore)
                + (self::P5_W_REPUTATION * $reputationScore)
                + (self::P5_W_PRICE * $priceScore)
                + (self::P5_W_MODEL * $modelMatch);
        } else {
            $score = (self::W_AVAILABILITY * $availabilityScore)
                + (self::W_LOAD * $loadScore)
                + (self::W_CAPACITY * max(0.0, $capacityScore))
                + (self::W_REGION * $regionScore)
                + (self::W_REPUTATION * $reputationScore);
        }

        return $score * $this->readiness->multiplier($node);
    }

    /**
     * @param  array<string,mixed>  $health
     * @param  array<string,mixed>  $capabilitySummary
     * @return array<string,mixed>
     */
    public function shadowV2(
        Node $node,
        array $health,
        array $capabilitySummary,
        ?string $requestedModel,
    ): array {
        $healthScore = max(0.0, min(1.0, ((float) ($health['score'] ?? 0)) / 100.0));
        $capabilityFit = $this->capabilityEvidence->fitScore($capabilitySummary, $requestedModel);
        $loadScore = 1.0 - min($node->load, 1.0);
        $capacityScore = $node->max_concurrent > 0
            ? max(0.0, 1.0 - ($node->active_jobs / $node->max_concurrent))
            : 0.0;
        $loadCapacity = round(($loadScore + $capacityScore) / 2.0, 4);
        $reputation = $this->reputationScore($node);
        $latency = (float) ($health['components']['latency'] ?? 0.5);
        $price = $node->pricing_credits_per_1000 !== null
            ? max(0.0, min(1.0, 1.0 - ($node->pricing_credits_per_1000 / 10.0)))
            : 0.5;
        $policy = $this->readiness->multiplier($node);

        $score = 0.25 * $healthScore
            + 0.20 * $capabilityFit
            + 0.15 * $loadCapacity
            + 0.15 * $reputation
            + 0.10 * $latency
            + 0.05 * $healthScore
            + 0.05 * $price
            + 0.05 * $policy;

        return [
            'routing_score_v2' => round($score, 4),
            'routing_score_v2_components' => [
                'health' => round($healthScore, 4),
                'capability_fit' => $capabilityFit,
                'load_capacity' => $loadCapacity,
                'reputation' => round($reputation, 4),
                'latency' => round($latency, 4),
                'uptime_stability' => round($healthScore, 4),
                'price' => round($price, 4),
                'policy_fit' => round($policy, 4),
            ],
        ];
    }

    private function reputation(Node $node): ?Reputation
    {
        $reputation = $node->reputation;

        return $reputation instanceof Reputation ? $reputation : null;
    }

    private function reputationScore(Node $node): float
    {
        $reputation = $this->reputation($node);

        return $reputation === null ? 0.5 : (float) $reputation->score;
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Validate node pricing declarations and apply compute-tier ceilings.
 */
final class NodePricingPolicy
{
    private const TIER_WEIGHTS = [
        'sub_1b' => 0.05,
        '7b' => 1.0,
        '13b' => 2.0,
        '30b' => 6.5,
        '70b' => 32.0,
        '100b_plus' => 75.0,
    ];

    /**
     * @param  array<string>  $advertisedModels
     */
    public function resolve(array $pricing, string $hmacKey, array $advertisedModels = []): array
    {
        $multiplier = isset($pricing['credit_cost_multiplier'])
            ? (float) $pricing['credit_cost_multiplier']
            : 1.0;
        $model = $pricing['pricing_model'] ?? 'per_token';
        $signature = $pricing['declaration_signature'] ?? null;
        $attested = false;

        if ($signature !== null) {
            $body = ['credit_cost_multiplier' => $multiplier, 'pricing_model' => $model];
            ksort($body);
            $expected = hash_hmac('sha256', json_encode($body, JSON_THROW_ON_ERROR), $hmacKey);
            if (! hash_equals($expected, $signature)) {
                throw ValidationException::withMessages([
                    'pricing.declaration_signature' => 'Invalid declaration signature (IICP-E010)',
                ]);
            }
            $attested = true;
        }

        if ($advertisedModels !== []) {
            $tier = $this->classifyModelTier($advertisedModels);
            $ceiling = self::TIER_WEIGHTS[$tier] * 3.0;
            if ($multiplier > $ceiling) {
                Log::warning('NodeRegistry: multiplier clamped by tier ceiling', [
                    'declared' => $multiplier,
                    'tier' => $tier,
                    'ceiling' => $ceiling,
                ]);
                $multiplier = $ceiling;
            }
        }

        return [
            'credit_cost_multiplier' => $multiplier,
            'pricing_model' => $model,
            'declaration_signature' => $signature,
            'attested' => $attested,
            'effective_from' => $pricing['effective_from'] ?? null,
            'effective_until' => $pricing['effective_until'] ?? null,
        ];
    }

    /**
     * @param  array<string>  $models
     */
    public function classifyModelTier(array $models): string
    {
        $maxWeight = 0.0;
        $bestTier = '7b';

        foreach ($models as $name) {
            if (! preg_match('/(\d+(?:\.\d+)?)\s*[bB](?:[^a-zA-Z]|$)/', (string) $name, $matches)) {
                continue;
            }

            $parameters = (float) $matches[1];
            $tier = match (true) {
                $parameters < 1 => 'sub_1b',
                $parameters < 10 => '7b',
                $parameters < 20 => '13b',
                $parameters < 50 => '30b',
                $parameters < 85 => '70b',
                default => '100b_plus',
            };

            if (self::TIER_WEIGHTS[$tier] > $maxWeight) {
                $maxWeight = self::TIER_WEIGHTS[$tier];
                $bestTier = $tier;
            }
        }

        return $bestTier;
    }
}

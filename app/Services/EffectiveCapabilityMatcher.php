<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use DateTimeImmutable;

/** Binding-neutral matcher for urn:iicp:profile:effective-capability:v1. */
final class EffectiveCapabilityMatcher
{
    private const FIELD_BY_CLASS = [
        'input_modality' => 'input_modalities',
        'output_modality' => 'output_modalities',
        'feature' => 'features',
        'execution_capability' => 'execution_capabilities',
        'profile' => 'supported_profiles',
        'extension' => 'extensions',
    ];

    public function match(array $advertisement, array $request, array $vocabulary, ?string $evaluatedAt = null, array $policyDenials = []): array
    {
        $required = $request['requires'] ?? [];
        foreach ($required as $requirement) {
            if (! $this->known($vocabulary, $requirement)) {
                return $this->refusal('required_capability_unknown');
            }
            if (in_array($requirement, $policyDenials, true)) {
                return $this->refusal('capability_policy_denied');
            }
        }

        $candidates = array_values(array_filter(
            $advertisement['capabilities'],
            fn (array $candidate): bool => $candidate['intent'] === $request['intent']
                && $this->hasEvery($candidate, $required),
        ));
        if ($candidates === []) {
            return $this->refusal('required_capability_unsupported');
        }

        $now = new DateTimeImmutable($evaluatedAt ?? 'now');
        $candidates = array_values(array_filter($candidates, function (array $candidate) use ($now): bool {
            $until = $candidate['claim_provenance']['valid_until'] ?? null;

            return $until === null || new DateTimeImmutable($until) >= $now;
        }));
        if ($candidates === []) {
            return $this->refusal('required_capability_stale');
        }

        $candidates = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => $this->limitsMatch($candidate, $request['limits'] ?? []),
        ));
        if ($candidates === []) {
            return $this->refusal('capability_limit_unsatisfied');
        }

        return [
            'eligible' => true,
            'variant_ids' => array_map(fn (array $candidate) => $candidate['variant_id'] ?? null, $candidates),
            'preference_unavailable' => collect($request['prefers'] ?? [])->contains(
                fn (array $preference): bool => ! $this->known($vocabulary, $preference)
            ),
        ];
    }

    private function known(array $vocabulary, array $requirement): bool
    {
        if ($requirement['class'] === 'extension') {
            return false;
        }

        return in_array($requirement['id'], $vocabulary[$requirement['class']] ?? [], true);
    }

    private function hasEvery(array $candidate, array $requirements): bool
    {
        foreach ($requirements as $requirement) {
            $field = self::FIELD_BY_CLASS[$requirement['class']];
            if (! array_key_exists($requirement['id'], array_flip($candidate[$field] ?? []))) {
                return false;
            }
        }

        return true;
    }

    private function limitsMatch(array $candidate, array $requirements): bool
    {
        foreach ($requirements as $required) {
            $actual = $candidate['limits'][$required['id']] ?? null;
            if ($actual === null || $actual['unit'] !== $required['unit']) {
                return false;
            }
            $matches = match ($required['operator']) {
                'gte' => $actual['value'] >= $required['value'],
                'lte' => $actual['value'] <= $required['value'],
                'eq' => $actual['value'] == $required['value'],
                default => false,
            };
            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    private function refusal(string $code): array
    {
        return ['eligible' => false, 'code' => $code];
    }
}

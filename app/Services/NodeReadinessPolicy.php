<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;

/**
 * Transitional routing policy for SDK currency and CX key readiness.
 */
final class NodeReadinessPolicy
{
    public const SDK_BASELINE_VERSION = '0.7.68';

    public function sdkStatus(?string $version): string
    {
        if ($version === null || trim($version) === '') {
            return 'unknown';
        }

        return $this->versionAtLeast($version, self::SDK_BASELINE_VERSION) ? 'current' : 'downlevel';
    }

    public function multiplier(Node $node): float
    {
        $multiplier = 1.0;
        if ($this->sdkStatus($node->sdk_version) !== 'current') {
            $multiplier -= 0.08;
        }
        if ($node->cx_public_key === null) {
            $multiplier -= 0.07;
        }

        return max(0.75, $multiplier);
    }

    private function versionAtLeast(string $version, string $baseline): bool
    {
        $actual = $this->versionParts($version);
        $required = $this->versionParts($baseline);
        $length = max(count($actual), count($required));

        for ($index = 0; $index < $length; $index++) {
            $actualPart = $actual[$index] ?? 0;
            $requiredPart = $required[$index] ?? 0;
            if ($actualPart > $requiredPart) {
                return true;
            }
            if ($actualPart < $requiredPart) {
                return false;
            }
        }

        return true;
    }

    /** @return list<int> */
    private function versionParts(string $version): array
    {
        $parts = [];
        foreach (explode('.', ltrim(trim($version), 'vV')) as $part) {
            if (! preg_match('/^\d+/', $part, $matches)) {
                break;
            }
            $parts[] = (int) $matches[0];
        }

        return $parts;
    }
}

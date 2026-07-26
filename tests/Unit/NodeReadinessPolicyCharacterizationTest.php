<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Models\Node;
use App\Services\NodeReadinessPolicy;
use App\Services\NodeScorer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NodeReadinessPolicyCharacterizationTest extends TestCase
{
    #[DataProvider('sdkVersions')]
    public function test_sdk_status_preserves_existing_version_interpretation(
        ?string $version,
        string $expected,
    ): void {
        $policy = new NodeReadinessPolicy;

        $this->assertSame($expected, $policy->sdkStatus($version));
        $this->assertSame($expected, NodeScorer::sdkStatus($version));
    }

    public static function sdkVersions(): array
    {
        return [
            'missing' => [null, 'unknown'],
            'blank' => ['  ', 'unknown'],
            'exact baseline' => ['0.7.68', 'current'],
            'lower patch' => ['0.7.67', 'downlevel'],
            'higher patch' => ['0.7.69', 'current'],
            'lower minor despite high patch' => ['0.6.999', 'downlevel'],
            'v prefix' => ['v0.7.68', 'current'],
            'uppercase prefix' => ['V0.7.68', 'current'],
            'short equivalent version' => ['0.7.68', 'current'],
            'long equivalent version' => ['0.7.68.0', 'current'],
            'longer downlevel version' => ['0.7.67.99', 'downlevel'],
            'numeric prerelease suffix follows legacy numeric prefix behavior' => ['0.7.68-beta.1', 'current'],
            'malformed version' => ['not-a-version', 'downlevel'],
        ];
    }

    #[DataProvider('readinessCases')]
    public function test_multiplier_preserves_existing_sdk_and_key_penalties(
        ?string $version,
        ?string $publicKey,
        float $expected,
    ): void {
        $node = new Node;
        $node->sdk_version = $version;
        $node->cx_public_key = $publicKey;

        $this->assertEqualsWithDelta($expected, (new NodeReadinessPolicy)->multiplier($node), 0.000001);
    }

    public static function readinessCases(): array
    {
        return [
            'current and keyed' => ['0.7.68', 'key-material', 1.0],
            'downlevel and keyed' => ['0.7.67', 'key-material', 0.92],
            'current and unkeyed' => ['0.7.68', null, 0.93],
            'downlevel and unkeyed' => ['0.7.67', null, 0.85],
            'unknown and unkeyed' => [null, null, 0.85],
        ];
    }

    public function test_baseline_constant_remains_compatible(): void
    {
        $this->assertSame(NodeReadinessPolicy::SDK_BASELINE_VERSION, NodeScorer::SDK_BASELINE_VERSION);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit\Services;

use App\Services\EffectiveCapabilityMatcher;
use PHPUnit\Framework\TestCase;

class EffectiveCapabilityMatcherTest extends TestCase
{
    public function test_shared_matching_scenarios(): void
    {
        $fixture = json_decode(
            file_get_contents(dirname(__DIR__, 3).'/parity/effective-capability-v1/fixture.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $matcher = new EffectiveCapabilityMatcher;
        foreach ($fixture['matching_scenarios'] as $scenario) {
            $actual = $matcher->match(
                $fixture['advertisement'],
                $scenario['request'],
                $fixture['vocabulary'],
                $scenario['evaluation_time'] ?? $fixture['evaluation_time'],
                $scenario['policy_denials'] ?? [],
            );
            $this->assertSame($scenario['expected']['eligible'], $actual['eligible'], $scenario['name']);
            if ($actual['eligible']) {
                $this->assertSame($scenario['expected']['variant_ids'], $actual['variant_ids'], $scenario['name']);
            } else {
                $this->assertSame($scenario['expected']['refusal']['code'], $actual['code'], $scenario['name']);
            }
        }
    }
}

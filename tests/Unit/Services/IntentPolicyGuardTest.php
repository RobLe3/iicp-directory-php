<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit\Services;

use App\Services\IntentPolicyGuard;
use PHPUnit\Framework\TestCase;

class IntentPolicyGuardTest extends TestCase
{
    public function test_classifies_prohibited_high_risk_transparency_and_minimal_intents(): void
    {
        $guard = new IntentPolicyGuard;

        $this->assertSame(
            IntentPolicyGuard::CATEGORY_PROHIBITED,
            $guard->classify('urn:iicp:intent:social-scoring:score:v1')['category'],
        );
        $this->assertSame(
            IntentPolicyGuard::CATEGORY_HIGH_RISK,
            $guard->classify('urn:iicp:intent:employment:hiring-decision:v1')['category'],
        );
        $this->assertSame(
            IntentPolicyGuard::CATEGORY_TRANSPARENCY_RISK,
            $guard->classify('urn:iicp:intent:creative:generate-public:v1')['category'],
        );
        $this->assertSame(
            IntentPolicyGuard::CATEGORY_MINIMAL_OR_GENERAL,
            $guard->classify('urn:iicp:intent:llm:chat:v1')['category'],
        );
    }

    public function test_public_mesh_refuses_prohibited_and_high_risk_but_not_transparency_or_general(): void
    {
        $guard = new IntentPolicyGuard;

        $this->assertStringContainsString('prohibited', $guard->refusalMessage('urn:iicp:intent:criminal-risk-prediction:v1'));
        $this->assertStringContainsString('high_risk', $guard->refusalMessage('urn:iicp:intent:credit:decision:v1'));
        $this->assertNull($guard->refusalMessage('urn:iicp:intent:ai-assistant:chat:v1'));
        $this->assertNull($guard->refusalMessage('urn:iicp:intent:code:review:v1'));
    }

    public function test_directory_rules_stay_aligned_with_shared_taxonomy_fixture(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../../../../spec/intent-risk-taxonomy.json'), true, flags: JSON_THROW_ON_ERROR);
        $guard = new IntentPolicyGuard;

        $fixtureRules = array_map(fn (array $rule) => [
            'category' => $rule['category'],
            'rule_id' => $rule['rule_id'],
            'label' => $rule['label'],
            'fragments' => $rule['fragments'],
        ], $fixture['rules']);

        $this->assertSame($fixtureRules, $guard->rules());
    }
}

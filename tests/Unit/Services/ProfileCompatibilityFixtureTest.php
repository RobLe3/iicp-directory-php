<?php

namespace Tests\Unit\Services;

use App\Services\IntentPolicyGuard;
use Tests\TestCase;

class ProfileCompatibilityFixtureTest extends TestCase
{
    public function test_policy_cases_in_the_shared_profile_fixture_match_directory_refusal(): void
    {
        $fixturePath = dirname(__DIR__, 3).'/spec/proposals/fixtures/profile-compatibility-v0.json';
        $fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
        $guard = new IntentPolicyGuard;

        $this->assertSame('0.3.0-draft', $fixture['fixture_version']);
        $this->assertSame('pre-normative', $fixture['status']);

        foreach ($fixture['scenarios'] as $scenario) {
            $intent = $scenario['request']['intent'];
            $isRefused = $guard->refusalMessage($intent) !== null;

            if ($scenario['name'] === 'prohibited_policy') {
                $this->assertTrue($isRefused, 'current public-mesh risk guard must refuse prohibited intent');
                $this->assertSame('policy_refusal', $scenario['expected_reason']);
            } elseif (in_array($scenario['name'], ['stable_chat', 'optional_extension', 'a2a_skill_bridge', 'mcp_tool_safe'], true)) {
                $this->assertFalse($isRefused, "ordinary fixture intent must remain eligible: {$scenario['name']}");
                $this->assertSame('compatible', $scenario['expected_reason']);
            }
        }

        // The seed directory currently owns public-mesh intent refusal, while
        // client-side profile eligibility owns encryption/region/manifest checks.
        // Keep that split explicit until the draft profile is ratified.
        $this->assertSame(
            'unsupported_pre_normative_profile',
            $fixture['result_contract']['unsupported_status']
        );
        $this->assertCount(4, $fixture['native_policy_scenarios']);
    }
}

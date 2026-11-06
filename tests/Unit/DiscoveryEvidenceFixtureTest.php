<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Models\Node;
use App\Models\Reputation;
use App\Services\NodeScorer;
use Tests\TestCase;

class DiscoveryEvidenceFixtureTest extends TestCase
{
    public function test_runtime_projection_matches_content_free_discovery_evidence_fixture(): void
    {
        $fixture = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/parity/discovery-evidence-v1.10.94.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $cases = collect($fixture['cases'])->keyBy('id');
        $this->assertSame('0.7.109', $fixture['invariants']['sdk_latest_known_version']);

        $trustInput = $cases['DIR-EVIDENCE-01'];
        $node = new Node(['sdk_version' => '0.7.98']);
        $node->setRelation('reputation', new Reputation([
            'completed_tasks_count' => $trustInput['input']['completed_tasks'],
            'score' => $trustInput['input']['reputation_score'],
            'observed_latency_ms' => $cases['DIR-EVIDENCE-02']['input']['observed_latency_ms'],
        ]));

        $trust = NodeScorer::trustProgress($node);
        foreach ($trustInput['expected'] as $field => $expected) {
            $this->assertSame($expected, $trust[$field]);
        }
        $this->assertSame($cases['DIR-EVIDENCE-02']['expected'], NodeScorer::latencyEvidence($node));

        $missing = new Node;
        $missing->setRelation('reputation', new Reputation);
        $this->assertSame($cases['DIR-EVIDENCE-03']['expected'], NodeScorer::latencyEvidence($missing));

        $sdk = NodeScorer::complianceSignals($node)['sdk_release'];
        foreach ($cases['DIR-EVIDENCE-04']['expected'] as $field => $expected) {
            $this->assertSame($expected, $sdk[$field]);
        }
        $this->assertFalse($fixture['invariants']['identity_material_exposed']);
    }

    public function test_currency_labels_preserve_compatibility_floor(): void
    {
        $this->assertSame('0.7.109', config('app.iicp_sdk_latest_known_version'));
        foreach (['0.7.106' => 'behind_known', '0.7.109' => 'latest_known', '0.7.110' => 'ahead_of_known'] as $version => $relation) {
            $node = new Node(['sdk_version' => $version]);
            $signals = NodeScorer::complianceSignals($node);
            $this->assertSame($relation, $signals['sdk_release']['relation']);
            $this->assertSame('current', $signals['sdk_status']);
            $this->assertSame('0.7.68', $signals['sdk_baseline_version']);
            $this->assertFalse($signals['upgrade_required']);
        }
        $signals = NodeScorer::complianceSignals(new Node(['sdk_version' => '0.7.67']));
        $this->assertSame('downlevel', $signals['sdk_status']);
        $this->assertTrue($signals['upgrade_required']);
    }

    public function test_operator_currency_override_remains_effective(): void
    {
        config(['app.iicp_sdk_latest_known_version' => '0.7.110']);
        $signals = NodeScorer::complianceSignals(new Node(['sdk_version' => '0.7.109']));
        $this->assertSame('0.7.110', $signals['sdk_release']['latest_known_version']);
        $this->assertSame('behind_known', $signals['sdk_release']['relation']);
        $this->assertSame('current', $signals['sdk_status']);
    }

    public function test_historical_fixture_retains_original_currency(): void
    {
        $path = dirname(__DIR__, 2).'/parity/discovery-evidence-v1.json';
        $this->assertSame('7aab59a1d7d2fc88b4638c9a1d0e01ccf45915220d638ce3b1a3f85fdfdcad56', hash_file('sha256', $path));
        $original = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $successor = json_decode(file_get_contents(dirname(__DIR__, 2).'/parity/discovery-evidence-v1.10.94.json'), true, flags: JSON_THROW_ON_ERROR);
        $successor['invariants']['sdk_latest_known_version'] = '0.7.106';
        $this->assertSame($original, $successor);
    }
}

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
            file_get_contents(dirname(__DIR__, 2).'/parity/discovery-evidence-v1.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $cases = collect($fixture['cases'])->keyBy('id');

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
}

<?php

namespace Tests\Unit;

use App\Models\Capability;
use App\Models\Node;
use App\Services\AvailabilityWindowPolicy;
use App\Services\CapabilityEvidencePolicy;
use App\Services\NodeHealthService;
use App\Services\NodeReadinessPolicy;
use App\Services\NodeScorer;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NodeScorerCapabilityEvidenceCharacterizationTest extends TestCase
{
    #[DataProvider('capabilityEvidenceCases')]
    public function test_current_capability_evidence_contract(
        ?array $healthModels,
        array $expectedSummary,
        float $expectedFit,
    ): void {
        $node = $this->node($healthModels);
        $policy = new CapabilityEvidencePolicy;
        $scorer = new NodeScorer(
            $this->createMock(NodeHealthService::class),
            $policy,
            new AvailabilityWindowPolicy,
            new NodeReadinessPolicy,
        );

        $summary = $scorer->capabilitySummary($node);

        $this->assertSame($expectedSummary, $summary);
        $this->assertSame($expectedFit, $policy->fitScore($summary, 'qwen2.5:0.5b'));
    }

    public function test_current_exact_model_match_contract(): void
    {
        $node = $this->node(['qwen2.5:0.5b']);
        $policy = new CapabilityEvidencePolicy;

        $this->assertSame(1.0, $policy->exactModelMatch($node, 'qwen2.5:0.5b'));
        $this->assertSame(0.0, $policy->exactModelMatch($node, 'missing:model'));
    }

    public static function capabilityEvidenceCases(): array
    {
        return [
            'health evidence absent preserves registered models' => [
                null,
                [
                    'model_count_registered' => 3,
                    'model_count_live' => 3,
                    'model_family_count' => 2,
                    'modalities' => ['text', 'image'],
                    'context_window_max' => 8192,
                    'quality_evidence' => 'self_declared',
                ],
                0.8393,
            ],
            'partial live evidence uses the intersection' => [
                ['qwen2.5:0.5b', 'not-registered:model'],
                [
                    'model_count_registered' => 3,
                    'model_count_live' => 1,
                    'model_family_count' => 1,
                    'modalities' => ['text', 'image'],
                    'context_window_max' => 8192,
                    'quality_evidence' => 'self_declared',
                ],
                0.7446,
            ],
            'empty live evidence is explicit' => [
                [],
                [
                    'model_count_registered' => 3,
                    'model_count_live' => 0,
                    'model_family_count' => 0,
                    'modalities' => ['text', 'image'],
                    'context_window_max' => 8192,
                    'quality_evidence' => 'none',
                ],
                0.6,
            ],
        ];
    }

    private function node(?array $healthModels): Node
    {
        $node = new Node;
        $node->health_models = $healthModels;
        $node->setRelation('capabilities', new Collection([
            new Capability([
                'models' => ['qwen2.5:0.5b', 'llama3:latest', 'custom:model'],
                'max_tokens' => 4096,
                'input_modalities' => ['text'],
            ]),
            new Capability([
                'models' => ['qwen2.5:0.5b'],
                'max_tokens' => 8192,
                'input_modalities' => ['text', 'image'],
            ]),
        ]));

        return $node;
    }
}

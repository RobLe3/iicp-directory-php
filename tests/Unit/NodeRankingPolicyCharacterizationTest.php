<?php

namespace Tests\Unit;

use App\Models\Capability;
use App\Models\Node;
use App\Models\Reputation;
use App\Services\AvailabilityWindowPolicy;
use App\Services\CapabilityEvidencePolicy;
use App\Services\NodeEligibilityPolicy;
use App\Services\NodeHealthService;
use App\Services\NodeReadinessPolicy;
use App\Services\NodeScorer;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class NodeRankingPolicyCharacterizationTest extends TestCase
{
    public function test_standard_score_preserves_all_weights_and_region_match(): void
    {
        $result = $this->score($this->node(), 'eu-west', null);

        $this->assertSame(0.878, $result['score']);
    }

    public function test_model_aware_score_preserves_price_and_exact_model_weights(): void
    {
        $node = $this->node();
        $node->pricing_credits_per_1000 = 2.0;

        $result = $this->score($node, 'eu-west', 'model-a');

        $this->assertEqualsWithDelta(0.88, $result['score'], 0.000001);
    }

    public function test_missing_reputation_and_pricing_keep_neutral_defaults_and_readiness_demotion(): void
    {
        $node = $this->node();
        $node->setRelation('reputation', null);
        $node->sdk_version = null;
        $node->cx_public_key = null;

        $result = $this->score($node, null, 'missing-model');

        $this->assertEqualsWithDelta(0.578, $result['score'], 0.000001);
    }

    public function test_over_capacity_is_clamped_to_zero(): void
    {
        $node = $this->node();
        $node->active_jobs = 12;

        $result = $this->score($node, 'other-region', null);

        $this->assertSame(0.644, $result['score']);
    }

    public function test_shadow_v2_preserves_components_and_rounding(): void
    {
        $node = $this->node();
        $node->pricing_credits_per_1000 = 2.0;
        $health = [
            'score' => 83,
            'components' => ['latency' => 0.73],
        ];
        $summary = (new CapabilityEvidencePolicy)->summary($node);
        $method = new ReflectionMethod(NodeScorer::class, 'routingScoreV2');

        $result = $method->invoke($this->scorer(), $node, $health, $summary, 'model-a');

        $this->assertSame([
            'routing_score_v2' => 0.7859,
            'routing_score_v2_components' => [
                'health' => 0.83,
                'capability_fit' => 0.7446,
                'load_capacity' => 0.8,
                'reputation' => 0.7,
                'latency' => 0.73,
                'uptime_stability' => 0.83,
                'price' => 0.8,
                'policy_fit' => 1.0,
            ],
        ], $result);
    }

    private function score(Node $node, ?string $region, ?string $model): array
    {
        $method = new ReflectionMethod(NodeScorer::class, 'scoreNode');

        return $method->invoke($this->scorer(), $node, $region, $model);
    }

    private function scorer(): NodeScorer
    {
        return new NodeScorer(
            $this->createMock(NodeHealthService::class),
            new CapabilityEvidencePolicy,
            new AvailabilityWindowPolicy,
            new NodeReadinessPolicy,
            new NodeEligibilityPolicy,
        );
    }

    private function node(): Node
    {
        $node = new Node;
        $node->load = 0.2;
        $node->max_concurrent = 10;
        $node->active_jobs = 2;
        $node->region = 'eu-west';
        $node->sdk_version = NodeReadinessPolicy::SDK_BASELINE_VERSION;
        $node->cx_public_key = 'key';
        $node->setRelation('reputation', new Reputation([
            'score' => 0.7,
        ]));
        $node->setRelation('availabilityWindows', new Collection);
        $node->setRelation('capabilities', new Collection([
            new Capability([
                'models' => ['model-a'],
                'max_tokens' => 4096,
                'input_modalities' => ['text'],
            ]),
        ]));

        return $node;
    }
}

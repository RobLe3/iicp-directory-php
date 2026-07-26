<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Reputation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class NodeEligibilityCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const INTENT = 'urn:iicp:intent:llm:chat:v1';

    private function createNode(array $overrides = [], array $models = ['model-a']): Node
    {
        $node = Node::create(array_merge([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://'.Str::lower(Str::random(10)).'.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'status' => 'active',
            'load' => 0.2,
            'active_jobs' => 0,
            'last_seen' => now(),
            'public_reachable' => true,
        ], $overrides));

        $node->capabilities()->create([
            'intent' => self::INTENT,
            'models' => $models,
            'max_tokens' => 4096,
        ]);

        return $node;
    }

    private function withReputation(Node $node, float $score, int $completedTasks): void
    {
        Reputation::create([
            'node_id' => $node->id,
            'score' => $score,
            'tasks_total' => max(1, $completedTasks),
            'tasks_failed' => 0,
            'completed_tasks_count' => $completedTasks,
            'avg_latency_ms' => 100,
        ]);
    }

    /** @return list<string> */
    private function discoverIds(string $query = ''): array
    {
        Cache::flush();
        $response = $this->getJson('/api/v1/discover?intent='.self::INTENT.$query)->assertOk();

        return collect($response->json('nodes'))->pluck('node_id')->all();
    }

    public function test_live_model_evidence_precedes_static_capabilities(): void
    {
        $staticFallback = $this->createNode(['health_models' => null], ['model-a']);
        $liveMatch = $this->createNode(['health_models' => ['model-a']], ['different-static-model']);
        $liveMismatch = $this->createNode(['health_models' => ['different-live-model']], ['model-a']);
        $emptyRuntime = $this->createNode(['health_models' => []], ['model-a']);

        $ids = $this->discoverIds('&model=model-a');

        $this->assertContains($staticFallback->id, $ids);
        $this->assertContains($liveMatch->id, $ids);
        $this->assertNotContains($liveMismatch->id, $ids);
        $this->assertNotContains($emptyRuntime->id, $ids);
    }

    public function test_interactive_qos_threshold_is_inclusive_at_one_hundred_tasks(): void
    {
        $below = $this->createNode();
        $atThreshold = $this->createNode();
        $this->withReputation($below, 0.9, 99);
        $this->withReputation($atThreshold, 0.5, 100);

        $ids = $this->discoverIds('&qos=interactive');

        $this->assertNotContains($below->id, $ids);
        $this->assertContains($atThreshold->id, $ids);
    }

    public function test_realtime_qos_requires_both_inclusive_thresholds(): void
    {
        $tooFewTasks = $this->createNode();
        $lowScore = $this->createNode();
        $atThreshold = $this->createNode();
        $this->withReputation($tooFewTasks, 0.9, 999);
        $this->withReputation($lowScore, 0.79, 1000);
        $this->withReputation($atThreshold, 0.8, 1000);

        $ids = $this->discoverIds('&qos=realtime');

        $this->assertNotContains($tooFewTasks->id, $ids);
        $this->assertNotContains($lowScore->id, $ids);
        $this->assertContains($atThreshold->id, $ids);
    }

    public function test_missing_reputation_retains_the_neutral_half_score_default(): void
    {
        $node = $this->createNode();

        $this->assertContains($node->id, $this->discoverIds('&min_reputation=0.5'));
        $this->assertNotContains($node->id, $this->discoverIds('&min_reputation=0.5001'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Reputation;
use App\Services\ReputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #117: Probation tier for new nodes — cold-start reputation floor.
 *
 * Covers spec §11.3 + NodeScorer probation filtering by QoS class.
 */
class ProbationTierTest extends TestCase
{
    use RefreshDatabase;

    private function makeNode(array $overrides = []): Node
    {
        return Node::create(array_merge([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://prob-test.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('tok', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'public_reachable' => true,
        ], $overrides));
    }

    private function withReputation(Node $node, int $completedCount, float $score = 0.5): void
    {
        Reputation::create([
            'node_id' => $node->id,
            'score' => $score,
            'tasks_total' => $completedCount,
            'tasks_failed' => 0,
            'completed_tasks_count' => $completedCount,
            'avg_latency_ms' => 100.0,
        ]);

        $node->capabilities()->create([
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => [],
            'max_tokens' => 4096,
        ]);
    }

    // New node is in probation (< 100 completed tasks)
    public function test_new_node_is_in_probation(): void
    {
        $node = $this->makeNode();
        $this->withReputation($node, completedCount: 0);

        $r = $this->getJson('/api/v1/node/'.$node->id);
        $r->assertStatus(200)
            ->assertJsonPath('probation', true)
            ->assertJsonPath('completed_tasks_count', 0);
    }

    // Node with ≥100 tasks is out of probation
    public function test_graduated_node_is_not_in_probation(): void
    {
        $node = $this->makeNode();
        $this->withReputation($node, completedCount: 100);

        $r = $this->getJson('/api/v1/node/'.$node->id);
        $r->assertStatus(200)
            ->assertJsonPath('probation', false)
            ->assertJsonPath('completed_tasks_count', 100);
    }

    // Probation node excluded from interactive discover
    public function test_probation_node_excluded_from_interactive_discover(): void
    {
        $node = $this->makeNode();
        $this->withReputation($node, completedCount: 50);

        $r = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&qos=interactive');
        $r->assertStatus(200);
        $nodeIds = collect($r->json('nodes'))->pluck('node_id')->all();
        $this->assertNotContains($node->id, $nodeIds, 'Probation node must not appear in interactive discover');
    }

    // Graduated node (≥100 tasks) included in interactive discover
    public function test_graduated_node_included_in_interactive_discover(): void
    {
        $node = $this->makeNode();
        $this->withReputation($node, completedCount: 100);

        $r = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&qos=interactive');
        $r->assertStatus(200);
        $nodeIds = collect($r->json('nodes'))->pluck('node_id')->all();
        $this->assertContains($node->id, $nodeIds, 'Graduated node must appear in interactive discover');
    }

    // Probation node excluded from realtime discover
    public function test_probation_node_excluded_from_realtime_discover(): void
    {
        $node = $this->makeNode();
        $this->withReputation($node, completedCount: 50);

        $r = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&qos=realtime');
        $r->assertStatus(200);
        $nodeIds = collect($r->json('nodes'))->pluck('node_id')->all();
        $this->assertNotContains($node->id, $nodeIds, 'Probation node must not appear in realtime discover');
    }

    // Realtime requires ≥1000 tasks AND reputation ≥ 0.8
    public function test_realtime_requires_1000_tasks_and_high_reputation(): void
    {
        $graduated = $this->makeNode();
        $this->withReputation($graduated, completedCount: 1000, score: 0.85);

        $notEnoughTasks = $this->makeNode(['endpoint' => 'https://node2.example.com']);
        $this->withReputation($notEnoughTasks, completedCount: 500, score: 0.9);

        $lowRep = $this->makeNode(['endpoint' => 'https://node3.example.com']);
        $this->withReputation($lowRep, completedCount: 1000, score: 0.7);

        $r = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&qos=realtime');
        $r->assertStatus(200);
        $nodeIds = collect($r->json('nodes'))->pluck('node_id')->all();

        $this->assertContains($graduated->id, $nodeIds);
        $this->assertNotContains($notEnoughTasks->id, $nodeIds);
        $this->assertNotContains($lowRep->id, $nodeIds);
    }

    // Batch/best-effort: no probation filter applied
    public function test_batch_qos_does_not_filter_probation_nodes(): void
    {
        $node = $this->makeNode();
        $this->withReputation($node, completedCount: 0);

        $r = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&qos=batch');
        $r->assertStatus(200);
        $nodeIds = collect($r->json('nodes'))->pluck('node_id')->all();
        $this->assertContains($node->id, $nodeIds, 'Batch QoS must not filter probation nodes');
    }

    // ReputationService increments completed_tasks_count on success
    public function test_reputation_service_tracks_completed_tasks(): void
    {
        $node = $this->makeNode();
        $service = app(ReputationService::class);

        $service->upsert($node->id, tasksSuccess: 5, tasksFailed: 0, avgLatencyMs: 200.0);

        $rep = $node->reputation()->first();
        $this->assertEquals(5, $rep->completed_tasks_count);

        $service->upsert($node->id, tasksSuccess: 3, tasksFailed: 2, avgLatencyMs: 200.0);
        $rep->refresh();
        $this->assertEquals(8, $rep->completed_tasks_count);
    }

    // GET /v1/node/{id} includes probation + completed_tasks_count + reputation_score
    public function test_node_show_includes_probation_fields(): void
    {
        Http::fake();
        $node = $this->makeNode();
        $this->withReputation($node, completedCount: 42, score: 0.72);

        $r = $this->getJson('/api/v1/node/'.$node->id);
        $r->assertStatus(200)
            ->assertJsonStructure(['probation', 'completed_tasks_count', 'reputation_score'])
            ->assertJsonPath('probation', true)
            ->assertJsonPath('completed_tasks_count', 42)
            ->assertJsonPath('reputation_score', 0.72);
    }
}

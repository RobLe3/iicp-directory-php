<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit\Services;

use App\Models\NodeHealthObservation;
use App\Services\FederatedMeshHealthResolver;
use App\Services\NodeHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-048 / #374 slice 2 — federation-aware mesh_health read.
 *
 * Verifies the majority-vote (quorum) conflict/staleness rule and the union aggregate.
 */
class FederatedMeshHealthResolverTest extends TestCase
{
    use RefreshDatabase;

    private FederatedMeshHealthResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new FederatedMeshHealthResolver(new NodeHealthService);
    }

    private function obs(string $node, string $evaluator, float $score, int $atMs): void
    {
        NodeHealthObservation::create([
            'node_id' => $node,
            'evaluator_did' => $evaluator,
            'score' => $score,
            'label' => null,
            'components' => null,
            'evaluated_at_ms' => $atMs,
            'event_id' => null,
        ]);
    }

    public function test_majority_label_wins_with_quorum(): void
    {
        // 3 evaluators: two see "healthy" (>=0.85), one sees "critical".
        $this->obs('node-a', 'did:web:e1', 0.90, 1000);
        $this->obs('node-a', 'did:web:e2', 0.88, 1000);
        $this->obs('node-a', 'did:web:e3', 0.10, 1000);

        $r = $this->resolver->resolveNode(NodeHealthObservation::where('node_id', 'node-a')->get());

        $this->assertSame('majority', $r['resolution']);
        $this->assertSame('healthy', $r['label']);
        $this->assertFalse($r['contested']);
        // canonical score = median of the healthy bucket {0.90, 0.88} = 0.88 (nearest-rank).
        $this->assertEqualsWithDelta(0.88, $r['score'], 1e-6);
    }

    public function test_no_majority_falls_back_to_most_recent_and_flags_contested(): void
    {
        // 3 evaluators, 3 distinct labels → no strict majority.
        $this->obs('node-a', 'did:web:e1', 0.90, 1000);   // healthy
        $this->obs('node-a', 'did:web:e2', 0.70, 1000);   // degraded
        $this->obs('node-a', 'did:web:e3', 0.45, 3000);   // impaired, freshest

        $r = $this->resolver->resolveNode(NodeHealthObservation::where('node_id', 'node-a')->get());

        $this->assertSame('most_recent', $r['resolution']);
        $this->assertTrue($r['contested']);
        $this->assertEqualsWithDelta(0.45, $r['score'], 1e-6);
    }

    public function test_below_quorum_uses_freshest_and_flags_unconfirmed(): void
    {
        $this->obs('node-a', 'did:web:e1', 0.30, 1000);
        $this->obs('node-a', 'did:web:e2', 0.95, 5000); // freshest

        $r = $this->resolver->resolveNode(NodeHealthObservation::where('node_id', 'node-a')->get());

        $this->assertSame('unconfirmed', $r['resolution']);
        $this->assertFalse($r['contested']);
        $this->assertEqualsWithDelta(0.95, $r['score'], 1e-6);
    }

    public function test_federated_aggregate_over_union(): void
    {
        // node-a healthy (majority), node-b critical (majority), node-c degraded (majority).
        foreach (['e1', 'e2', 'e3'] as $i => $e) {
            $this->obs('node-a', "did:web:$e", 0.90, 1000);
            $this->obs('node-b', "did:web:$e", 0.10, 1000);
            $this->obs('node-c', "did:web:$e", 0.70, 1000);
        }

        $m = $this->resolver->federatedMeshHealth();

        $this->assertSame(3, $m['sample']);
        $this->assertSame('federated_union', $m['basis']);
        $this->assertSame(0, $m['contested']);
        // union scores {0.10, 0.70, 0.90} → median 0.70.
        $this->assertEqualsWithDelta(0.70, $m['score'], 1e-6);
        $this->assertSame(1, $m['distribution']['healthy']);
        $this->assertSame(1, $m['distribution']['degraded']);
        $this->assertSame(1, $m['distribution']['critical']);
    }

    public function test_empty_observations_yields_unavailable_zero_sample(): void
    {
        $m = $this->resolver->federatedMeshHealth();
        $this->assertSame(0, $m['sample']);
        $this->assertSame('unavailable', $m['label']);
        $this->assertSame(0.0, $m['score']);
    }
}

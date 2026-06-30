<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeEvent;
use App\Models\NodeHealthObservation;
use App\Services\HealthEventEmitter;
use App\Services\NodeEventLogger;
use App\Services\NodeHealthService;
use App\Services\ReplicaEventApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-048 / #374 — federation-aware mesh_health, slice 1: the HEALTH event.
 *
 * Behavior tests for emit (seed) + apply (replica) + the per-evaluator staleness rule.
 * Each fails without the slice: apply would SKIP "unsupported event_type: HEALTH",
 * emit would have no HealthEventEmitter, and the staleness assertion guards the
 * monotonic-overwrite rule specifically.
 */
class HealthEventTest extends TestCase
{
    use RefreshDatabase;

    private ReplicaEventApplier $applier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applier = new ReplicaEventApplier;
    }

    private function makeNode(array $overrides = []): Node
    {
        return Node::create(array_merge([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('tok', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'public_reachable' => true,
        ], $overrides));
    }

    private function healthEvent(string $nodeId, array $payload, string $eventId = 'evt-h-1'): array
    {
        return [
            'event_id' => $eventId,
            'seq' => 1,
            'event_type' => 'HEALTH',
            'node_id' => $nodeId,
            'ts_ms' => (int) (microtime(true) * 1000),
            'payload' => $payload,
        ];
    }

    public function test_apply_health_stores_per_evaluator_snapshot(): void
    {
        $r = $this->applier->apply($this->healthEvent('node-a', [
            'score' => 0.82,
            'label' => 'degraded',
            'components' => ['reachability' => 1.0, 'latency' => 0.6],
            'evaluated_at_ms' => 1_700_000_000_000,
            'evaluator_did' => 'did:web:seed.example',
        ]));

        $this->assertSame(ReplicaEventApplier::RESULT_APPLIED, $r['status']);
        $obs = NodeHealthObservation::where('node_id', 'node-a')
            ->where('evaluator_did', 'did:web:seed.example')
            ->first();
        $this->assertNotNull($obs);
        $this->assertEqualsWithDelta(0.82, $obs->score, 1e-6);
        $this->assertSame('degraded', $obs->label);
        $this->assertEquals(1.0, $obs->components['reachability']);
    }

    public function test_apply_health_rejects_missing_evaluator_did(): void
    {
        $r = $this->applier->apply($this->healthEvent('node-a', [
            'score' => 0.5,
            'evaluated_at_ms' => 1_700_000_000_000,
        ]));
        $this->assertSame(ReplicaEventApplier::RESULT_REJECTED, $r['status']);
    }

    public function test_older_health_snapshot_does_not_overwrite_newer(): void
    {
        $newer = [
            'score' => 0.90, 'evaluator_did' => 'did:web:seed.example',
            'evaluated_at_ms' => 1_700_000_002_000,
        ];
        $older = [
            'score' => 0.10, 'evaluator_did' => 'did:web:seed.example',
            'evaluated_at_ms' => 1_700_000_001_000,
        ];

        $this->assertSame(ReplicaEventApplier::RESULT_APPLIED,
            $this->applier->apply($this->healthEvent('node-a', $newer, 'evt-h-newer'))['status']);
        // Out-of-order replay of the older snapshot must be a no-op.
        $r = $this->applier->apply($this->healthEvent('node-a', $older, 'evt-h-older'));
        $this->assertSame(ReplicaEventApplier::RESULT_SKIPPED, $r['status']);

        $obs = NodeHealthObservation::where('node_id', 'node-a')->first();
        $this->assertEqualsWithDelta(0.90, $obs->score, 1e-6);
    }

    public function test_two_evaluators_keep_independent_rows(): void
    {
        $this->applier->apply($this->healthEvent('node-a', [
            'score' => 0.80, 'evaluator_did' => 'did:web:seed.example', 'evaluated_at_ms' => 1_700_000_000_000,
        ], 'evt-e1'));
        $this->applier->apply($this->healthEvent('node-a', [
            'score' => 0.40, 'evaluator_did' => 'did:web:replica.example', 'evaluated_at_ms' => 1_700_000_000_000,
        ], 'evt-e2'));

        $this->assertSame(2, NodeHealthObservation::where('node_id', 'node-a')->count());
    }

    public function test_emitter_appends_signed_health_event_per_active_node(): void
    {
        $node = $this->makeNode();
        $emitter = new HealthEventEmitter(new NodeHealthService, new NodeEventLogger);

        $emitted = $emitter->emitForActiveNodes();

        $this->assertSame(1, $emitted);
        $event = NodeEvent::where('event_type', 'HEALTH')->where('node_id', $node->id)->first();
        $this->assertNotNull($event);
        $this->assertArrayHasKey('score', $event->payload);
        $this->assertArrayHasKey('evaluator_did', $event->payload);
        $this->assertArrayHasKey('evaluated_at_ms', $event->payload);
        $this->assertStringStartsWith('did:web:', $event->payload['evaluator_did']);
        // score is normalized to the [0,1] wire scale.
        $this->assertGreaterThanOrEqual(0.0, $event->payload['score']);
        $this->assertLessThanOrEqual(1.0, $event->payload['score']);
    }
}

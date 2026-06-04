<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Replica;
use App\Services\ReplicaEventApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P6-1.4 — Replica state-mirror correctness.
 *
 * Verifies the cross-event-type invariants the replica must hold:
 *  - Multi-event sequence: REGISTER → CREDIT_AWARD → REPUTATION_DECAY →
 *    DEREGISTER produces the expected end-state
 *  - Idempotency: replaying the same event N times leaves state identical
 *    to applying it once (key for replica re-bootstrap / retry scenarios)
 *  - Mixed-order replay: events received out of seq order produce the same
 *    end-state as in-order replay (where the underlying writes are
 *    commutative — REGISTER + REPUTATION_DECAY are commutative because
 *    updateOrCreate is idempotent on key; DEREGISTER must be applied last
 *    to win, so this test checks the specific commutativity claim)
 *
 * Per-event-type semantics are covered in ReplicaEventApplierTest.
 * Signature verification semantics in ReplicaEventApplierSigVerifyTest.
 * End-to-end protocol contract in FederationIntegrationTest.
 * Command lifecycle in ReplicaStartCommandTest.
 * Snapshot endpoint in SnapshotEndpointTest.
 * This file covers the gaps the others don't: cross-event-type invariants.
 */
class ReplicaStateMirrorTest extends TestCase
{
    use RefreshDatabase;

    private ReplicaEventApplier $applier;

    private string $nodeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applier = new ReplicaEventApplier;
        $this->nodeId = (string) Str::uuid();
    }

    private function event(string $type, array $payload, ?string $nodeId = null, ?int $seq = null): array
    {
        return [
            'event_id' => 'evt-'.substr(md5(uniqid('', true)), 0, 16),
            'seq' => $seq ?? random_int(1, 999999),
            'event_type' => $type,
            'node_id' => $nodeId ?? $this->nodeId,
            'ts_ms' => (int) (microtime(true) * 1000),
            'payload' => $payload,
        ];
    }

    public function test_full_lifecycle_sequence_produces_expected_end_state(): void
    {
        $events = [
            $this->event('REGISTER', [
                'endpoint' => 'https://lifecycle.test',
                'region' => 'eu-central',
                'cip_policy' => ['allow_remote_inference' => false, 'allow_tool_execution' => false, 'allow_file_access' => false],
                'pricing' => ['credit_cost_multiplier' => 1.0, 'pricing_model' => 'per_token', 'attested' => false],
            ], seq: 100),
            $this->event('CREDIT_AWARD', [
                'task_id' => 't-1', 'tokens_used' => 1000, 'amount' => 1.0, 'new_balance' => 1.0,
            ], seq: 101),
            $this->event('CREDIT_AWARD', [
                'task_id' => 't-2', 'tokens_used' => 500, 'amount' => 0.5, 'new_balance' => 1.5,
            ], seq: 102),
            $this->event('REPUTATION_DECAY', [
                'old_score' => 0.5, 'new_score' => 0.4975, 'delta' => -0.0025, 'lambda' => 0.005,
            ], seq: 103),
            $this->event('DEREGISTER', [], seq: 104),
        ];

        foreach ($events as $ev) {
            $r = $this->applier->apply($ev);
            $this->assertSame(ReplicaEventApplier::RESULT_APPLIED, $r['status'], "Event {$ev['event_type']} should apply: {$r['detail']}");
        }

        $node = Node::find($this->nodeId);
        $this->assertNotNull($node, 'Node should exist after REGISTER');
        $this->assertSame('https://lifecycle.test', $node->endpoint);
        $this->assertEquals(1.5, (float) $node->credit_balance, 'Latest CREDIT_AWARD new_balance wins');
        $this->assertEqualsWithDelta(0.4975, (float) $node->reputation_score, 0.0001);
        $this->assertFalse((bool) $node->available, 'DEREGISTER should mark unavailable');
        $this->assertSame('deregistered', $node->status);
    }

    public function test_idempotent_replay_produces_identical_state(): void
    {
        $event = $this->event('REGISTER', [
            'endpoint' => 'https://idem.test', 'region' => 'us-east',
        ], seq: 200);

        for ($i = 0; $i < 5; $i++) {
            $this->applier->apply($event);
        }

        $this->assertSame(1, Node::where('id', $this->nodeId)->count(), '5× replay produces exactly 1 row');
        $node = Node::find($this->nodeId);
        $this->assertSame('https://idem.test', $node->endpoint);
    }

    public function test_credit_award_replay_does_not_double_count(): void
    {
        // CRITICAL invariant: replicas re-poll /v1/events on reconnect. If
        // CREDIT_AWARD wasn't idempotent, a replica re-replaying the same
        // event would double-count tokens — silent money creation.
        $this->applier->apply($this->event('REGISTER', [
            'endpoint' => 'https://credit.test', 'region' => 'eu',
        ], seq: 300));

        $award = $this->event('CREDIT_AWARD', [
            'task_id' => 't-replay', 'tokens_used' => 1000, 'amount' => 1.0, 'new_balance' => 7.5,
        ], seq: 301);

        $this->applier->apply($award);
        $this->applier->apply($award);
        $this->applier->apply($award);

        // Balance reflects the new_balance from the payload, NOT 3 × amount.
        // Because the seed sends authoritative new_balance, the replica's
        // job is "write what the seed said", not "compute from delta".
        $this->assertEquals(7.5, (float) Node::find($this->nodeId)->credit_balance);
    }

    public function test_mixed_order_apply_with_register_first_produces_expected_state(): void
    {
        // REGISTER must come first (DEREGISTER on unknown node is SKIPPED,
        // and CREDIT_AWARD on unknown node is also SKIPPED). After REGISTER,
        // CREDIT_AWARD + REPUTATION_DECAY are commutative (both target
        // distinct columns; updateOrCreate on `id` is idempotent on key).
        $reg = $this->event('REGISTER', ['endpoint' => 'https://mix.test', 'region' => 'eu'], seq: 400);
        $credit = $this->event('CREDIT_AWARD', [
            'task_id' => 't-mix', 'tokens_used' => 100, 'amount' => 0.1, 'new_balance' => 3.0,
        ], seq: 401);
        $decay = $this->event('REPUTATION_DECAY', [
            'old_score' => 0.6, 'new_score' => 0.5985, 'delta' => -0.0015, 'lambda' => 0.005,
        ], seq: 402);

        // Order A: REGISTER → CREDIT → DECAY
        $this->applier->apply($reg);
        $this->applier->apply($credit);
        $this->applier->apply($decay);
        $stateA = Node::find($this->nodeId)->toArray();

        // Wipe and replay in order B: REGISTER → DECAY → CREDIT
        Node::query()->delete();
        $this->applier->apply($reg);
        $this->applier->apply($decay);
        $this->applier->apply($credit);
        $stateB = Node::find($this->nodeId)->toArray();

        $this->assertEquals(
            (float) $stateA['credit_balance'],
            (float) $stateB['credit_balance'],
            'CREDIT_AWARD and REPUTATION_DECAY MUST commute (target distinct columns)'
        );
        $this->assertEqualsWithDelta(
            (float) $stateA['reputation_score'],
            (float) $stateB['reputation_score'],
            0.0001,
            'Reputation score same regardless of CREDIT vs DECAY order'
        );
    }

    public function test_replica_registered_followed_by_re_register_is_idempotent_on_did(): void
    {
        $ev1 = $this->event('REPLICA_REGISTERED', [
            'did' => 'did:web:r-mirror.test', 'endpoint' => 'https://r-mirror.test', 'trust_tier' => 'low',
        ], nodeId: 'rep-'.str_repeat('m', 32), seq: 500);

        // Same DID, new replica_id (e.g. operator re-onboarded with same did:web)
        $ev2 = $this->event('REPLICA_REGISTERED', [
            'did' => 'did:web:r-mirror.test', 'endpoint' => 'https://r-mirror-v2.test', 'trust_tier' => 'medium',
        ], nodeId: 'rep-'.str_repeat('n', 32), seq: 501);

        $this->applier->apply($ev1);
        $this->applier->apply($ev2);

        $this->assertSame(1, Replica::where('did', 'did:web:r-mirror.test')->count(),
            'Re-registration on same DID MUST produce exactly one row (idempotent on did)');
        $replica = Replica::where('did', 'did:web:r-mirror.test')->first();
        $this->assertSame('https://r-mirror-v2.test', $replica->endpoint, 'Latest registration wins');
        $this->assertSame('medium', $replica->trust_tier);
    }
}

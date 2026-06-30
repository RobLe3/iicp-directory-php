<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Replica;
use App\Services\ReplicaEventApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplicaEventApplierTest extends TestCase
{
    use RefreshDatabase;

    private ReplicaEventApplier $applier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applier = new ReplicaEventApplier;
    }

    private function event(string $type, string $nodeId, array $payload, string $eventId = 'evt-test-1'): array
    {
        return [
            'event_id' => $eventId,
            'seq' => 1,
            'event_type' => $type,
            'node_id' => $nodeId,
            'ts_ms' => (int) (microtime(true) * 1000),
            'signer_did' => 'did:web:iicp.network',
            'payload' => $payload,
        ];
    }

    public function test_register_creates_node_with_payload_fields(): void
    {
        $r = $this->applier->apply($this->event('REGISTER', '550e8400-e29b-41d4-a716-446655440001', [
            'endpoint' => 'https://node-a.test',
            'region' => 'eu-central',
            'cip_policy' => ['allow_remote_inference' => true, 'allow_tool_execution' => false, 'allow_file_access' => false],
            'pricing' => ['credit_cost_multiplier' => 1.5, 'pricing_model' => 'per_token', 'attested' => true],
        ]));

        $this->assertSame(ReplicaEventApplier::RESULT_APPLIED, $r['status']);
        $node = Node::find('550e8400-e29b-41d4-a716-446655440001');
        $this->assertNotNull($node);
        $this->assertSame('https://node-a.test', $node->endpoint);
        $this->assertSame('eu-central', $node->region);
        $this->assertTrue((bool) $node->allow_remote_inference);
        $this->assertEquals(1.5, (float) $node->credit_cost_multiplier);
        $this->assertTrue((bool) $node->attested);
        $this->assertTrue((bool) $node->available);
    }

    public function test_register_applies_capabilities_for_replica_discover(): void
    {
        // #438 — a REGISTER event carrying capabilities must create capability rows on
        // the replica, so /v1/discover (which INNER JOINs capabilities on the intent)
        // returns the node. Without this, nodes registered after a replica's bootstrap
        // snapshot are invisible on the replica.
        $nodeId = '550e8400-e29b-41d4-a716-446655440005';
        $r = $this->applier->apply($this->event('REGISTER', $nodeId, [
            'endpoint' => 'https://node-cap.test',
            'region' => 'eu-central',
            'capabilities' => [
                ['intent' => 'urn:iicp:intent:llm:chat:v1', 'models' => ['llama-3-8b'], 'max_tokens' => 4096, 'input_modalities' => ['text']],
                ['intent' => 'urn:iicp:intent:audio:transcribe:v1', 'models' => ['whisper-1'], 'max_tokens' => 1],
            ],
        ]));

        $this->assertSame(ReplicaEventApplier::RESULT_APPLIED, $r['status']);
        $node = Node::find($nodeId);
        $caps = $node->capabilities()->get();
        $this->assertCount(2, $caps);
        $chat = $caps->firstWhere('intent', 'urn:iicp:intent:llm:chat:v1');
        $this->assertNotNull($chat, 'chat capability must exist for discover');
        $this->assertEquals(['llama-3-8b'], $chat->models);
        $this->assertEquals(['text'], $chat->input_modalities); // #408 default applied
    }

    public function test_register_capabilities_replace_idempotently(): void
    {
        // Re-applying REGISTER (or a changed capability set) replaces, never duplicates.
        $nodeId = '550e8400-e29b-41d4-a716-446655440006';
        $ev1 = $this->event('REGISTER', $nodeId, [
            'endpoint' => 'https://n.test', 'region' => 'eu',
            'capabilities' => [['intent' => 'urn:iicp:intent:llm:chat:v1', 'models' => ['m1'], 'max_tokens' => 1]],
        ]);
        $this->applier->apply($ev1);
        $this->applier->apply($ev1); // idempotent
        $this->assertSame(1, Node::find($nodeId)->capabilities()->count());
    }

    public function test_register_is_idempotent(): void
    {
        $ev = $this->event('REGISTER', '550e8400-e29b-41d4-a716-446655440002', [
            'endpoint' => 'https://node-b.test', 'region' => 'us-east',
        ]);
        $this->applier->apply($ev);
        $this->applier->apply($ev);
        $this->assertSame(1, Node::where('id', '550e8400-e29b-41d4-a716-446655440002')->count());
    }

    public function test_register_rejected_without_endpoint(): void
    {
        $r = $this->applier->apply($this->event('REGISTER', '550e8400-e29b-41d4-a716-446655440003', ['region' => 'eu-west']));
        $this->assertSame(ReplicaEventApplier::RESULT_REJECTED, $r['status']);
    }

    public function test_deregister_marks_node_unavailable(): void
    {
        Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440010', 'endpoint' => 'https://x.test', 'available' => true,
            'max_concurrent' => 1, 'tokens_per_min' => 100, 'region' => 'eu', 'node_token_hash' => 'h',
        ]);
        $r = $this->applier->apply($this->event('DEREGISTER', '550e8400-e29b-41d4-a716-446655440010', []));
        $this->assertSame(ReplicaEventApplier::RESULT_APPLIED, $r['status']);
        $node = Node::find('550e8400-e29b-41d4-a716-446655440010');
        $this->assertFalse((bool) $node->available);
        $this->assertSame('deregistered', $node->status);
    }

    public function test_deregister_unknown_node_skipped(): void
    {
        $r = $this->applier->apply($this->event('DEREGISTER', '550e8400-e29b-41d4-a716-446655440099', []));
        $this->assertSame(ReplicaEventApplier::RESULT_SKIPPED, $r['status']);
    }

    public function test_credit_award_writes_new_balance(): void
    {
        Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440020', 'endpoint' => 'https://y.test', 'available' => true,
            'max_concurrent' => 1, 'tokens_per_min' => 100, 'region' => 'eu', 'node_token_hash' => 'h', 'credit_balance' => 5.0,
        ]);
        $r = $this->applier->apply($this->event('CREDIT_AWARD', '550e8400-e29b-41d4-a716-446655440020', [
            'task_id' => 't-1', 'tokens_used' => 500, 'amount' => 0.5, 'new_balance' => 7.5,
        ]));
        $this->assertSame(ReplicaEventApplier::RESULT_APPLIED, $r['status']);
        $this->assertEquals(7.5, (float) Node::find('550e8400-e29b-41d4-a716-446655440020')->credit_balance);
    }

    public function test_credit_award_rejected_without_new_balance(): void
    {
        $r = $this->applier->apply($this->event('CREDIT_AWARD', '550e8400-e29b-41d4-a716-446655440030', [
            'task_id' => 't-2', 'amount' => 1.0,
        ]));
        $this->assertSame(ReplicaEventApplier::RESULT_REJECTED, $r['status']);
    }

    public function test_replica_registered_inserts_replica_row(): void
    {
        $r = $this->applier->apply($this->event('REPLICA_REGISTERED', 'rep-'.str_repeat('a', 32), [
            'did' => 'did:web:r1.test', 'endpoint' => 'https://r1.test', 'trust_tier' => 'verified',
        ]));
        $this->assertSame(ReplicaEventApplier::RESULT_APPLIED, $r['status']);
        $replica = Replica::where('did', 'did:web:r1.test')->first();
        $this->assertNotNull($replica);
        $this->assertSame('https://r1.test', $replica->endpoint);
        $this->assertSame('verified', $replica->trust_tier);
    }

    public function test_replica_registered_is_idempotent_on_did(): void
    {
        $ev = $this->event('REPLICA_REGISTERED', 'rep-'.str_repeat('b', 32), [
            'did' => 'did:web:r2.test', 'endpoint' => 'https://r2.test',
        ]);
        $this->applier->apply($ev);
        $this->applier->apply($ev);
        $this->assertSame(1, Replica::where('did', 'did:web:r2.test')->count());
    }

    public function test_reputation_decay_writes_new_score(): void
    {
        Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440040', 'endpoint' => 'https://z.test', 'available' => true,
            'max_concurrent' => 1, 'tokens_per_min' => 100, 'region' => 'eu', 'node_token_hash' => 'h', 'reputation_score' => 0.85,
        ]);
        $r = $this->applier->apply($this->event('REPUTATION_DECAY', '550e8400-e29b-41d4-a716-446655440040', [
            'old_score' => 0.85, 'new_score' => 0.8458, 'delta' => -0.0042, 'lambda' => 0.005,
        ]));
        $this->assertSame(ReplicaEventApplier::RESULT_APPLIED, $r['status']);
        $this->assertEqualsWithDelta(0.8458, (float) Node::find('550e8400-e29b-41d4-a716-446655440040')->reputation_score, 0.0001);
    }

    public function test_operator_observed_recorded_without_state_mutation(): void
    {
        Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440050', 'endpoint' => 'https://q.test', 'available' => true,
            'max_concurrent' => 1, 'tokens_per_min' => 100, 'region' => 'eu', 'node_token_hash' => 'h',
            'reputation_score' => 0.9,
        ]);
        $r = $this->applier->apply($this->event('OPERATOR_OBSERVED', '550e8400-e29b-41d4-a716-446655440050', [
            'observation_type' => 'private_ip_public_region',
            'observed_at' => '2026-05-26T12:00:00Z',
            'evidence' => ['claimed' => 'eu-central', 'observed' => 'private (RFC1918)', 'source' => 'NodeAddressObserver', 'rule_id' => 'IICP-SEC-GEO-01'],
            'severity' => 'medium',
        ]));
        $this->assertSame(ReplicaEventApplier::RESULT_APPLIED, $r['status']);
        $this->assertStringContainsString('private_ip_public_region', $r['detail']);
        // Replica MUST NOT mutate state from OPERATOR_OBSERVED — score unchanged.
        $this->assertEqualsWithDelta(0.9, (float) Node::find('550e8400-e29b-41d4-a716-446655440050')->reputation_score, 0.0001);
    }

    public function test_operator_observed_with_missing_payload_still_recorded(): void
    {
        // Even bare OPERATOR_OBSERVED is recorded — replicas trust the seed to have
        // already validated the payload; the audit trail is more useful than strict
        // rejection here (rejecting would silently drop evidence).
        $r = $this->applier->apply($this->event('OPERATOR_OBSERVED', 'some-node', []));
        $this->assertSame(ReplicaEventApplier::RESULT_APPLIED, $r['status']);
        $this->assertStringContainsString('unknown', $r['detail']);
    }

    public function test_unknown_event_type_skipped(): void
    {
        $r = $this->applier->apply($this->event('FROBNICATE', 'node-x', []));
        $this->assertSame(ReplicaEventApplier::RESULT_SKIPPED, $r['status']);
        $this->assertStringContainsString('unsupported event_type', $r['detail']);
    }

    public function test_missing_event_id_or_type_rejected(): void
    {
        $r = $this->applier->apply(['payload' => []]);
        $this->assertSame(ReplicaEventApplier::RESULT_REJECTED, $r['status']);
    }
}

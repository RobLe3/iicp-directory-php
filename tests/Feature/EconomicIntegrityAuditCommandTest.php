<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Reputation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EconomicIntegrityAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_audit_is_aggregate_read_only_and_reconciles_valid_rows(): void
    {
        $operator = str_repeat('a', 64);
        $provider = $this->node('economic-provider-1', [
            'status' => 'active',
            'operator_pubkey' => $operator,
            'credit_balance' => 4.0,
            'lifetime_jobs' => 2,
            'supported_receipt_profiles' => ['consumer_cosignature_v1'],
        ]);
        Reputation::create(['node_id' => $provider->id, 'score' => 0.5, 'tasks_total' => 2, 'tasks_failed' => 0, 'completed_tasks_count' => 2, 'avg_latency_ms' => 10]);
        $this->node('economic-provider-2', ['status' => 'active', 'operator_pubkey' => $operator]);

        DB::table('credit_transactions')->insert([
            ['node_id' => $provider->id, 'amount' => 5.0, 'type' => 'credit', 'task_id' => null, 'reason' => 'free_allocation', 'created_at' => now(), 'updated_at' => now()],
            ['node_id' => $provider->id, 'amount' => 1.0, 'type' => 'debit', 'task_id' => 'task-1', 'reason' => 'task_spend', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->creditEvent($provider->id, 1, [
            'task_id' => 'task-1',
            'attribution' => 'attributed_cross_operator',
            'receipt_profile' => 'consumer_cosignature_v1',
            'spent' => 1.0,
        ]);

        $this->assertSame(0, Artisan::call('iicp:audit-economic-integrity', ['--json' => true]));
        $output = Artisan::output();
        $audit = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('iicp.economic_integrity_audit.v1', $audit['schema']);
        $this->assertSame(0, $audit['ledger']['node_balance_mismatch_count']);
        $this->assertSame(0, $audit['credit_events']['spend_reconciliation_mismatch_count']);
        $this->assertSame(1, $audit['credit_events']['receipt_profile_claim_counts']['consumer_cosignature_v1']);
        $this->assertSame(2, $audit['operator_concentration']['max_nodes_per_verified_operator']);
        $this->assertSame(1, $audit['receipt_profile_readiness']['active_ready']);
        $this->assertSame(2, $audit['receipt_profile_readiness']['active_total']);
        $this->assertStringNotContainsString((string) $provider->id, $output);
        $this->assertStringNotContainsString($operator, $output);
    }

    public function test_audit_detects_ledger_spend_and_forbidden_attribution_anomalies(): void
    {
        $node = $this->node('economic-provider-3', [
            'status' => 'active',
            'credit_balance' => 9.0,
            'lifetime_jobs' => 2,
        ]);
        Reputation::create(['node_id' => $node->id, 'score' => 0.5, 'tasks_total' => 10, 'tasks_failed' => 0, 'completed_tasks_count' => 10, 'avg_latency_ms' => 10]);
        DB::table('credit_transactions')->insert([
            'node_id' => $node->id,
            'amount' => 2.0,
            'type' => 'credit',
            'task_id' => null,
            'reason' => 'task_reward',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->creditEvent($node->id, 1, [
            'task_id' => 'missing-debit',
            'attribution' => 'self_operator',
            'spent' => 1.0,
        ]);

        $this->assertSame(0, Artisan::call('iicp:audit-economic-integrity', ['--json' => true]));
        $audit = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $audit['ledger']['node_balance_mismatch_count']);
        $this->assertSame(1, $audit['credit_events']['forbidden_attribution_count']);
        $this->assertSame(1, $audit['credit_events']['spend_reconciliation_mismatch_count']);
        $this->assertSame(1, $audit['advisory_counters']['nodes_completed_exceeds_lifetime_jobs']);
    }

    private function creditEvent(string $nodeId, int $seq, array $payload): void
    {
        DB::table('node_events')->insert([
            'event_id' => fake()->uuid(),
            'seq' => $seq,
            'event_type' => 'CREDIT_AWARD',
            'node_id' => $nodeId,
            'ts_ms' => now()->getTimestampMs(),
            'payload' => json_encode($payload),
            'signature' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function node(string $id, array $overrides = []): Node
    {
        return Node::create(array_merge([
            'id' => $id,
            'endpoint' => 'https://node.invalid',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('test-token', PASSWORD_BCRYPT),
            'max_concurrent' => 1,
            'tokens_per_min' => 1000,
            'available' => true,
            'last_seen' => now(),
            'status' => 'active',
            'credit_balance' => 0,
            'lifetime_jobs' => 0,
        ], $overrides));
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Credit;
use App\Models\CreditTransaction;
use App\Models\Node;
use App\Services\CreditService;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /v1/credits/summary (#456) — lifetime income / spending / balance with a
 * `reconciles` integrity flag. The tamper test is the point of this suite: a summary
 * that only echoes the balance is worthless for fraud detection; `reconciles` MUST
 * catch a ledger that no longer adds up (deleted debit / edited balance).
 */
class CreditSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Node $node;

    private string $jwt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->node = Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440777',
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('unused', PASSWORD_BCRYPT),
            'node_hmac_key' => bin2hex(random_bytes(32)),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'observed_source_ip' => '127.0.0.1',
        ]);

        // Income: 10 + 5 = 15 earned. Spending: 4 spent. Balance: 11.
        foreach ([['credit', 10.0], ['credit', 5.0], ['debit', 4.0]] as [$type, $amount]) {
            CreditTransaction::create([
                'node_id' => $this->node->id,
                'amount' => $amount,
                'type' => $type,
                'task_id' => $type === 'debit' ? 'task-xyz' : null,
                'reason' => $type === 'credit' ? 'award' : 'task',
            ]);
        }
        Credit::create(['node_id' => $this->node->id, 'balance' => 11.0]);
        $this->node->update(['credit_balance' => 11.0]);

        $this->jwt = app(JwtService::class)->issue($this->node->id);
    }

    public function test_summary_reports_earned_spent_balance_and_reconciles(): void
    {
        $this->withToken($this->jwt)
            ->getJson('/api/v1/credits/summary')
            ->assertStatus(200)
            ->assertJsonPath('node_id', $this->node->id)
            ->assertJsonPath('total_earned', 15)
            ->assertJsonPath('total_spent', 4)
            ->assertJsonPath('balance', 11)
            ->assertJsonPath('tx_count', 3)
            ->assertJsonPath('reconciles', true)
            ->assertJsonPath('unit', 'credit')
            ->assertJsonPath('tokens_per_credit', 1000);
    }

    /**
     * #466 operator wallet (v1): when the node is delegation-bound, the summary aggregates
     * credit_balance across ALL the operator's non-archived nodes into one wallet keyed by
     * the operator identity. operator_pubkey itself is never returned.
     */
    public function test_summary_aggregates_operator_wallet_across_the_operators_nodes(): void
    {
        $this->node->update(['operator_pubkey' => 'OP_KEY', 'credit_balance' => 11.0]);
        // A second node owned by the same operator, with its own balance.
        Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440888',
            'endpoint' => 'https://node2.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('unused', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'operator_pubkey' => 'OP_KEY',
            'credit_balance' => 7.0,
        ]);
        Credit::create(['node_id' => '550e8400-e29b-41d4-a716-446655440888', 'balance' => 7.0]);
        CreditTransaction::create([
            'node_id' => '550e8400-e29b-41d4-a716-446655440888',
            'amount' => 7.0,
            'type' => 'credit',
            'reason' => 'award',
            'expires_at' => now()->addDays(30),
        ]);
        // A node owned by a DIFFERENT operator must NOT be counted.
        Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440999',
            'endpoint' => 'https://other.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('unused', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'operator_pubkey' => 'OTHER_KEY',
            'credit_balance' => 99.0,
        ]);

        $resp = $this->withToken($this->jwt)->getJson('/api/v1/credits/summary')->assertStatus(200)
            ->assertJsonPath('operator_wallet.total_balance', 18) // 11 + 7, not the other's 99
            ->assertJsonPath('operator_wallet.total_earned', 22)
            ->assertJsonPath('operator_wallet.total_spent', 4)
            ->assertJsonPath('operator_wallet.tx_count', 4)
            ->assertJsonPath('operator_wallet.node_count', 2)
            ->assertJsonPath('operator_wallet.reconciles', true);
        $this->assertStringNotContainsString('OP_KEY', $resp->getContent());
        $this->assertStringContainsString('operator_fingerprint', $resp->getContent());
    }

    public function test_summary_operator_wallet_is_null_when_not_operator_bound(): void
    {
        // The setUp node has no operator_pubkey → no wallet aggregation.
        $this->withToken($this->jwt)->getJson('/api/v1/credits/summary')
            ->assertStatus(200)
            ->assertJsonPath('operator_wallet', null);
    }

    /**
     * Tamper: delete the debit row to "hide" spending while the balance column is
     * unchanged. earned − spent (15) no longer equals the balance (11) → reconciles=false.
     * This is the fraud signal an operator (or auditor) must see.
     */
    public function test_summary_flags_tampered_ledger_as_not_reconciling(): void
    {
        CreditTransaction::where('node_id', $this->node->id)->where('type', 'debit')->delete();

        $this->withToken($this->jwt)
            ->getJson('/api/v1/credits/summary')
            ->assertStatus(200)
            ->assertJsonPath('total_spent', 0)
            ->assertJsonPath('balance', 11)
            ->assertJsonPath('reconciles', false);
    }

    public function test_quote_uses_operator_wallet_as_effective_balance(): void
    {
        $this->node->update(['operator_pubkey' => 'OP_KEY', 'credit_balance' => 0.0]);
        Credit::where('node_id', $this->node->id)->update(['balance' => 0.0]);

        Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440123',
            'endpoint' => 'https://wallet-node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('unused', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'operator_pubkey' => 'OP_KEY',
            'credit_balance' => 5.0,
        ]);
        Credit::create(['node_id' => '550e8400-e29b-41d4-a716-446655440123', 'balance' => 5.0]);
        CreditTransaction::create([
            'node_id' => '550e8400-e29b-41d4-a716-446655440123',
            'amount' => 5.0,
            'type' => 'credit',
            'reason' => 'award',
            'expires_at' => now()->addDays(30),
        ]);

        $this->withToken($this->jwt)
            ->getJson('/api/v1/credits/quote?intent=urn:iicp:intent:llm:chat:v1&max_tokens=1000')
            ->assertStatus(200)
            ->assertJsonPath('consumer_balance', 0)
            ->assertJsonPath('operator_wallet_balance', 5)
            ->assertJsonPath('effective_balance', 5)
            ->assertJsonPath('balance_scope', 'operator_wallet')
            ->assertJsonPath('balance_sufficient', true);
    }

    public function test_operator_wallet_debit_spends_oldest_expiring_credits_first(): void
    {
        $this->node->update(['operator_pubkey' => 'OP_KEY', 'credit_balance' => 0.0]);
        Credit::where('node_id', $this->node->id)->update(['balance' => 0.0]);

        foreach ([
            ['550e8400-e29b-41d4-a716-446655440321', 3.0, 1],
            ['550e8400-e29b-41d4-a716-446655440322', 5.0, 30],
        ] as [$id, $balance, $days]) {
            Node::create([
                'id' => $id,
                'endpoint' => "https://$id.example.com",
                'region' => 'eu-central',
                'node_token_hash' => password_hash('unused', PASSWORD_BCRYPT),
                'max_concurrent' => 4,
                'tokens_per_min' => 10000,
                'available' => true,
                'last_seen' => now(),
                'operator_pubkey' => 'OP_KEY',
                'credit_balance' => $balance,
            ]);
            Credit::create(['node_id' => $id, 'balance' => $balance]);
            CreditTransaction::create([
                'node_id' => $id,
                'amount' => $balance,
                'type' => 'credit',
                'reason' => 'award',
                'expires_at' => now()->addDays($days),
            ]);
        }

        $result = app(CreditService::class)->debitForConsumer(
            consumerNodeId: (string) $this->node->id,
            amount: 4.0,
            taskId: 'task-wallet-spend',
            reason: 'task_spend',
        );

        $this->assertTrue($result['debited']);
        $this->assertSame('operator_wallet', $result['scope']);
        $this->assertSame(2, $result['debit_count']);
        $this->assertSame(0.0, (float) Node::find('550e8400-e29b-41d4-a716-446655440321')->credit_balance);
        $this->assertSame(4.0, (float) Node::find('550e8400-e29b-41d4-a716-446655440322')->credit_balance);
        $this->assertSame(3.0, (float) CreditTransaction::where('node_id', '550e8400-e29b-41d4-a716-446655440321')->where('type', 'debit')->sum('amount'));
        $this->assertSame(1.0, (float) CreditTransaction::where('node_id', '550e8400-e29b-41d4-a716-446655440322')->where('type', 'debit')->sum('amount'));
    }
}

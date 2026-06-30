<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Credit;
use App\Models\CreditTransaction;
use App\Models\Node;
use App\Services\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The 90-day TTL credit sink (#404 behavior tests for ADR-035 /
 * iicp-billing-extension §11.3). The point of this suite: a documented sink with
 * no enforcement is worthless — these tests fail without the expires_at write +
 * the idle-node sweep.
 *
 *   1. an earn stamps expires_at = now + 90d (TTL_DAYS)
 *   2. an idle node's unspent balance is excluded from balance after the sweep
 *   3. the sweep is idempotent (second run expires nothing)
 *   4. a node with a fresh (future-dated) earn is NOT swept even with a balance
 */
class CreditTtlExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function makeNode(string $id): Node
    {
        return Node::create([
            'id' => $id,
            'endpoint' => "https://{$id}.example.com",
            'region' => 'eu-central',
            'node_token_hash' => password_hash('unused', PASSWORD_BCRYPT),
            'node_hmac_key' => bin2hex(random_bytes(32)),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'observed_source_ip' => '127.0.0.1',
        ]);
    }

    public function test_an_earn_stamps_expires_at_ninety_days_out(): void
    {
        $node = $this->makeNode('550e8400-e29b-41d4-a716-44665544aaaa');

        app(CreditService::class)->award($node->id, 12.0, 'award');

        $tx = CreditTransaction::where('node_id', $node->id)->where('type', 'credit')->firstOrFail();
        $this->assertNotNull($tx->expires_at, 'earn must carry a TTL horizon');
        // expires_at ≈ now + 90 days (allow a small clock window for the test run).
        $expected = now()->addDays(CreditService::TTL_DAYS);
        $this->assertLessThan(60, abs($tx->expires_at->diffInSeconds($expected)));
    }

    public function test_idle_nodes_unspent_balance_is_swept_and_excluded_from_balance(): void
    {
        $node = $this->makeNode('550e8400-e29b-41d4-a716-44665544bbbb');
        // A node holding 50 credits whose only earn expired 1 day past its 90d TTL.
        Credit::create(['node_id' => $node->id, 'balance' => 50.0]);
        $node->update(['credit_balance' => 50.0]);
        CreditTransaction::create([
            'node_id' => $node->id,
            'amount' => 50.0,
            'type' => 'credit',
            'reason' => 'award',
            'expires_at' => now()->subDays(CreditService::TTL_DAYS + 1),
        ]);

        Artisan::call('iicp:expire-credits');

        // Balance is now zero (excluded), and the sink wrote one expire row.
        $this->assertSame(0.0, app(CreditService::class)->balance($node->id));
        $this->assertSame(0.0, (float) $node->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'node_id' => $node->id,
            'type' => 'debit',
            'reason' => 'ttl_expire',
            'amount' => 50.0,
        ]);
    }

    public function test_sweep_is_idempotent(): void
    {
        $node = $this->makeNode('550e8400-e29b-41d4-a716-44665544cccc');
        Credit::create(['node_id' => $node->id, 'balance' => 30.0]);
        $node->update(['credit_balance' => 30.0]);
        CreditTransaction::create([
            'node_id' => $node->id,
            'amount' => 30.0,
            'type' => 'credit',
            'reason' => 'award',
            'expires_at' => now()->subDays(CreditService::TTL_DAYS + 5),
        ]);

        $first = app(CreditService::class)->expireIdleNodeCredits();
        $second = app(CreditService::class)->expireIdleNodeCredits();

        $this->assertSame(1, $first['expired_nodes']);
        $this->assertSame(0, $second['expired_nodes'], 'second sweep must be a no-op');
        // Exactly one expire row was ever written.
        $this->assertSame(1, CreditTransaction::where('node_id', $node->id)->where('reason', 'ttl_expire')->count());
    }

    public function test_node_with_fresh_earn_is_not_swept(): void
    {
        $node = $this->makeNode('550e8400-e29b-41d4-a716-44665544dddd');
        Credit::create(['node_id' => $node->id, 'balance' => 40.0]);
        $node->update(['credit_balance' => 40.0]);
        // Earn whose TTL is still in the future → node is active, not idle.
        CreditTransaction::create([
            'node_id' => $node->id,
            'amount' => 40.0,
            'type' => 'credit',
            'reason' => 'award',
            'expires_at' => now()->addDays(CreditService::TTL_DAYS),
        ]);

        $result = app(CreditService::class)->expireIdleNodeCredits();

        $this->assertSame(0, $result['expired_nodes']);
        $this->assertSame(40.0, app(CreditService::class)->balance($node->id));
    }
}

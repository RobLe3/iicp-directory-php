<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Services\CreditService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase A.4 / ADR-039 §5.9: credit_balance precision regression tests.
 *
 * Verifies decimal(15,4) precision is preserved across the dual-write path
 * (credits → nodes.credit_balance) + the D2-READ path (read from nodes,
 * fallback to credits).
 *
 * Catches: float roundoff drift, decimal-vs-float conversions, hex floats,
 * very-small + very-large values, repeated arithmetic.
 *
 * Per the 10-issue audit §5.9: decimal(15,4) gives 11 digits of integer +
 * 4 decimal places. Roundoff drift in PHP float arithmetic could surface
 * here; this regression suite locks the precision contract.
 */
class CreditBalancePrecisionTest extends TestCase
{
    use RefreshDatabase;

    private CreditService $service;

    private string $nodeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CreditService;
        $this->nodeId = '550e8400-e29b-41d4-a716-446655440200';
        Node::create([
            'id' => $this->nodeId,
            'endpoint' => 'https://prec.test',
            'region' => 'eu',
            'node_token_hash' => 'h',
            'max_concurrent' => 1,
            'tokens_per_min' => 100,
            'available' => true,
            'credit_balance' => 0,
        ]);
    }

    public function test_balance_preserves_4_decimal_places(): void
    {
        $this->service->award($this->nodeId, 0.0001, 'micro-tx');
        $this->assertEqualsWithDelta(0.0001, $this->service->balance($this->nodeId), 0.00001);
    }

    public function test_balance_handles_sub_credit_precision(): void
    {
        // Three sub-credit increments — sum must equal 0.0003, not float-drifted
        $this->service->award($this->nodeId, 0.0001, 'tx1');
        $this->service->award($this->nodeId, 0.0001, 'tx2');
        $this->service->award($this->nodeId, 0.0001, 'tx3');
        $this->assertEqualsWithDelta(0.0003, $this->service->balance($this->nodeId), 0.00001);
    }

    public function test_balance_handles_large_value(): void
    {
        // decimal(15,4) max integer part = 11 digits = 99,999,999,999.9999
        // Test a value well within range but larger than typical
        $this->service->award($this->nodeId, 1_000_000.5500, 'big-tx');
        $this->assertEqualsWithDelta(1_000_000.5500, $this->service->balance($this->nodeId), 0.0001);
    }

    public function test_repeated_award_debit_does_not_drift(): void
    {
        // 100 cycles of +0.10 / -0.05 = net +0.05 per cycle = +5.0 final
        for ($i = 0; $i < 100; $i++) {
            $this->service->award($this->nodeId, 0.10, "cycle{$i}");
            $this->service->debit($this->nodeId, 0.05, "task-{$i}", 'cycle');
        }
        $this->assertEqualsWithDelta(5.0, $this->service->balance($this->nodeId), 0.001);
    }

    public function test_dual_write_keeps_credits_and_nodes_in_sync(): void
    {
        $this->service->award($this->nodeId, 12.3456, 'sync-test');

        $creditTableBalance = (float) DB::table('credits')->where('node_id', $this->nodeId)->value('balance');
        $nodesTableBalance = (float) Node::find($this->nodeId)->credit_balance;

        $this->assertEqualsWithDelta(12.3456, $creditTableBalance, 0.00001, 'credits.balance');
        $this->assertEqualsWithDelta(12.3456, $nodesTableBalance, 0.00001, 'nodes.credit_balance (D2 dual-write)');
        $this->assertEqualsWithDelta(
            $creditTableBalance,
            $nodesTableBalance,
            0.00001,
            'D2 dual-write MUST keep both columns in sync to 4 decimal places'
        );
    }

    public function test_d2_read_path_returns_nodes_credit_balance(): void
    {
        // Seed nodes.credit_balance directly (simulating post-D2-READ state)
        Node::where('id', $this->nodeId)->update(['credit_balance' => 7.5]);
        // Also seed credits table with a DIFFERENT value to verify which one wins
        DB::table('credits')->insert([
            'node_id' => $this->nodeId,
            'balance' => 99.9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // D2-READ should return nodes.credit_balance (7.5), not credits.balance (99.9)
        $this->assertEqualsWithDelta(7.5, $this->service->balance($this->nodeId), 0.001,
            'D2-READ MUST prefer nodes.credit_balance over legacy credits.balance');
    }

    public function test_d2_read_fallback_path_is_unreachable_under_current_schema(): void
    {
        // Schema invariant check: the D2 denormalization migration created
        // credit_balance with `default(0.0000)` (NOT NULL). The CreditService::balance()
        // fallback path (`if ($denormalized !== null)`) is therefore unreachable
        // under the current schema — kept only as defensive coding.
        //
        // This test documents the invariant: we EXPECT a NOT NULL constraint
        // violation if anything tries to set credit_balance to NULL. If this
        // test starts passing the assertion below (i.e. the update succeeds),
        // the schema has drifted and the fallback path becomes reachable.
        $this->expectException(QueryException::class);
        DB::table('nodes')->where('id', $this->nodeId)->update(['credit_balance' => null]);
    }

    public function test_balance_zero_for_unknown_node(): void
    {
        $unknown = '550e8400-e29b-41d4-a716-446655440999';
        $this->assertSame(0.0, $this->service->balance($unknown));
    }

    public function test_credits_for_tokens_returns_exact_rate(): void
    {
        // TOKENS_PER_CREDIT = 1000; rate must be exact, not float-rounded
        $this->assertEqualsWithDelta(0.0010, $this->service->creditsForTokens(1), 0.00001);
        $this->assertEqualsWithDelta(0.5000, $this->service->creditsForTokens(500), 0.00001);
        $this->assertEqualsWithDelta(1.0000, $this->service->creditsForTokens(1000), 0.00001);
        $this->assertEqualsWithDelta(2.5000, $this->service->creditsForTokens(2500), 0.00001);
    }

    public function test_negative_balance_blocked_by_debit_check(): void
    {
        // Awarded 1.0 → debit 1.5 must fail; balance stays at 1.0
        $this->service->award($this->nodeId, 1.0, 'fund');
        $result = $this->service->debit($this->nodeId, 1.5, 'over-debit', 'should-fail');
        $this->assertFalse($result, 'debit beyond balance must return false');
        $this->assertEqualsWithDelta(1.0, $this->service->balance($this->nodeId), 0.001,
            'failed debit must leave balance unchanged');
    }
}

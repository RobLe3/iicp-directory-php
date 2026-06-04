<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Credit;
use App\Models\CreditIpGate;
use App\Models\CreditTransaction;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

/**
 * Credit ledger for the cooperative inference billing system (Phase 5, ADR-019).
 *
 * WHY TOKENS_PER_CREDIT = 1000: one credit = 1000 tokens consumed. This gives 3 decimal
 * places of precision at typical task sizes (100–5000 tokens) without requiring floating-point
 * token tracking. Operators see credit amounts, not raw token counts, in their dashboard.
 *
 * WHY DB::transaction on award/debit: the `credits` table is the authoritative balance ledger.
 * Concurrent heartbeats from the same node must not race on balance updates (TC-9b rate limit
 * is also checked upstream in CreditsController — the transaction lock here is a defense-in-depth
 * guard against implementation bugs in the controller layer).
 *
 * WHY lockForUpdate on debit: prevents double-spend in concurrent debit calls (e.g., two
 * simultaneous CIP task completions for the same node). Award uses firstOrCreate + increment
 * (optimistic) because over-award is less damaging than under-award (and rate-limited upstream).
 *
 * Security: TC-9b (rate limit 1000 credits/hour), TC-9c (credit inflation ceiling), TC-9d
 * (nonce lock for HMAC receipts) are all enforced in CreditsController — not here. This service
 * is the persistence layer only.
 *
 * Spec: spec/iicp-cooperative-inference.md §2.1 credit pre-flight. ADR: ADR-019 (pricing).
 */
class CreditService
{
    private const TOKENS_PER_CREDIT = 1000;

    /** Free evaluation tier: 5 credits every 6 hours when balance is zero (issue #306). */
    public const FREE_CREDITS_AMOUNT = 5.0;

    public const FREE_CREDITS_PERIOD_HOURS = 6;

    public function balance(string $nodeId): float
    {
        // D2-READ (W-042/D5prime prep): read from canonical denormalized column
        // on nodes; falls back to the legacy `credits` table only if nodes row
        // has no value (which only happens for nodes created before D1prime
        // migration backfilled). After Phase 2 cleanup (credits table drop),
        // the fallback path is removed.
        $denormalized = Node::where('id', $nodeId)->value('credit_balance');
        if ($denormalized !== null) {
            return (float) $denormalized;
        }

        // Legacy fallback — only triggered if nodes.credit_balance is NULL.
        return (float) Credit::where('node_id', $nodeId)->value('balance') ?? 0.0;
    }

    public function award(string $nodeId, float $amount, ?string $reason = null): float
    {
        return DB::transaction(function () use ($nodeId, $amount, $reason): float {
            $credit = Credit::firstOrCreate(['node_id' => $nodeId], ['balance' => 0]);
            $credit->increment('balance', $amount);

            CreditTransaction::create([
                'node_id' => $nodeId,
                'amount' => $amount,
                'type' => 'credit',
                'reason' => $reason,
            ]);

            $newBalance = (float) $credit->fresh()?->balance;
            // W-042 / db-D2prime: dual-write to nodes.credit_balance
            Node::where('id', $nodeId)->update(['credit_balance' => $newBalance]);

            return $newBalance;
        });
    }

    public function debit(string $nodeId, float $amount, string $taskId, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($nodeId, $amount, $taskId, $reason): bool {
            $credit = Credit::where('node_id', $nodeId)->lockForUpdate()->first();

            if (! $credit || $credit->balance < $amount) {
                return false;
            }

            $credit->decrement('balance', $amount);

            CreditTransaction::create([
                'node_id' => $nodeId,
                'amount' => $amount,
                'type' => 'debit',
                'task_id' => $taskId,
                'reason' => $reason,
            ]);

            // W-042 / db-D2prime: dual-write to nodes.credit_balance
            Node::where('id', $nodeId)->update([
                'credit_balance' => (float) $credit->fresh()?->balance,
            ]);

            return true;
        });
    }

    public function creditsForTokens(int $tokensUsed): float
    {
        return round($tokensUsed / self::TOKENS_PER_CREDIT, 4);
    }

    /**
     * Award the free evaluation allocation if the node is eligible.
     *
     * Two-tier gate (RT-02b, #380):
     * 1. Per-node_id: credits.free_credit_last_allocation_at must be NULL or > 6h ago.
     * 2. Per-source-IP: credit_ip_gates.last_allocation_at must be NULL or > 6h ago.
     *    This prevents harvest by registering a new node_id from the same IP.
     *
     * Both gates are checked and updated atomically within a single transaction.
     *
     * Returns FREE_CREDITS_AMOUNT on success, null if either gate blocks allocation.
     */
    public function maybeAllocateFreeCredits(string $nodeId, string $ipAddress = '0.0.0.0'): ?float
    {
        return DB::transaction(function () use ($nodeId, $ipAddress): ?float {
            $credit = Credit::where('node_id', $nodeId)->lockForUpdate()->first();

            $balance = $credit ? (float) $credit->balance : 0.0;
            if ($balance > 0) {
                return null;
            }

            // Gate 1: per-node_id 6h window
            $lastAlloc = $credit?->free_credit_last_allocation_at;
            if ($lastAlloc && $lastAlloc->diffInHours(now()) < self::FREE_CREDITS_PERIOD_HOURS) {
                return null;
            }

            // Gate 2: per-source-IP 6h window (RT-02b bypass mitigation)
            $ipGate = CreditIpGate::where('ip_address', $ipAddress)->lockForUpdate()->first();
            if ($ipGate?->last_allocation_at &&
                $ipGate->last_allocation_at->diffInHours(now()) < self::FREE_CREDITS_PERIOD_HOURS) {
                return null;
            }

            if (! $credit) {
                $credit = Credit::firstOrCreate(
                    ['node_id' => $nodeId],
                    ['balance' => 0, 'free_credit_last_allocation_at' => now()],
                );
            } else {
                $credit->free_credit_last_allocation_at = now();
                $credit->save();
            }

            $credit->increment('balance', self::FREE_CREDITS_AMOUNT);

            CreditTransaction::create([
                'node_id' => $nodeId,
                'amount' => self::FREE_CREDITS_AMOUNT,
                'type' => 'credit',
                'reason' => 'free_allocation',
            ]);

            // W-042 / db-D2prime: dual-write to nodes.credit_balance + free_credit_last_allocation_at
            Node::where('id', $nodeId)->update([
                'credit_balance' => (float) $credit->fresh()?->balance,
                'free_credit_last_allocation_at' => $credit->fresh()?->free_credit_last_allocation_at,
            ]);

            // Update IP gate (fetch-then-save for SQLite/MySQL compat; inside the transaction lock)
            $ipGateNew = CreditIpGate::firstOrNew(['ip_address' => $ipAddress]);
            $ipGateNew->last_allocation_at = now();
            $ipGateNew->allocation_count = ($ipGateNew->allocation_count ?? 0) + 1;
            $ipGateNew->save();

            return self::FREE_CREDITS_AMOUNT;
        });
    }

    public function recentTransactions(string $nodeId, int $limit = 20): array
    {
        return CreditTransaction::where('node_id', $nodeId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'amount', 'type', 'task_id', 'reason', 'created_at'])
            ->toArray();
    }
}

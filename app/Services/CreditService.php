<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Credit;
use App\Models\CreditIpGate;
use App\Models\CreditTransaction;
use App\Models\Node;
use App\Models\Operator;
use Carbon\Carbon;
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

    /**
     * Credit TTL window in days (ADR-035 / iicp-billing-extension §11, pinned to
     * credit_economy.TTL_days). Every earn resets the node's TTL to now+TTL_DAYS;
     * a node that does not earn within the window forfeits its unspent balance
     * (the primary anti-inflation sink — see expireIdleNodeCredits()).
     */
    public const TTL_DAYS = 90;

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
                // ADR-035 §11: an earn sets the credit TTL horizon (now + 90d).
                'expires_at' => now()->addDays(self::TTL_DAYS),
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

    /**
     * Spend credits for a consumer node.
     *
     * Operator-bound consumers spend from their pooled operator wallet. The ledger remains
     * per-node for auditability: every split debit is written against the node whose balance
     * was actually reduced. Unbound consumers retain the legacy node-local debit behavior.
     *
     * @return array{debited: bool, spent: float, scope: string, reason: ?string, debit_count: int}
     */
    public function debitForConsumer(string $consumerNodeId, float $amount, string $taskId, ?string $reason = null): array
    {
        $consumer = Node::query()
            ->where('id', $consumerNodeId)
            ->select(['id', 'operator_pubkey'])
            ->first();

        if (! $consumer || empty($consumer->operator_pubkey)) {
            $debited = $this->debit($consumerNodeId, $amount, $taskId, $reason);

            return [
                'debited' => $debited,
                'spent' => $debited ? round($amount, 4) : 0.0,
                'scope' => 'node',
                'reason' => $debited ? null : 'insufficient_balance',
                'debit_count' => $debited ? 1 : 0,
            ];
        }

        return DB::transaction(function () use ($consumer, $amount, $taskId, $reason): array {
            $candidates = [];
            foreach ($this->operatorSpendableNodes($consumer->operator_pubkey) as $candidate) {
                /** @var Node|null $locked */
                $locked = Node::query()
                    ->where('id', $candidate->id)
                    ->lockForUpdate()
                    ->select(['id', 'credit_balance'])
                    ->first();

                if (! $locked || $this->nodeCreditsExpired((string) $locked->id)) {
                    continue;
                }

                $balance = (float) $locked->credit_balance;
                if ($balance > 0.0) {
                    $candidates[] = [$locked, $balance];
                }
            }

            $available = array_reduce($candidates, fn (float $sum, array $row): float => $sum + $row[1], 0.0);
            if ($available + 0.0001 < $amount) {
                return [
                    'debited' => false,
                    'spent' => 0.0,
                    'scope' => 'operator_wallet',
                    'reason' => 'insufficient_operator_wallet_balance',
                    'debit_count' => 0,
                ];
            }

            $remaining = round($amount, 4);
            $debitCount = 0;
            foreach ($candidates as [$node, $balance]) {
                if ($remaining <= 0.00005) {
                    break;
                }

                $take = round(min($balance, $remaining), 4);
                if ($take <= 0.0) {
                    continue;
                }

                $newBalance = round($balance - $take, 4);
                $credit = Credit::where('node_id', $node->id)->lockForUpdate()->first();
                if (! $credit) {
                    $credit = Credit::create(['node_id' => $node->id, 'balance' => $balance]);
                }
                $credit->update(['balance' => $newBalance]);
                Node::where('id', $node->id)->update(['credit_balance' => $newBalance]);

                CreditTransaction::create([
                    'node_id' => $node->id,
                    'amount' => $take,
                    'type' => 'debit',
                    'task_id' => $taskId,
                    'reason' => $this->walletDebitReason($reason, (string) $consumer->id, (string) $node->id),
                ]);

                $remaining = round($remaining - $take, 4);
                $debitCount++;
            }

            return [
                'debited' => $remaining <= 0.0001,
                'spent' => $remaining <= 0.0001 ? round($amount, 4) : 0.0,
                'scope' => 'operator_wallet',
                'reason' => $remaining <= 0.0001 ? null : 'insufficient_operator_wallet_balance',
                'debit_count' => $debitCount,
            ];
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
                // ADR-035 §11: the free allocation is an earn — it carries a TTL too.
                'expires_at' => now()->addDays(self::TTL_DAYS),
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

    /**
     * Lifetime credit summary for a node: income (earned), spending (spent), and the
     * current balance — the data behind `iicp-node credits` (#456).
     *
     * `reconciles` is an integrity invariant: the balance MUST equal
     * (total_earned − total_spent). If a ledger row is tampered with, a debit is
     * deleted to hide spending, or the balance column is edited, this flips to false —
     * so the summary is self-checking, not a number taken on trust. (Independent
     * cryptographic confirmation of income against the signed CREDIT_AWARD event log
     * is the separate `--verify` path — see #456.)
     *
     * @return array{balance: float, total_earned: float, total_spent: float, tx_count: int, reconciles: bool}
     */
    public function summary(string $nodeId): array
    {
        $earned = (float) CreditTransaction::where('node_id', $nodeId)
            ->where('type', 'credit')->sum('amount');
        $spent = (float) CreditTransaction::where('node_id', $nodeId)
            ->where('type', 'debit')->sum('amount');
        $balance = $this->balance($nodeId);
        $txCount = (int) CreditTransaction::where('node_id', $nodeId)->count();

        // Float-safe equality at the ledger's 4-decimal precision (decimal(15,4)).
        $reconciles = abs($balance - ($earned - $spent)) < 0.0001;

        return [
            'balance' => $balance,
            'total_earned' => $earned,
            'total_spent' => $spent,
            'tx_count' => $txCount,
            'reconciles' => $reconciles,
        ];
    }

    /**
     * Operator-wallet rollup for the node's verified operator binding.
     *
     * The raw operator_pubkey is never returned. Balances remain backed by the per-node
     * ledger; this is the identity-level view users expect when several nodes earn for
     * the same operator.
     *
     * @return array{total_balance: float, total_earned: float, total_spent: float, tx_count: int, node_count: int, reconciles: bool, operator_fingerprint: string}|null
     */
    public function operatorWalletSummary(string $nodeId): ?array
    {
        $operatorPubkey = Node::query()
            ->where('id', $nodeId)
            ->value('operator_pubkey');

        if (empty($operatorPubkey)) {
            return null;
        }

        $nodeIds = Node::query()
            ->where('operator_pubkey', $operatorPubkey)
            ->where('status', '!=', 'archived')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (empty($nodeIds)) {
            return null;
        }

        $balance = (float) Node::whereIn('id', $nodeIds)->sum('credit_balance');
        $earned = (float) CreditTransaction::whereIn('node_id', $nodeIds)
            ->where('type', 'credit')->sum('amount');
        $spent = (float) CreditTransaction::whereIn('node_id', $nodeIds)
            ->where('type', 'debit')->sum('amount');
        $txCount = (int) CreditTransaction::whereIn('node_id', $nodeIds)->count();

        return [
            'total_balance' => round($balance, 4),
            'total_earned' => round($earned, 4),
            'total_spent' => round($spent, 4),
            'tx_count' => $txCount,
            'node_count' => count($nodeIds),
            'reconciles' => abs($balance - ($earned - $spent)) < 0.0001,
            'operator_fingerprint' => Operator::publicFingerprint((string) $operatorPubkey),
        ];
    }

    /**
     * Effective balance used for pre-flight spend checks.
     *
     * @return array{consumer_balance: float, effective_balance: float, balance_scope: string, operator_wallet_balance: ?float}
     */
    public function effectiveBalanceForConsumer(string $nodeId): array
    {
        $consumerBalance = $this->balance($nodeId);
        $operatorPubkey = Node::query()->where('id', $nodeId)->value('operator_pubkey');
        if (empty($operatorPubkey)) {
            return [
                'consumer_balance' => round($consumerBalance, 4),
                'effective_balance' => round($consumerBalance, 4),
                'balance_scope' => 'node',
                'operator_wallet_balance' => null,
            ];
        }

        $walletBalance = (float) $this->operatorSpendableNodes((string) $operatorPubkey)
            ->sum(fn (Node $node): float => (float) $node->credit_balance);

        return [
            'consumer_balance' => round($consumerBalance, 4),
            'effective_balance' => round($walletBalance, 4),
            'balance_scope' => 'operator_wallet',
            'operator_wallet_balance' => round($walletBalance, 4),
        ];
    }

    private function walletDebitReason(?string $reason, string $consumerNodeId, string $debitedNodeId): string
    {
        $base = $reason ?: 'task_spend';
        if ($consumerNodeId === $debitedNodeId) {
            return substr($base, 0, 255);
        }

        return substr($base.':operator_wallet:consumer='.substr($consumerNodeId, 0, 8), 0, 255);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Node>
     */
    private function operatorSpendableNodes(string $operatorPubkey): \Illuminate\Support\Collection
    {
        $nodes = Node::query()
            ->where('operator_pubkey', $operatorPubkey)
            ->where('status', '!=', 'archived')
            ->where('credit_balance', '>', 0)
            ->get(['id', 'credit_balance']);

        return $nodes
            ->filter(fn (Node $node): bool => ! $this->nodeCreditsExpired((string) $node->id))
            ->sort(function (Node $a, Node $b): int {
                $aHorizon = $this->nodeCreditExpiryHorizon((string) $a->id)?->getTimestamp() ?? PHP_INT_MAX;
                $bHorizon = $this->nodeCreditExpiryHorizon((string) $b->id)?->getTimestamp() ?? PHP_INT_MAX;
                if ($aHorizon === $bHorizon) {
                    return strcmp((string) $a->id, (string) $b->id);
                }

                return $aHorizon <=> $bHorizon;
            })
            ->values();
    }

    private function nodeCreditsExpired(string $nodeId): bool
    {
        $balance = $this->balance($nodeId);
        if ($balance <= 0.0) {
            return false;
        }

        $hasOpenEndedEarn = CreditTransaction::where('node_id', $nodeId)
            ->where('type', 'credit')
            ->whereNull('expires_at')
            ->exists();
        if ($hasOpenEndedEarn) {
            return false;
        }

        $maxExpiresAt = CreditTransaction::where('node_id', $nodeId)
            ->where('type', 'credit')
            ->whereNotNull('expires_at')
            ->max('expires_at');

        if ($maxExpiresAt === null) {
            return false;
        }

        return Carbon::parse($maxExpiresAt)->isPast();
    }

    private function nodeCreditExpiryHorizon(string $nodeId): ?Carbon
    {
        $expiresAt = CreditTransaction::where('node_id', $nodeId)
            ->where('type', 'credit')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->min('expires_at');

        return $expiresAt ? Carbon::parse($expiresAt) : null;
    }

    /**
     * The 90-day TTL credit sink (ADR-035 / iicp-billing-extension §11.3, the primary
     * anti-inflation sink; complements the live 2% burn).
     *
     * A node is *idle* when its newest earn's `expires_at` is in the past
     * (`MAX(expires_at) < now`) and it still holds a positive balance. The sweep zeroes
     * such a node's balance and writes one `expire` row (encoded as a debit with
     * `reason='ttl_expire'` so the §summary `reconciles` invariant — balance == Σcredit −
     * Σdebit — stays intact). A fresh earn resets the node's TTL forward, removing it from
     * the idle set.
     *
     * Idempotent: after a sweep an idle node's balance is 0, so re-running expires nothing.
     *
     * @return array{expired_nodes: int, expired_credits: float}
     */
    public function expireIdleNodeCredits(): array
    {
        $now = now();
        $expiredNodes = 0;
        $expiredCredits = 0.0;

        // Nodes whose newest earn is past its TTL — the credit_transactions.expires_at
        // column (set on every earn) is the authority for the per-node TTL horizon.
        $idleNodeIds = CreditTransaction::query()
            ->where('type', 'credit')
            ->whereNotNull('expires_at')
            ->groupBy('node_id')
            ->havingRaw('MAX(expires_at) < ?', [$now])
            ->pluck('node_id');

        foreach ($idleNodeIds as $nodeId) {
            DB::transaction(function () use ($nodeId, &$expiredNodes, &$expiredCredits): void {
                $credit = Credit::where('node_id', $nodeId)->lockForUpdate()->first();
                $balance = $this->balance($nodeId);
                if ($balance <= 0.0) {
                    return; // idempotent: nothing left to sweep
                }

                if ($credit) {
                    $credit->update(['balance' => 0]);
                }
                Node::where('id', $nodeId)->update(['credit_balance' => 0]);

                CreditTransaction::create([
                    'node_id' => $nodeId,
                    'amount' => $balance,
                    'type' => 'debit',
                    'reason' => 'ttl_expire',
                ]);

                $expiredNodes++;
                $expiredCredits += $balance;
            });
        }

        return [
            'expired_nodes' => $expiredNodes,
            'expired_credits' => round($expiredCredits, 4),
        ];
    }
}

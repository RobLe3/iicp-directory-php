<?php

// SPDX-License-Identifier: Apache-2.0

/**
 * /api/v1/credits/* — node credit ledger HTTP surface.
 *
 * Credits are the cooperative-inference reciprocity unit (Phase 3+). One credit
 * == 1000 tokens of inference; operators earn credits by serving tasks and
 * spend them by consuming. The directory holds the canonical ledger so the
 * mesh has a tamper-evident, double-entry record without any node having to
 * trust another node's accounting.
 *
 * Endpoints:
 *   - GET  /credits/balance        — current balance for the authenticated node
 *   - POST /credits/award          — admin-only ledger credit (issue #115/#116)
 *   - GET  /credits/transactions   — recent transaction history for this node
 *
 * Implements:
 *   - ADR-008 §credits — initial credit accounting model
 *   - spec/iicp-core-v1.5.md §11 IAL — token-denominated credit framing
 *
 * Authentication: balance / transactions require node_token (operator reads
 * their own ledger); award routes through admin middleware for issuance.
 */

namespace App\Http\Controllers;

use App\Models\Node;
use App\Services\CreditService;
use App\Services\NodeEventLogger;
use App\Services\OtelTracer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CreditsController extends Controller
{
    public function __construct(
        private CreditService $credits,
        private NodeEventLogger $eventLogger,
    ) {}

    /**
     * Return the authenticated node's current credit balance.
     *
     * `tokens_per_credit` is echoed so clients never have to hard-code the
     * conversion factor — the directory is the authority on the unit definition.
     */
    public function balance(Request $request): JsonResponse
    {
        $span = OtelTracer::startSpan($request, 'iicp.directory.credits.balance');

        /** @var Node $node */
        $node = $request->get('_authenticated_node');
        $balance = $this->credits->balance($node->id);

        // Lazy free-credit allocation: award 5 credits when balance is zero and 6h period elapsed.
        // Pass source IP for RT-02b IP-level gate (#380).
        if ($balance <= 0) {
            $allocated = $this->credits->maybeAllocateFreeCredits($node->id, $request->ip() ?? '0.0.0.0');
            if ($allocated !== null) {
                $balance += $allocated;
                $this->eventLogger->log('CREDIT_ALLOCATION', (string) $node->id, [
                    'amount' => $allocated,
                    'reason' => 'free_allocation',
                    'period_h' => CreditService::FREE_CREDITS_PERIOD_HOURS,
                ]);
            }
        }

        $span->setAttribute('iicp.node_id', (string) $node->id)
            ->setAttribute('iicp.credit_balance', (float) $balance);
        $span->end();

        return response()->json([
            'node_id' => $node->id,
            'balance' => $balance,
            'unit' => 'credit',
            'tokens_per_credit' => 1000,
        ]);
    }

    /**
     * Issue credits to a node (admin-only route — see auth middleware).
     *
     * `min:0.0001` guards against precision-laundering attempts (0-amount or
     * round-to-zero awards that still write a ledger row). The reason string
     * is persisted so audits can reconstruct WHY a balance moved.
     */
    public function award(Request $request): JsonResponse
    {
        $span = OtelTracer::startSpan($request, 'iicp.directory.credits.award');

        $validated = $request->validate([
            // Accept both UUIDs and custom alphanumeric node names (≤36 chars) —
            // a custom-named node registers + heartbeats fine, so it must also be
            // creditable. Matches RegisterController/HeartbeatController + the
            // nodes.id CHAR(36) column. exists:nodes,id still enforces the node.
            'node_id' => ['required', 'string', 'max:36', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/', 'exists:nodes,id'],
            'task_id' => ['required', 'string', 'max:255'],
            'tokens_used' => ['required', 'integer', 'min:0'],
            'nonce' => ['required', 'string', 'min:32'],
            'expires_at' => ['required', 'string', 'date'],
            'signature' => ['required', 'string', 'size:64'],
            'response_hash' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'cip_parent_task_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cip_session_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.0001'],
            'reason' => ['sometimes', 'string', 'max:255'],
        ]);

        // IICP-E027: verify CIPWorkerReceipt HMAC-SHA256 before crediting
        $node = Node::find($validated['node_id']);
        if (! $node || ! $node->node_hmac_key) {
            $span->setAttribute('iicp.rejected', true)
                ->setAttribute('iicp.rejection_reason', 'hmac_key_not_provisioned');
            $span->end();

            return response()->json([
                'error' => ['code' => 'IICP-E027', 'message' => 'Node HMAC key not provisioned'],
            ], 422);
        }

        $canonical = implode(':', [
            $validated['task_id'],
            (string) $validated['tokens_used'],
            $validated['cip_parent_task_id'] ?? '',
            $validated['cip_session_key'] ?? '',
            $validated['nonce'],
            $validated['response_hash'],
        ]);
        $expected = hash_hmac('sha256', $canonical, $node->node_hmac_key);

        if (! hash_equals($expected, $validated['signature'])) {
            $span->setAttribute('iicp.rejected', true)
                ->setAttribute('iicp.rejection_reason', 'hmac_invalid');
            $span->end();

            return response()->json([
                'error' => ['code' => 'IICP-E027', 'message' => 'CIPWorkerReceipt HMAC validation failed'],
            ], 422);
        }

        // Spec §10.3: reject if receipt is expired
        $expiresAt = Carbon::parse($validated['expires_at']);
        if ($expiresAt->isPast()) {
            $span->setAttribute('iicp.rejected', true)
                ->setAttribute('iicp.rejection_reason', 'receipt_expired');
            $span->end();

            return response()->json([
                'error' => ['code' => 'IICP-E027', 'message' => 'CIPWorkerReceipt expired'],
            ], 422);
        }

        // TC-9c: reject credit inflation — amount must not exceed the ceiling computed from
        // tokens_used × credit_cost_multiplier (with 10% tolerance for rounding).
        // Checked before the nonce lock so inflated receipts don't consume valid nonces.
        $multiplier = max(0.001, (float) ($node->credit_cost_multiplier ?? 1.0));
        $ceiling = (int) ceil($validated['tokens_used'] / 1000) * $multiplier;
        if ((float) $validated['amount'] > $ceiling * 1.1 + 0.0001) {
            $span->setAttribute('iicp.rejected', true)
                ->setAttribute('iicp.rejection_reason', 'credit_ceiling_exceeded');
            $span->end();

            return response()->json([
                'error' => ['code' => 'IICP-E027', 'message' => 'CIPWorkerReceipt amount exceeds authorized credit ceiling'],
            ], 422);
        }

        // TC-9b: credit laundering rate limit — max 1000 credits awarded per node_id per hour.
        // Colluding nodes earn unusually fast; this floor detects coordinated manipulation.
        // Checked before the nonce lock so laundering attempts don't consume valid nonces.
        $hourKey = 'award_hourly:'.$validated['node_id'].':'.now()->format('YmdH');
        $hourlyEarned = (float) (Cache::get($hourKey, 0));
        if ($hourlyEarned + (float) $validated['amount'] > 1000.0) {
            $span->setAttribute('iicp.rejected', true)
                ->setAttribute('iicp.rejection_reason', 'rate_limit_exceeded');
            $span->end();

            return response()->json([
                'error' => ['code' => 'IICP-E027', 'message' => 'Credit award rate limit exceeded (max 1000 credits/hour per node)'],
            ], 422);
        }

        // TC-9d: atomic nonce lock — Cache::add() is set-if-not-exists, preventing the TOCTOU
        // race between has() + put() that allows concurrent requests to double-spend the same nonce.
        // Placed after ceiling check so inflated receipts don't burn valid nonces.
        $nonceCacheKey = 'award_nonce:'.$validated['nonce'];
        if (! Cache::add($nonceCacheKey, 1, $expiresAt->copy()->addHours(24))) {
            $span->setAttribute('iicp.rejected', true)
                ->setAttribute('iicp.rejection_reason', 'nonce_replayed');
            $span->end();

            return response()->json([
                'error' => ['code' => 'IICP-E027', 'message' => 'CIPWorkerReceipt nonce already used (replay rejected)'],
            ], 422);
        }

        $newBalance = $this->credits->award(
            nodeId: $validated['node_id'],
            amount: (float) $validated['amount'],
            reason: $validated['reason'] ?? null,
        );

        // Increment hourly counter after successful award (expires at end of next hour).
        Cache::put($hourKey, $hourlyEarned + (float) $validated['amount'], 7200);

        $this->eventLogger->log('CREDIT_AWARD', $validated['node_id'], [
            'task_id' => $validated['task_id'],
            'tokens_used' => $validated['tokens_used'],
            'amount' => $validated['amount'],
            'new_balance' => $newBalance,
            'response_hash' => $validated['response_hash'],
            'cip_parent_task_id' => $validated['cip_parent_task_id'] ?? null,
            'cip_session_key' => $validated['cip_session_key'] ?? null,
        ]);

        $span->setAttribute('iicp.node_id', $validated['node_id'])
            ->setAttribute('iicp.credit_amount', (float) $validated['amount'])
            ->setAttribute('iicp.tokens_used', (int) $validated['tokens_used'])
            ->setAttribute('iicp.new_balance', $newBalance);
        $span->end();

        return response()->json([
            'node_id' => $validated['node_id'],
            'awarded' => $validated['amount'],
            'balance' => $newBalance,
        ]);
    }

    /**
     * Recent ledger entries for the authenticated node.
     *
     * The window and ordering are owned by CreditService::recentTransactions —
     * keep the HTTP layer agnostic so we can swap pagination strategies
     * (cursor vs offset) without breaking the endpoint contract.
     */
    public function transactions(Request $request): JsonResponse
    {
        $span = OtelTracer::startSpan($request, 'iicp.directory.credits.transactions');

        /** @var Node $node */
        $node = $request->get('_authenticated_node');
        $transactions = $this->credits->recentTransactions($node->id);

        $span->setAttribute('iicp.node_id', (string) $node->id)
            ->setAttribute('iicp.tx_count', count($transactions));
        $span->end();

        return response()->json([
            'node_id' => $node->id,
            'transactions' => $transactions,
        ]);
    }

    /**
     * GET /v1/credits/quote — credit cost estimate for a task (§8 CIP pre-flight).
     *
     * Required: intent (URN), max_tokens (int > 0)
     * Optional: nodes (comma-separated node UUIDs to quote against)
     *
     * Returns a quote valid for ≤ 60 seconds. Uses the default credit rate until
     * per-node declarative pricing (ADR-019) is implemented in Phase 5B.
     *
     * Error codes: 401 if bearer absent/invalid, 422 if intent or max_tokens missing.
     */
    public function quote(Request $request): JsonResponse
    {
        $span = OtelTracer::startSpan($request, 'iicp.directory.credits.quote');

        /** @var Node $consumer */
        $consumer = $request->get('_authenticated_node');
        $intent = $request->query('intent');
        $maxTokens = $request->query('max_tokens');
        $nodesParam = $request->query('nodes');
        $minReputation = $request->query('min_reputation') !== null
            ? max(0.0, min(1.0, (float) $request->query('min_reputation')))
            : 0.0;

        // §8.3 normative: 422 if intent or max_tokens missing
        if (! $intent || ! $maxTokens) {
            $span->end();

            return response()->json([
                'error' => ['code' => 'validation_error', 'message' => 'intent and max_tokens are required'],
            ], 422);
        }

        $maxTokens = (int) $maxTokens;
        if ($maxTokens <= 0) {
            $span->end();

            return response()->json([
                'error' => ['code' => 'validation_error', 'message' => 'max_tokens must be a positive integer'],
            ], 422);
        }

        // Determine candidate nodes: filter by intent capability, optionally by node IDs
        $query = Node::where('available', true)
            ->whereHas('capabilities', fn ($q) => $q->where('intent', $intent));

        if ($nodesParam) {
            $nodeIds = array_filter(array_map('trim', explode(',', $nodesParam)));
            if (! empty($nodeIds)) {
                $query->whereIn('id', $nodeIds);
            }
        }

        // Apply ADR-019 per-node pricing: fetch credit_cost_multiplier from candidates.
        // base rate = 1 credit per 1000 tokens; multiplier scales per provider.
        // min_reputation filter: consistent with discover endpoint (NodeScorer default = 0.5 for nodes without a record).
        $nodes = $query->with('reputation:node_id,score')->select(['id', 'credit_cost_multiplier'])->get();
        if ($minReputation > 0.0) {
            $nodes = $nodes->filter(
                fn (Node $node) => ($node->reputation?->score ?? 0.5) >= $minReputation
            );
        }
        $nodesQuoted = $nodes->count();
        $baseBlocks = (int) ceil($maxTokens / 1000);

        if ($nodesQuoted > 0) {
            $multipliers = $nodes->pluck('credit_cost_multiplier')
                ->map(fn ($m) => max(0.0, (float) ($m ?? 1.0)));
            $minCredits = round($baseBlocks * $multipliers->min(), 4);
            $maxCredits = round($baseBlocks * $multipliers->max(), 4);
            $avgMultiplier = $multipliers->avg();
            $estimatedCredits = round($baseBlocks * $avgMultiplier, 4);
            $pricePerThousand = round($avgMultiplier, 4);
        } else {
            $minCredits = round($baseBlocks * 1.0, 4);
            $maxCredits = $minCredits;
            $estimatedCredits = $minCredits;
            $pricePerThousand = 1.0;
        }

        // §8.3: quote valid for ≤ 60 seconds
        $expiresAt = now()->addSeconds(60)->toISOString();

        // S.12 §2.1: consumer pre-flight — balance check lets the proxy decide whether
        // to fall back to local execution before dispatching the CIP sub-task.
        $consumerBalance = $this->credits->balance($consumer->id);
        $balanceSufficient = $consumerBalance >= $estimatedCredits;

        $span->setAttribute('iicp.intent', (string) $intent)
            ->setAttribute('iicp.max_tokens', $maxTokens)
            ->setAttribute('iicp.nodes_quoted', $nodesQuoted)
            ->setAttribute('iicp.estimated_credits', $estimatedCredits)
            ->setAttribute('iicp.consumer_balance', (float) $consumerBalance)
            ->setAttribute('iicp.balance_sufficient', $balanceSufficient);
        $span->end();

        return response()->json([
            'quote_id' => 'q_'.Str::random(12),
            'estimated_credits' => $estimatedCredits,
            'min_credits' => $minCredits,
            'max_credits' => $maxCredits,
            'price_per_1000_tokens' => $pricePerThousand,
            'nodes_quoted' => $nodesQuoted,
            'quote_expires_at' => $expiresAt,
            'currency' => 'iicp_credits',
            // S.12 §2.1 pre-flight fields: proxy uses these to decide local vs remote
            'consumer_balance' => $consumerBalance,
            'balance_sufficient' => $balanceSufficient,
        ]);
    }
}

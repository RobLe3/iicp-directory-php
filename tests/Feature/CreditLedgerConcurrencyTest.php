<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Credit;
use App\Models\CreditIpGate;
use App\Models\CreditTransaction;
use App\Models\Node;
use App\Services\CreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Real MariaDB evidence for credit-ledger invariants under separate writers.
 */
class CreditLedgerConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the dedicated MariaDB concurrency job.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires the pcntl extension.');
        }

        DB::table('credit_transactions')->delete();
        DB::table('credits')->delete();
        DB::table('credit_ip_gates')->delete();
        DB::table('nodes')->delete();
    }

    public function test_concurrent_debits_cannot_overspend_and_reconcile(): void
    {
        $node = $this->node(100.0);
        Credit::create(['node_id' => $node->id, 'balance' => 100]);
        CreditTransaction::create([
            'node_id' => $node->id,
            'amount' => 100,
            'type' => 'credit',
            'reason' => 'opening_balance',
        ]);

        $results = $this->forkWorkers(8, fn (int $worker): bool => app(CreditService::class)->debit(
            (string) $node->id,
            20.0,
            "debit-{$worker}",
            'concurrency_test',
        ));

        $this->assertSame(5, count(array_filter($results)));
        $this->assertBalanceAndLedger((string) $node->id, 0.0, 100.0, 100.0, 6);
    }

    public function test_concurrent_awards_have_no_lost_updates(): void
    {
        $node = $this->node(0.0);
        Credit::create(['node_id' => $node->id, 'balance' => 0]);

        $results = $this->forkWorkers(
            8,
            fn (int $worker): float => app(CreditService::class)->award(
                (string) $node->id,
                1.25,
                "concurrent_award_{$worker}",
            ),
        );

        $this->assertCount(8, $results);
        $this->assertBalanceAndLedger((string) $node->id, 10.0, 10.0, 0.0, 8);
    }

    public function test_concurrent_free_allocations_share_one_ip_gate(): void
    {
        $nodeIds = [];
        for ($worker = 0; $worker < 8; $worker++) {
            $nodeIds[] = (string) $this->node(0.0)->id;
        }

        $results = $this->forkWorkers(
            8,
            fn (int $worker): ?float => app(CreditService::class)->maybeAllocateFreeCredits(
                $nodeIds[$worker],
                '198.51.100.42',
            ),
        );

        $this->assertSame(1, count(array_filter($results, static fn ($value): bool => $value !== null)));
        $this->assertSame(5.0, (float) Node::whereIn('id', $nodeIds)->sum('credit_balance'));
        $this->assertSame(1, CreditTransaction::where('reason', 'free_allocation')->count());
        $gate = CreditIpGate::findOrFail('198.51.100.42');
        $this->assertSame(1, $gate->allocation_count);
    }

    public function test_concurrent_operator_wallet_debits_lock_nodes_in_one_order(): void
    {
        $operator = base64_encode(random_bytes(32));
        $consumer = $this->node(50.0, $operator);
        $peer = $this->node(50.0, $operator);
        Credit::create(['node_id' => $consumer->id, 'balance' => 50]);
        Credit::create(['node_id' => $peer->id, 'balance' => 50]);

        $results = $this->forkWorkers(
            4,
            fn (int $worker): array => app(CreditService::class)->debitForConsumer(
                (string) $consumer->id,
                30.0,
                "wallet-{$worker}",
                'concurrency_test',
            ),
        );

        $this->assertSame(3, count(array_filter($results, static fn (array $result): bool => $result['debited'])));
        $this->assertSame(10.0, (float) Node::whereIn('id', [$consumer->id, $peer->id])->sum('credit_balance'));
        $this->assertSame(90.0, (float) CreditTransaction::where('type', 'debit')->sum('amount'));
        foreach ([$consumer->id, $peer->id] as $nodeId) {
            $this->assertSame(
                (float) Credit::where('node_id', $nodeId)->value('balance'),
                (float) Node::where('id', $nodeId)->value('credit_balance'),
            );
        }
    }

    private function node(float $balance, ?string $operator = null): Node
    {
        return Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://'.Str::lower(Str::random(12)).'.example.com',
            'region' => 'test',
            'node_token_hash' => password_hash(Str::random(32), PASSWORD_BCRYPT),
            'max_concurrent' => 1,
            'tokens_per_min' => 1000,
            'credit_balance' => $balance,
            'operator_pubkey' => $operator,
            'operator_verified' => $operator !== null,
            'status' => 'active',
        ]);
    }

    /**
     * @template T
     *
     * @param  callable(int):T  $callback
     * @return array<int, T>
     */
    private function forkWorkers(int $workerCount, callable $callback): array
    {
        $runId = (string) Str::uuid();
        $barrier = sys_get_temp_dir()."/iicp-credit-start-{$runId}";
        $resultPrefix = sys_get_temp_dir()."/iicp-credit-result-{$runId}";
        $children = [];

        for ($worker = 0; $worker < $workerCount; $worker++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork failed');
            if ($pid === 0) {
                DB::purge();
                DB::reconnect();
                while (! file_exists($barrier)) {
                    usleep(1000);
                }

                try {
                    file_put_contents(
                        "{$resultPrefix}-{$worker}",
                        json_encode(['ok' => true, 'value' => $callback($worker)], JSON_THROW_ON_ERROR),
                    );
                    exit(0);
                } catch (\Throwable $throwable) {
                    file_put_contents("{$resultPrefix}-{$worker}", json_encode([
                        'ok' => false,
                        'error' => $throwable::class.': '.$throwable->getMessage(),
                    ], JSON_THROW_ON_ERROR));
                    exit(1);
                }
            }
            $children[$worker] = $pid;
        }

        touch($barrier);
        $results = [];
        $failures = [];
        foreach ($children as $worker => $pid) {
            pcntl_waitpid($pid, $status);
            $file = "{$resultPrefix}-{$worker}";
            $payload = file_exists($file)
                ? json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)
                : ['ok' => false, 'error' => "worker {$worker} produced no result"];
            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0 || ! $payload['ok']) {
                $failures[] = $payload['error'] ?? "worker {$worker} exited abnormally";
            } else {
                $results[$worker] = $payload['value'];
            }
            @unlink($file);
        }
        @unlink($barrier);
        $this->assertSame([], $failures, implode("\n", $failures));

        DB::purge();
        DB::reconnect();
        ksort($results);

        return $results;
    }

    private function assertBalanceAndLedger(
        string $nodeId,
        float $balance,
        float $earned,
        float $spent,
        int $transactionCount,
    ): void {
        $this->assertSame($balance, (float) Credit::where('node_id', $nodeId)->value('balance'));
        $this->assertSame($balance, (float) Node::where('id', $nodeId)->value('credit_balance'));
        $this->assertSame($earned, (float) CreditTransaction::where('node_id', $nodeId)->where('type', 'credit')->sum('amount'));
        $this->assertSame($spent, (float) CreditTransaction::where('node_id', $nodeId)->where('type', 'debit')->sum('amount'));
        $this->assertSame($transactionCount, CreditTransaction::where('node_id', $nodeId)->count());
        $this->assertEqualsWithDelta($balance, $earned - $spent, 0.0001);
    }
}

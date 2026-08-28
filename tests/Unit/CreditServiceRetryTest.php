<?php

namespace Tests\Unit;

use App\Models\Node;
use App\Services\CreditService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDOException;
use ReflectionMethod;
use Tests\TestCase;

class CreditServiceRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_concurrency_retry_is_bounded_observable_and_rolls_back_failed_attempts(): void
    {
        Log::spy();
        $attempts = 0;
        $nodeId = (string) Str::uuid();
        $method = new ReflectionMethod(CreditService::class, 'transactionWithRetry');
        $method->setAccessible(true);

        $result = $method->invoke(app(CreditService::class), 'retry_test', function () use (&$attempts, $nodeId): string {
            $attempts++;
            Node::create([
                'id' => $nodeId,
                'endpoint' => 'https://retry.example.com',
                'region' => 'test',
                'node_token_hash' => password_hash('retry-token', PASSWORD_BCRYPT),
                'max_concurrent' => 1,
                'tokens_per_min' => 1000,
            ]);
            if ($attempts < 3) {
                throw $this->deadlock();
            }

            return 'committed';
        });

        $this->assertSame('committed', $result);
        $this->assertSame(3, $attempts);
        $this->assertSame(1, Node::where('id', $nodeId)->count());
        Log::shouldHaveReceived('warning')
            ->twice()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'credit_ledger_transaction_retry'
                && $context['operation'] === 'retry_test'
                && $context['maximum_attempts'] === 5
                && ! array_key_exists('sql', $context));
    }

    public function test_persistent_storage_failure_is_not_retried_and_rolls_back_partial_state(): void
    {
        Log::spy();
        $attempts = 0;
        $nodeId = (string) Str::uuid();
        $method = new ReflectionMethod(CreditService::class, 'transactionWithRetry');
        $method->setAccessible(true);

        try {
            $method->invoke(app(CreditService::class), 'storage_failure_test', function () use (&$attempts, $nodeId): void {
                $attempts++;
                Node::create([
                    'id' => $nodeId,
                    'endpoint' => 'https://storage-failure.example.com',
                    'region' => 'test',
                    'node_token_hash' => password_hash('storage-failure-token', PASSWORD_BCRYPT),
                    'max_concurrent' => 1,
                    'tokens_per_min' => 1000,
                ]);
                throw $this->storageFull();
            });
            $this->fail('A persistent storage failure must escape the transaction.');
        } catch (QueryException $exception) {
            $this->assertSame(1021, (int) ($exception->errorInfo[1] ?? 0));
        }

        $this->assertSame(1, $attempts, 'Persistent storage failures must not enter the concurrency retry loop.');
        $this->assertSame(0, Node::where('id', $nodeId)->count(), 'The failed transaction must not retain partial state.');
        Log::shouldNotHaveReceived('warning');
    }

    private function deadlock(): QueryException
    {
        $previous = new PDOException('synthetic deadlock', 1213);
        $previous->errorInfo = ['40001', 1213, 'synthetic deadlock'];

        return new QueryException('sqlite', 'content must not be logged', [], $previous);
    }

    private function storageFull(): QueryException
    {
        $previous = new PDOException('synthetic storage full', 1021);
        $previous->errorInfo = ['HY000', 1021, 'synthetic storage full'];

        return new QueryException('sqlite', 'content must not be logged', [], $previous);
    }
}

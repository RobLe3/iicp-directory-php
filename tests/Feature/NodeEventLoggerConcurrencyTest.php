<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\NodeEvent;
use App\Services\NodeEventLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Real-database evidence for serialized signed event-log appends.
 *
 * This test is skipped by the default SQLite suite and runs in the dedicated
 * MariaDB CI job. Each child uses its own connection and PHP process.
 */
class NodeEventLoggerConcurrencyTest extends TestCase
{
    private const WORKERS = 8;

    private const EVENTS_PER_WORKER = 8;

    private const SECRET_KEY_HEX = '1111111111111111111111111111111111111111111111111111111111111111'
        .'d04ab232742bb4ab3a1368bd4615e4e6d0224ab71a016baf8520a332c9778737';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the dedicated MariaDB concurrency job.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires the pcntl extension.');
        }

        DB::table('node_events')->delete();
        DB::table('node_event_chain_heads')
            ->where('chain_id', 'genesis')
            ->update(['last_seq' => 0, 'last_signature' => null]);
        config(['app.genesis_ed25519_secret_key' => self::SECRET_KEY_HEX]);
    }

    public function test_concurrent_writers_produce_one_contiguous_verifiable_chain(): void
    {
        $barrier = sys_get_temp_dir().'/iicp-event-start-'.Str::uuid();
        $errorPrefix = sys_get_temp_dir().'/iicp-event-error-'.Str::uuid();
        $children = [];

        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork failed');

            if ($pid === 0) {
                DB::purge();
                DB::reconnect();

                while (! file_exists($barrier)) {
                    usleep(1000);
                }

                try {
                    $logger = app(NodeEventLogger::class);
                    for ($event = 0; $event < self::EVENTS_PER_WORKER; $event++) {
                        $logger->log('HEARTBEAT', (string) Str::uuid(), [
                            'worker' => $worker,
                            'event' => $event,
                        ]);
                    }
                    exit(0);
                } catch (\Throwable $throwable) {
                    file_put_contents(
                        "{$errorPrefix}-{$worker}",
                        $throwable::class.': '.$throwable->getMessage(),
                    );
                    exit(1);
                }
            }

            $children[$worker] = $pid;
        }

        touch($barrier);
        $failures = [];
        foreach ($children as $worker => $pid) {
            pcntl_waitpid($pid, $status);
            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $errorFile = "{$errorPrefix}-{$worker}";
                $failures[] = file_exists($errorFile)
                    ? file_get_contents($errorFile)
                    : "worker {$worker} exited abnormally";
            }
        }

        @unlink($barrier);
        foreach (array_keys($children) as $worker) {
            @unlink("{$errorPrefix}-{$worker}");
        }

        $this->assertSame([], $failures, implode("\n", $failures));

        DB::purge();
        DB::reconnect();
        $events = NodeEvent::query()->orderBy('seq')->get();
        $expectedCount = self::WORKERS * self::EVENTS_PER_WORKER;

        $this->assertCount($expectedCount, $events);
        $this->assertSame(range(1, $expectedCount), $events->pluck('seq')->all());
        $this->assertSame($expectedCount, $events->pluck('event_id')->unique()->count());

        $publicKey = sodium_crypto_sign_publickey_from_secretkey(
            sodium_hex2bin(self::SECRET_KEY_HEX),
        );
        $previousSignature = null;

        foreach ($events as $event) {
            $this->assertSame(
                app(NodeEventLogger::class)->prevHash($previousSignature),
                $event->prev_hash,
                "event {$event->seq} must bind its immediate predecessor",
            );
            $this->assertTrue(
                sodium_crypto_sign_verify_detached(
                    sodium_hex2bin($event->signature),
                    $this->signingMessage($event),
                    $publicKey,
                ),
                "event {$event->seq} signature must verify",
            );
            $previousSignature = $event->signature;
        }

        $head = DB::table('node_event_chain_heads')->where('chain_id', 'genesis')->first();
        $this->assertSame($expectedCount, (int) $head->last_seq);
        $this->assertSame($previousSignature, $head->last_signature);
    }

    private function signingMessage(NodeEvent $event): string
    {
        $payloadHash = hash('sha256', NodeEventLogger::canonicalJson($event->payload));

        return hash('sha256', implode(':', [
            $event->event_id,
            $event->event_type,
            (string) $event->seq,
            (string) $event->ts_ms,
            $payloadHash,
            $event->prev_hash,
        ]), true);
    }
}

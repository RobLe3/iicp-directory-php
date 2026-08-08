<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Reputation;
use App\Services\ReputationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Real MariaDB evidence for the RT-01b hourly reputation budget. */
class ReputationVelocityConcurrencyTest extends TestCase
{
    private const WORKERS = 4;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the dedicated MariaDB concurrency job.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires the pcntl extension.');
        }

        DB::table('reputations')->delete();
        DB::table('nodes')->delete();
    }

    public function test_concurrent_heartbeats_share_one_persisted_hourly_budget(): void
    {
        $node = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://reputation-concurrency.example.com',
            'region' => 'test',
            'node_token_hash' => password_hash(Str::random(32), PASSWORD_BCRYPT),
            'max_concurrent' => 1,
            'tokens_per_min' => 1000,
            'status' => 'active',
        ]);

        $runId = (string) Str::uuid();
        $barrier = sys_get_temp_dir()."/iicp-reputation-start-{$runId}";
        $errorPrefix = sys_get_temp_dir()."/iicp-reputation-error-{$runId}";
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
                    app(ReputationService::class)->upsert(
                        (string) $node->id,
                        tasksSuccess: 10,
                        tasksFailed: 0,
                        avgLatencyMs: 100.0,
                    );
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
            @unlink("{$errorPrefix}-{$worker}");
        }
        @unlink($barrier);
        $this->assertSame([], $failures, implode("\n", $failures));

        DB::purge();
        DB::reconnect();
        $storedNode = Node::findOrFail($node->id);
        $reputation = Reputation::findOrFail($node->id);

        $this->assertSame(0.7, (float) $storedNode->reputation_score);
        $this->assertSame(0.7, (float) $reputation->score);
        $this->assertSame(ReputationService::MAX_HOURLY_REPUTATION_GAIN, (float) $storedNode->rep_hourly_gain);
        $this->assertSame(40, (int) $reputation->completed_tasks_count);
        $this->assertSame(40, (int) $storedNode->tasks_total);
    }
}

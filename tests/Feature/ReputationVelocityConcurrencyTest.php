<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Reputation;
use App\Services\ReputationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Real MariaDB evidence for the RT-01b hourly reputation budget. */
class ReputationVelocityConcurrencyTest extends TestCase
{
    private function fixture(): array
    {
        return json_decode(
            file_get_contents(base_path('parity/reputation-hourly-velocity-v0.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

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
        $fixture = $this->fixture();
        $inputs = $fixture['inputs'];
        $expected = $fixture['expected'];

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

        for ($worker = 0; $worker < $inputs['workers']; $worker++) {
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
                        tasksSuccess: $inputs['tasks_success_per_worker'],
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

        $this->assertSame($expected['concurrent_score'], (float) $storedNode->reputation_score);
        $this->assertSame($expected['concurrent_score'], (float) $reputation->score);
        $this->assertSame($expected['concurrent_hourly_gain'], (float) $storedNode->rep_hourly_gain);
        $this->assertSame($expected['concurrent_tasks_total'], (int) $reputation->completed_tasks_count);
        $this->assertSame($expected['concurrent_tasks_total'], (int) $storedNode->tasks_total);

        // Use one fixed application clock for the exact boundary checks. The
        // database remains real MariaDB; only the application-side policy clock
        // is deterministic so 3599 and 3600 seconds cannot flake at a wall-clock
        // tick between setup and the heartbeat.
        $clock = now()->startOfSecond();
        Carbon::setTestNow($clock);
        try {
            $storedNode->update([
                'rep_hourly_window_start' => $clock->copy()->subSeconds($expected['same_window_age_seconds']),
            ]);
            app(ReputationService::class)->upsert(
                (string) $node->id,
                tasksSuccess: 10,
                tasksFailed: 0,
                avgLatencyMs: 100.0,
            );
            $sameWindow = Node::findOrFail($node->id);
            $this->assertSame($expected['same_window_score'], (float) $sameWindow->reputation_score);
            $this->assertSame($expected['concurrent_hourly_gain'], (float) $sameWindow->rep_hourly_gain);

            $sameWindow->update([
                'rep_hourly_window_start' => $clock->copy()->subSeconds($expected['next_window_age_seconds']),
            ]);
            // Resolve a new service instance to model repository/service reload;
            // the persisted node state is the only hourly-budget input.
            app()->forgetInstance(ReputationService::class);
            app(ReputationService::class)->upsert(
                (string) $node->id,
                tasksSuccess: 10,
                tasksFailed: 0,
                avgLatencyMs: 100.0,
            );
            $nextWindow = Node::findOrFail($node->id);
            $this->assertSame($expected['next_window_score_after_first_positive'], (float) $nextWindow->reputation_score);
            $this->assertSame($expected['next_window_hourly_gain_after_first_positive'], (float) $nextWindow->rep_hourly_gain);

            // Consume the remaining positive budget, then resolve another
            // service instance before applying a negative delta. This proves
            // the persisted window state, rather than in-memory service state,
            // governs both operations after reload.
            app(ReputationService::class)->upsert(
                (string) $node->id,
                tasksSuccess: 10,
                tasksFailed: 0,
                avgLatencyMs: 100.0,
            );
            app()->forgetInstance(ReputationService::class);
            app(ReputationService::class)->upsert(
                (string) $node->id,
                tasksSuccess: 0,
                tasksFailed: 1,
                avgLatencyMs: 100.0,
            );
            $finalNode = Node::findOrFail($node->id);
            $this->assertSame($expected['final_score_after_reload_and_negative'], (float) $finalNode->reputation_score);
            $this->assertSame($expected['final_hourly_gain'], (float) $finalNode->rep_hourly_gain);
        } finally {
            Carbon::setTestNow();
        }
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\TrustDomainMembership;
use App\Services\TrustDomainMembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Real MariaDB evidence for first issuance and rotation serialization. */
class RestrictedDomainMembershipConcurrencyTest extends TestCase
{
    private const WORKERS = 6;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the dedicated MariaDB concurrency job.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires the pcntl extension.');
        }

        DB::table('trust_domain_memberships')->delete();
        config()->set('iicp.restricted_domain.enabled', true);
        config()->set('iicp.restricted_domain.domain_id', 'concurrency.example');
        config()->set('iicp.restricted_domain.authority_id', 'did:key:directory');
        config()->set('iicp.restricted_domain.membership_epoch', 1);
        config()->set('iicp.restricted_domain.max_credential_ttl_seconds', 86400);
    }

    public function test_concurrent_first_issuance_and_rotations_are_serialized(): void
    {
        $runId = (string) Str::uuid();
        $barrier = sys_get_temp_dir()."/iicp-membership-start-{$runId}";
        $errorPrefix = sys_get_temp_dir()."/iicp-membership-error-{$runId}";
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
                    app(TrustDomainMembershipService::class)->issue(
                        'node',
                        'shared-node',
                        ['bootstrap', 'peers'],
                        3600,
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
        $memberships = TrustDomainMembership::query()
            ->where('domain_id', 'concurrency.example')
            ->where('subject_kind', 'node')
            ->where('subject_id', 'shared-node')
            ->get();

        $this->assertCount(1, $memberships);
        $this->assertSame(self::WORKERS, $memberships->sole()->generation);
        $this->assertNull($memberships->sole()->revoked_at);
    }
}

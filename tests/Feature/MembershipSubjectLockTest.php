<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Services\MembershipSubjectLock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class MembershipSubjectLockTest extends TestCase
{
    public function test_mysql_lock_is_bounded_and_released(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        DB::shouldReceive('connection')->once()->andReturn($connection);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => $sql === 'SELECT GET_LOCK(?, 10) AS acquired'
                && count($bindings) === 1
                && str_starts_with($bindings[0], 'iicp-mem:')
                && strlen($bindings[0]) <= 64)
            ->andReturn((object) ['acquired' => 1]);
        DB::shouldReceive('selectOne')
            ->once()
            ->with('SELECT RELEASE_LOCK(?) AS released', Mockery::type('array'))
            ->andReturn((object) ['released' => 1]);

        $lock = app(MembershipSubjectLock::class);
        $lockName = $lock->acquire('example.internal', 'node', 'node-a');
        $this->assertNotNull($lockName);
        $lock->release($lockName);
    }

    public function test_mysql_lock_refusal_fails_closed(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        DB::shouldReceive('connection')->once()->andReturn($connection);
        DB::shouldReceive('selectOne')->once()->andReturn((object) ['acquired' => 0]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('membership_subject_lock_unavailable');

        app(MembershipSubjectLock::class)->acquire('example.internal', 'node', 'node-a');
    }

    public function test_release_failure_disconnects_without_masking_committed_issuance(): void
    {
        DB::shouldReceive('selectOne')->once()->andThrow(new \RuntimeException('connection lost'));
        DB::shouldReceive('disconnect')->once();

        app(MembershipSubjectLock::class)->release('iicp-mem:held');
        $this->addToAssertionCount(1);
    }
}

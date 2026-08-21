<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Serializes membership creation even when no subject row exists yet. */
class MembershipSubjectLock
{
    public function acquire(string $domainId, string $subjectKind, string $subjectId): ?string
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return null;
        }

        $lockName = 'iicp-mem:'.substr(hash('sha256', implode("\0", [
            $domainId,
            $subjectKind,
            $subjectId,
        ])), 0, 54);
        $result = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
        if ((int) ($result->acquired ?? 0) !== 1) {
            throw new \RuntimeException('membership_subject_lock_unavailable');
        }

        return $lockName;
    }

    public function release(?string $lockName): void
    {
        if ($lockName === null) {
            return;
        }

        try {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        } catch (\Throwable) {
            // Do not turn a committed issuance into an apparent failure.
            // Dropping the connection also releases any surviving named lock.
            DB::disconnect();
        }
    }
}

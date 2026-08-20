<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Services\TrustDomainMembershipService;
use Illuminate\Console\Command;

class RevokeTrustDomainMembership extends Command
{
    protected $signature = 'iicp:membership-revoke {kind : node or client} {subject : Stable subject identifier}';

    protected $description = 'Revoke a restricted trust-domain membership credential';

    public function handle(TrustDomainMembershipService $memberships): int
    {
        try {
            $revoked = $memberships->revoke(
                (string) $this->argument('kind'),
                (string) $this->argument('subject'),
            );
        } catch (\InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        if (! $revoked) {
            $this->error('No active membership matched the requested subject.');

            return self::FAILURE;
        }

        $this->info('Membership revoked. New protected operations will fail immediately.');

        return self::SUCCESS;
    }
}

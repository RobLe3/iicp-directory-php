<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Services\TrustDomainMembershipService;
use Illuminate\Console\Command;

class IssueTrustDomainMembership extends Command
{
    protected $signature = 'iicp:membership-issue
        {kind : node or client}
        {subject : Stable subject identifier}
        {--scope=* : Permitted operation; repeat for multiple scopes}
        {--ttl=3600 : Credential lifetime in seconds}';

    protected $description = 'Issue or rotate a restricted trust-domain membership credential';

    public function handle(TrustDomainMembershipService $memberships): int
    {
        try {
            $issued = $memberships->issue(
                (string) $this->argument('kind'),
                (string) $this->argument('subject'),
                (array) $this->option('scope'),
                (int) $this->option('ttl'),
            );
        } catch (\InvalidArgumentException|\LogicException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $membership = $issued['membership'];
        $this->warn('Store this credential now. It will not be shown again.');
        $this->line($issued['token']);
        $this->newLine();
        $this->info(sprintf(
            'Issued %s membership for %s in %s; generation %d; expires %s.',
            $membership->subject_kind,
            $membership->subject_id,
            $membership->domain_id,
            $membership->generation,
            $membership->expires_at->toIso8601String(),
        ));

        return self::SUCCESS;
    }
}

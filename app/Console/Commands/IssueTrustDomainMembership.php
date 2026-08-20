<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Models\TrustDomainMembership;
use App\Services\TrustDomainMembershipService;
use Illuminate\Console\Command;

class IssueTrustDomainMembership extends Command
{
    protected $signature = 'iicp:membership-issue
        {kind : node or client}
        {subject : Stable subject identifier}
        {--scope=* : Permitted operation; repeat for multiple scopes}
        {--ttl=3600 : Credential lifetime in seconds}
        {--key-id= : Subject Ed25519 key identifier for a peer-verifiable assertion}
        {--public-key= : Subject Ed25519 public key as unpadded base64url}';

    protected $description = 'Issue or rotate a restricted trust-domain membership credential';

    public function handle(TrustDomainMembershipService $memberships): int
    {
        try {
            $issued = $this->issueFromOptions($memberships);
        } catch (\InvalidArgumentException|\LogicException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $membership = $issued['membership'];
        $this->warn('Store this credential now. It will not be shown again.');
        $this->line($issued['token']);
        $this->writeAssertion($issued['assertion'] ?? null);
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

    private function writeAssertion(?array $assertion): void
    {
        if ($assertion === null) {
            return;
        }
        $this->newLine();
        $this->line(json_encode($assertion, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array{membership: TrustDomainMembership, token: string, assertion?: array} */
    private function issueFromOptions(TrustDomainMembershipService $memberships): array
    {
        $keyId = (string) ($this->option('key-id') ?? '');
        $publicKey = (string) ($this->option('public-key') ?? '');
        $this->assertKeyOptionsComplete($keyId, $publicKey);
        $arguments = [
            (string) $this->argument('kind'),
            (string) $this->argument('subject'),
            (array) $this->option('scope'),
            (int) $this->option('ttl'),
        ];
        if ($keyId === '') {
            return $memberships->issue(...$arguments);
        }

        $arguments[] = $keyId;
        $arguments[] = $publicKey;

        return $memberships->issueWithAssertion(...$arguments);
    }

    private function assertKeyOptionsComplete(string $keyId, string $publicKey): void
    {
        if (($keyId === '') !== ($publicKey === '')) {
            throw new \InvalidArgumentException('--key-id and --public-key must be supplied together');
        }
    }
}

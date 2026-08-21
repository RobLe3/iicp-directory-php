<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Models\TrustDomainMembership;
use Illuminate\Console\Command;

class InspectTrustDomainMembership extends Command
{
    protected $signature = 'iicp:membership-status {kind : node or client} {subject : Stable subject identifier}';

    protected $description = 'Inspect one restricted trust-domain membership without exposing credentials or topology';

    public function handle(): int
    {
        $kind = (string) $this->argument('kind');
        $subject = (string) $this->argument('subject');
        if (! in_array($kind, ['node', 'client'], true)) {
            $this->error('subject kind must be node or client');

            return self::FAILURE;
        }

        $membership = TrustDomainMembership::query()
            ->where('domain_id', (string) config('iicp.restricted_domain.domain_id'))
            ->where('subject_kind', $kind)
            ->where('subject_id', $subject)
            ->first();
        if (! $membership) {
            $this->error('No membership matched the requested subject in the configured trust domain.');

            return self::FAILURE;
        }

        $this->line(json_encode([
            'subject_kind' => $membership->subject_kind,
            'subject_ref' => hash('sha256', "iicp-membership-subject-v1\0".$membership->subject_id),
            'status' => $this->status($membership),
            'generation' => $membership->generation,
            'scopes' => $membership->scopes,
            'expires_at' => $membership->expires_at->toIso8601String(),
            'revoked_at' => $membership->revoked_at?->toIso8601String(),
            'has_peer_assertion' => $membership->membership_envelope !== null,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function status(TrustDomainMembership $membership): string
    {
        if ($membership->revoked_at !== null) {
            return 'revoked';
        }
        if ($membership->expires_at->isPast()) {
            return 'expired';
        }
        if ($membership->generation < (int) config('iicp.restricted_domain.membership_epoch', 1)) {
            return 'stale_epoch';
        }

        return 'active';
    }
}

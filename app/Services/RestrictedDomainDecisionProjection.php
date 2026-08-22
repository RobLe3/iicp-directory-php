<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\TrustDomainMembership;

final class RestrictedDomainDecisionProjection
{
    /**
     * @return array<string, int|string>|null
     */
    public function forOperation(string $operation, TrustDomainMembership $membership): ?array
    {
        $projectedOperation = match ($operation) {
            'registration', 'discovery', 'bootstrap', 'consumer_token' => $operation,
            'dispatch' => 'dispatch_ticket',
            default => null,
        };

        if ($projectedOperation === null) {
            return null;
        }

        return [
            'schema' => 'iicp.restricted-trust-domain.directory-decision.v0',
            'profile' => 'urn:iicp:profile:restricted-trust-domain:v1',
            'decision' => 'eligible',
            'operation' => $projectedOperation,
            'domain_id' => (string) config('iicp.restricted_domain.domain_id'),
            'authority_id' => (string) config('iicp.restricted_domain.authority_id'),
            'subject_kind' => $membership->subject_kind,
            'membership_generation' => $membership->generation,
            'membership_expires_at' => $membership->expires_at->getTimestamp(),
        ];
    }
}

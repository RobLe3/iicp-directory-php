<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Support;

class RestrictedDomainConfig
{
    public static function assertValid(): void
    {
        if (! config('iicp.restricted_domain.enabled')) {
            return;
        }

        self::assertRequiredValues();
        self::assertSigningMaterial();
        self::assertFederationDisabled();
    }

    private static function assertSigningMaterial(): void
    {
        $keyId = trim((string) config('iicp.restricted_domain.authority_key_id'));
        $secret = config('app.genesis_ed25519_secret_key');
        if ($keyId === '' || ! is_string($secret) || ! preg_match('/^[0-9a-fA-F]{128}$/', $secret)) {
            throw new \LogicException(
                'Restricted trust-domain mode requires an authority key identifier and a 64-byte Ed25519 signing secret encoded as hex.'
            );
        }
    }

    private static function assertRequiredValues(): void
    {
        $domainId = trim((string) config('iicp.restricted_domain.domain_id'));
        $authorityId = trim((string) config('iicp.restricted_domain.authority_id'));
        $epoch = (int) config('iicp.restricted_domain.membership_epoch');
        $ttl = (int) config('iicp.restricted_domain.max_credential_ttl_seconds');
        if ($domainId === '' || $authorityId === '' || $epoch < 1 || $ttl < 60) {
            throw new \LogicException(
                'Restricted trust-domain mode requires a domain ID, directory authority, positive membership epoch and credential TTL of at least 60 seconds.'
            );
        }
    }

    private static function assertFederationDisabled(): void
    {
        if (config('iicp.replica.enabled')) {
            throw new \LogicException(
                'Restricted trust-domain federation is not implemented; replica mode cannot be combined with restricted-domain mode.'
            );
        }
    }
}

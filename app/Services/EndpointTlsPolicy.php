<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

/**
 * Separates transport reachability from TLS endpoint identity.
 *
 * Production always verifies the certificate chain and requested hostname.
 * The bypass exists only for an explicitly configured non-production testbed.
 */
final class EndpointTlsPolicy
{
    public function allowInsecureTestbed(): bool
    {
        return config('app.env') !== 'production'
            && (bool) config('iicp.registry.dev_allow_insecure_tls', false);
    }
}

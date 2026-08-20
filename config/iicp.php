<?php

// SPDX-License-Identifier: Apache-2.0

return [
    'restricted_domain' => [
        // Additive, disabled-by-default implementation of the pre-normative
        // urn:iicp:profile:restricted-trust-domain:v1 semantics.
        'enabled' => env('IICP_RESTRICTED_DOMAIN_ENABLED', false),
        'domain_id' => env('IICP_TRUST_DOMAIN_ID', ''),
        'authority_id' => env('IICP_DIRECTORY_AUTHORITY_ID', ''),
        'membership_epoch' => (int) env('IICP_MEMBERSHIP_EPOCH', 1),
        'max_credential_ttl_seconds' => (int) env('IICP_MEMBERSHIP_MAX_TTL_SECONDS', 86400),
    ],
    'registry' => [
        'skip_liveness_check' => env('IICP_SKIP_LIVENESS_CHECK', false),
        // Testbed-only escape hatch. Production code rejects this setting and
        // always verifies the certificate chain and requested hostname.
        'dev_allow_insecure_tls' => env('IICP_DEV_ALLOW_INSECURE_TLS', false),
        'max_active_nodes_per_source_ip' => (int) env('IICP_REGISTER_MAX_ACTIVE_NODES_PER_IP', 20),
    ],
    'replica' => [
        'enabled' => env('IICP_REPLICA_MODE', false),
        'seed_url' => env('IICP_SEED_URL', ''),
        'dev_allow_http_did' => env('IICP_DEV_ALLOW_HTTP_DID', false),
        // Historical/unsigned event consumption is limited to explicit
        // non-production testbeds. Production replicas always fail closed.
        'dev_allow_unsigned_events' => env('IICP_DEV_ALLOW_UNSIGNED_EVENTS', false),
        'redirect' => [
            'enabled' => env('IICP_REDIRECT_ENABLED', false),
            'retry_after' => (int) env('IICP_REDIRECT_RETRY_AFTER', 5),
            'urls' => env('IICP_REPLICA_URLS', ''),
            'trust_tier' => env('IICP_REDIRECT_TRUST_TIER', 'low'),
        ],
    ],
];

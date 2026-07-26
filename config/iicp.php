<?php

// SPDX-License-Identifier: Apache-2.0

return [
    'registry' => [
        'skip_liveness_check' => env('IICP_SKIP_LIVENESS_CHECK', false),
        'max_active_nodes_per_source_ip' => (int) env('IICP_REGISTER_MAX_ACTIVE_NODES_PER_IP', 20),
    ],
    'replica' => [
        'enabled' => env('IICP_REPLICA_MODE', false),
        'seed_url' => env('IICP_SEED_URL', ''),
        'dev_allow_http_did' => env('IICP_DEV_ALLOW_HTTP_DID', false),
        'redirect' => [
            'enabled' => env('IICP_REDIRECT_ENABLED', false),
            'retry_after' => (int) env('IICP_REDIRECT_RETRY_AFTER', 5),
            'urls' => env('IICP_REPLICA_URLS', ''),
            'trust_tier' => env('IICP_REDIRECT_TRUST_TIER', 'low'),
        ],
    ],
];

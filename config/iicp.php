<?php

// SPDX-License-Identifier: Apache-2.0

return [
    'registry' => [
        'skip_liveness_check' => env('IICP_SKIP_LIVENESS_CHECK', false),
        'max_active_nodes_per_source_ip' => (int) env('IICP_REGISTER_MAX_ACTIVE_NODES_PER_IP', 20),
    ],
];

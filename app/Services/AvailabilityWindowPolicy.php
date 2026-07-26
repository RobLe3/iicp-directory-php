<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;

/**
 * Evaluates the current share declared by a node's availability windows.
 *
 * This policy does not query, filter, or rank nodes.
 */
class AvailabilityWindowPolicy
{
    public function score(Node $node): float
    {
        $windows = $node->availabilityWindows;

        if ($windows->isEmpty()) {
            return 1.0;
        }

        $nowTime = now()->format('H:i:s');

        foreach ($windows as $window) {
            if ($nowTime >= $window->start_time && $nowTime <= $window->end_time) {
                return (float) $window->share;
            }
        }

        return 0.5;
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Services\LivenessMonitor;
use Illuminate\Console\Command;

class ExpireStaleNodes extends Command
{
    protected $signature = 'iicp:expire-nodes';

    protected $description = 'Mark nodes inactive if no heartbeat received in 90 seconds';

    public function handle(LivenessMonitor $monitor): int
    {
        $count = $monitor->expireStaleNodes();

        if ($count > 0) {
            $this->info("Marked {$count} stale node(s) inactive.");
        }

        return Command::SUCCESS;
    }
}

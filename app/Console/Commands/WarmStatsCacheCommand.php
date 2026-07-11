<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Http\Controllers\StatsController;
use Illuminate\Console\Command;

/**
 * #508 — pre-warm the /v1/stats aggregate every minute.
 *
 * The stats document costs ~1.2s to rebuild (24h percentile aggregation +
 * conformance counts). With only the 60s response cache, the one unlucky
 * request per minute paid that rebuild (REACH DIR-STATS-01 measured 1309ms
 * vs ~190ms cached). The scheduler rebuilds it out-of-band; the 90s TTL
 * overlaps the next tick so user requests always hit a warm cache. The
 * controller's own Cache::remember stays as the cold-start fallback.
 */
class WarmStatsCacheCommand extends Command
{
    protected $signature = 'iicp:warm-stats-cache';

    protected $description = 'Rebuild the /v1/stats cache out-of-band so no user request pays the aggregate rebuild (#508)';

    public function handle(StatsController $stats): int
    {
        $start = microtime(true);
        $ttl = $stats->warmPublicStatsCache();
        $this->info(sprintf(
            'stats.public warmed in %dms (ttl=%ds)',
            (int) ((microtime(true) - $start) * 1000),
            $ttl,
        ));

        return Command::SUCCESS;
    }
}

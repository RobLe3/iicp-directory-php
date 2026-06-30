<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Services\FounderLockinDetector;
use Illuminate\Console\Command;

/**
 * #310 — detect founder lock-ins (spec §5.4). Reserves #1 for the founder and assigns #2..N
 * to genuine external operators in first-appearance order. Runs daily via the scheduler; safe
 * to run by hand. `--dry-run` prints what would be assigned without persisting.
 */
class FounderLockinScanCommand extends Command
{
    protected $signature = 'iicp:founder-lockin-scan {--dry-run : Show assignments without persisting}';

    protected $description = 'Detect founder lock-ins (§5.4) — reserve #1, assign #2..N to genuine operators (#310)';

    public function handle(FounderLockinDetector $detector): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $detector->scan($dryRun);

        $prefix = $dryRun ? '[dry-run] ' : '';
        if ($result['reserved'] !== null) {
            $r = $result['reserved'];
            $this->info(sprintf('%sreserved #1 = %s (%s/%s)', $prefix, $r['display_name'] ?? '?', $r['tier'], $r['badge'] ?? '?'));
        }
        foreach ($result['assigned'] as $a) {
            $this->info(sprintf('%sassigned #%d = %s (%s/%s)', $prefix, $a['ordinal'], $a['display_name'] ?? '?', $a['tier'], $a['badge'] ?? '?'));
        }
        $this->info(sprintf(
            '%sscanned %d candidate(s); %d newly assigned (genesis_ms=%d).',
            $prefix,
            $result['scanned'],
            count($result['assigned']),
            $result['genesis_ms']
        ));

        return self::SUCCESS;
    }
}

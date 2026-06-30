<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Models\CreditTransaction;
use App\Services\CreditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * iicp:expire-credits — the nightly 90-day TTL credit sink.
 *
 * ADR-035 / iicp-billing-extension §11.3. Sweeps idle nodes (no earn within the
 * TTL window) and forfeits their unspent balance, writing an `expire` ledger row
 * per swept node. This is the PRIMARY anti-inflation sink and is always active;
 * the 2% transaction burn is the secondary sink (governance-activated, month 13+).
 *
 * Idempotent: a node swept once is at zero balance, so re-running expires nothing
 * until a fresh earn resets its TTL forward.
 *
 * Scheduled nightly in routes/console.php. Safe to run by hand any time.
 *
 * Usage:
 *   php artisan iicp:expire-credits
 *   php artisan iicp:expire-credits --dry-run
 */
class ExpireCreditsCommand extends Command
{
    protected $signature = 'iicp:expire-credits '
        .'{--dry-run : Report idle nodes that would be swept without zeroing balances}';

    protected $description = 'Sweep idle nodes\' unspent credits past their 90-day TTL (ADR-035 primary sink)';

    public function handle(CreditService $credits): int
    {
        if ($this->option('dry-run')) {
            // Dry-run only counts the idle set; it must not mutate the ledger.
            $idle = CreditTransaction::query()
                ->where('type', 'credit')
                ->whereNotNull('expires_at')
                ->groupBy('node_id')
                ->havingRaw('MAX(expires_at) < ?', [now()])
                ->pluck('node_id')
                ->filter(fn ($nodeId) => $credits->balance($nodeId) > 0.0);

            $this->info("DRY-RUN: {$idle->count()} idle node(s) with positive balance would be swept");

            return self::SUCCESS;
        }

        $result = $credits->expireIdleNodeCredits();

        Log::info('credit TTL expiry sweep', $result);
        $this->info(
            "Expired {$result['expired_nodes']} idle node(s); ".
            "{$result['expired_credits']} credits returned to the sink"
        );

        return self::SUCCESS;
    }
}

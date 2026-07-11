<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PruneTelemetryCommand extends Command
{
    protected $signature = 'iicp:prune-telemetry
        {--probe-days= : Retain raw iicp_telemetry_probes rows for this many days}
        {--aggregate-days= : Retain iicp_telemetry_aggregates rows for this many days}
        {--proxy-days= : Retain proxy_telemetry rows for this many days}
        {--dispatch-days= : Retain anonymous dispatch adoption aggregates for this many days}
        {--batch= : Maximum rows to delete per table per batch}
        {--max-batches= : Maximum batches to delete per table in one invocation}
        {--dry-run : Count eligible rows without deleting}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Prune bounded telemetry tables without touching credits, reputation, node or operator records';

    public const DEFAULT_PROBE_DAYS = 14;

    public const DEFAULT_AGGREGATE_DAYS = 30;

    public const DEFAULT_PROXY_DAYS = 30;

    public const DEFAULT_DISPATCH_DAYS = 30;

    public const DEFAULT_BATCH_SIZE = 1000;

    public const DEFAULT_MAX_BATCHES = 5;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $json = (bool) $this->option('json');
        $batch = $this->positiveIntOption('batch', self::DEFAULT_BATCH_SIZE);
        $maxBatches = $this->positiveIntOption('max-batches', self::DEFAULT_MAX_BATCHES);

        $targets = [
            [
                'name' => 'raw_probe_telemetry',
                'table' => 'iicp_telemetry_probes',
                'column' => 'probed_at',
                'days' => $this->positiveIntOption('probe-days', (int) config('app.iicp_telemetry_retention.probe_days', self::DEFAULT_PROBE_DAYS)),
            ],
            [
                'name' => 'probe_aggregates',
                'table' => 'iicp_telemetry_aggregates',
                'column' => 'computed_at',
                'days' => $this->positiveIntOption('aggregate-days', (int) config('app.iicp_telemetry_retention.aggregate_days', self::DEFAULT_AGGREGATE_DAYS)),
            ],
            [
                'name' => 'proxy_telemetry',
                'table' => 'proxy_telemetry',
                'column' => 'created_at',
                'days' => $this->positiveIntOption('proxy-days', (int) config('app.iicp_telemetry_retention.proxy_days', self::DEFAULT_PROXY_DAYS)),
            ],
            [
                'name' => 'dispatch_usage_aggregates',
                'table' => 'dispatch_usage_daily',
                'column' => 'usage_date',
                'days' => $this->positiveIntOption('dispatch-days', (int) config('app.iicp_telemetry_retention.dispatch_days', self::DEFAULT_DISPATCH_DAYS)),
            ],
        ];

        $results = [];
        foreach ($targets as $target) {
            $results[] = $this->processTarget($target, $batch, $maxBatches, $dryRun);
        }

        $payload = [
            'schema' => 'iicp.db.telemetry_prune.v1',
            'generated_at' => now()->toIso8601String(),
            'dry_run' => $dryRun,
            'batch_size' => $batch,
            'max_batches' => $maxBatches,
            'tables' => $results,
            'safety' => [
                'drops_tables' => false,
                'touches_credits' => false,
                'touches_reputation' => false,
                'touches_nodes_or_operators' => false,
                'prod_backup_required_before_and_after_deploy' => true,
            ],
        ];

        if (! $dryRun) {
            Log::info('iicp telemetry prune completed', [
                'tables' => collect($results)->mapWithKeys(fn ($row) => [$row['table'] => $row['deleted']])->all(),
                'batch_size' => $batch,
                'max_batches' => $maxBatches,
            ]);
        }

        if ($json) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info($dryRun ? 'DRY-RUN telemetry retention summary' : 'Telemetry retention summary');
        foreach ($results as $row) {
            if (! $row['exists']) {
                $this->line("  - {$row['table']}: table missing; skipped");

                continue;
            }
            $this->line(sprintf(
                '  - %s: eligible=%d deleted=%d remaining_eligible=%d cutoff=%s',
                $row['table'],
                $row['eligible_before'],
                $row['deleted'],
                $row['eligible_after'],
                $row['cutoff'],
            ));
        }

        return self::SUCCESS;
    }

    /** @param array{name:string,table:string,column:string,days:int} $target */
    private function processTarget(array $target, int $batch, int $maxBatches, bool $dryRun): array
    {
        if (! Schema::hasTable($target['table'])) {
            return [
                'name' => $target['name'],
                'table' => $target['table'],
                'exists' => false,
                'retention_days' => $target['days'],
                'cutoff' => null,
                'eligible_before' => 0,
                'deleted' => 0,
                'eligible_after' => 0,
            ];
        }

        $cutoff = now()->subDays($target['days'])->utc()->format('Y-m-d H:i:s');
        $eligibleBefore = $this->eligibleQuery($target['table'], $target['column'], $cutoff)->count();
        $deleted = $dryRun ? 0 : $this->deleteInBatches($target['table'], $target['column'], $cutoff, $batch, $maxBatches);
        $eligibleAfter = $dryRun
            ? $eligibleBefore
            : $this->eligibleQuery($target['table'], $target['column'], $cutoff)->count();

        return [
            'name' => $target['name'],
            'table' => $target['table'],
            'exists' => true,
            'retention_days' => $target['days'],
            'cutoff' => $cutoff,
            'eligible_before' => (int) $eligibleBefore,
            'deleted' => (int) $deleted,
            'eligible_after' => (int) $eligibleAfter,
        ];
    }

    private function deleteInBatches(string $table, string $column, string $cutoff, int $batch, int $maxBatches): int
    {
        $deleted = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $ids = $this->eligibleQuery($table, $column, $cutoff)
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table($table)->whereIn('id', $ids->all())->delete();

            if ($ids->count() < $batch) {
                break;
            }
        }

        return $deleted;
    }

    private function eligibleQuery(string $table, string $column, string $cutoff)
    {
        return DB::table($table)
            ->whereNotNull($column)
            ->where($column, '<', $cutoff);
    }

    private function positiveIntOption(string $name, int $default): int
    {
        $raw = $this->option($name);
        if ($raw === null || $raw === '') {
            return max(1, $default);
        }

        return max(1, (int) $raw);
    }
}

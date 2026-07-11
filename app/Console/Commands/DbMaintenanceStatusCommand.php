<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DbMaintenanceStatusCommand extends Command
{
    protected $signature = 'iicp:db-maintenance-status
        {--probe-days= : Raw probe telemetry retention horizon}
        {--aggregate-days= : Probe aggregate retention horizon}
        {--proxy-days= : Proxy telemetry retention horizon}
        {--dispatch-days= : Dispatch adoption aggregate retention horizon}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Report safe DB-maintenance and retention status without exporting row payloads';

    public function handle(): int
    {
        $retention = [
            'probe_days' => $this->positiveIntOption('probe-days', (int) config('app.iicp_telemetry_retention.probe_days', PruneTelemetryCommand::DEFAULT_PROBE_DAYS)),
            'aggregate_days' => $this->positiveIntOption('aggregate-days', (int) config('app.iicp_telemetry_retention.aggregate_days', PruneTelemetryCommand::DEFAULT_AGGREGATE_DAYS)),
            'proxy_days' => $this->positiveIntOption('proxy-days', (int) config('app.iicp_telemetry_retention.proxy_days', PruneTelemetryCommand::DEFAULT_PROXY_DAYS)),
            'dispatch_days' => $this->positiveIntOption('dispatch-days', (int) config('app.iicp_telemetry_retention.dispatch_days', PruneTelemetryCommand::DEFAULT_DISPATCH_DAYS)),
            'heartbeat_event_days' => (int) config('app.iicp_telemetry_retention.heartbeat_event_days', 1),
        ];

        $tables = [
            $this->tableStatus('iicp_telemetry_probes', 'probed_at', $retention['probe_days']),
            $this->tableStatus('iicp_telemetry_aggregates', 'computed_at', $retention['aggregate_days']),
            $this->tableStatus('proxy_telemetry', 'created_at', $retention['proxy_days']),
            $this->tableStatus('dispatch_usage_daily', 'usage_date', $retention['dispatch_days']),
            $this->tableStatus('node_events', null, null),
            $this->tableStatus('node_address_history', null, null),
            $this->tableStatus('credits', null, null),
            $this->tableStatus('credit_transactions', null, null),
            $this->tableStatus('reputations', null, null),
        ];

        $payload = [
            'schema' => 'iicp.db.maintenance_status.v1',
            'generated_at' => now()->toIso8601String(),
            'driver' => DB::getDriverName(),
            'database' => DB::getDatabaseName(),
            'retention' => $retention,
            'tables' => $tables,
            'safety' => [
                'dry_run_only' => true,
                'exports_row_payloads' => false,
                'drops_tables' => false,
                'prod_backup_required_before_and_after_deploy' => true,
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('IICP DB maintenance status (metadata only; no row payloads exported)');
        foreach ($tables as $row) {
            if (! $row['exists']) {
                $this->line("  - {$row['table']}: missing");

                continue;
            }
            $bytes = $row['estimated_bytes'] === null ? 'n/a' : $this->formatBytes((int) $row['estimated_bytes']);
            $eligible = $row['eligible_prune_rows'] === null ? 'n/a' : (string) $row['eligible_prune_rows'];
            $this->line(sprintf(
                '  - %s: rows=%d bytes=%s oldest=%s newest=%s eligible_prune=%s',
                $row['table'],
                $row['rows'],
                $bytes,
                $row['oldest'] ?? 'n/a',
                $row['newest'] ?? 'n/a',
                $eligible,
            ));
        }

        return self::SUCCESS;
    }

    private function tableStatus(string $table, ?string $dateColumn, ?int $retentionDays): array
    {
        if (! Schema::hasTable($table)) {
            return [
                'table' => $table,
                'exists' => false,
                'rows' => 0,
                'estimated_bytes' => null,
                'oldest' => null,
                'newest' => null,
                'retention_days' => $retentionDays,
                'eligible_prune_rows' => null,
            ];
        }

        $oldest = null;
        $newest = null;
        $eligible = null;
        if ($dateColumn !== null && Schema::hasColumn($table, $dateColumn)) {
            $oldest = DB::table($table)->whereNotNull($dateColumn)->min($dateColumn);
            $newest = DB::table($table)->whereNotNull($dateColumn)->max($dateColumn);
            if ($retentionDays !== null) {
                $cutoff = now()->subDays($retentionDays)->utc()->format('Y-m-d H:i:s');
                $eligible = DB::table($table)
                    ->whereNotNull($dateColumn)
                    ->where($dateColumn, '<', $cutoff)
                    ->count();
            }
        }

        return [
            'table' => $table,
            'exists' => true,
            'rows' => (int) DB::table($table)->count(),
            'estimated_bytes' => $this->estimatedBytes($table),
            'oldest' => $oldest,
            'newest' => $newest,
            'retention_days' => $retentionDays,
            'eligible_prune_rows' => $eligible === null ? null : (int) $eligible,
        ];
    }

    private function estimatedBytes(string $table): ?int
    {
        if (DB::getDriverName() !== 'mysql') {
            return null;
        }

        $row = DB::selectOne(
            'select (data_length + index_length) as bytes from information_schema.tables where table_schema = ? and table_name = ?',
            [DB::getDatabaseName(), $table],
        );

        return $row?->bytes === null ? null : (int) $row->bytes;
    }

    private function positiveIntOption(string $name, int $default): int
    {
        $raw = $this->option($name);
        if ($raw === null || $raw === '') {
            return max(1, $default);
        }

        return max(1, (int) $raw);
    }

    private function formatBytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1).' '.$unit;
            }
            $bytes = (int) ($bytes / 1024);
        }

        return $bytes.' B';
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Services\DataSubjectRightsService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class DataSubjectRightsCommand extends Command
{
    protected $signature = 'iicp:dsr
        {action : export|restrict|anonymize}
        {--node-id= : Exact node UUID selector}
        {--endpoint= : Exact node endpoint selector}
        {--operator-pubkey= : Full operator public key selector; never stored in audit logs}
        {--operator-fingerprint= : 12-char public operator fingerprint selector}
        {--tracking-id= : Private case/tracking id; required for mutating actions}
        {--output= : Write export JSON to this path instead of stdout}
        {--dry-run : Preview restrict/anonymize affected counts without mutating}';

    protected $description = 'Run GDPR data-subject export/restrict/anonymize workflows for directory records';

    public function handle(DataSubjectRightsService $dsr): int
    {
        $action = (string) $this->argument('action');
        $selector = [
            'node_id' => $this->option('node-id'),
            'endpoint' => $this->option('endpoint'),
            'operator_pubkey' => $this->option('operator-pubkey'),
            'operator_fingerprint' => $this->option('operator-fingerprint'),
        ];
        $trackingId = (string) ($this->option('tracking-id') ?? '');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = match ($action) {
                'export' => $dsr->export($selector, $trackingId !== '' ? $trackingId : null),
                'restrict' => $dsr->restrict($selector, $trackingId, $dryRun),
                'anonymize' => $dsr->anonymize($selector, $trackingId, $dryRun),
                default => throw new InvalidArgumentException('action must be one of: export, restrict, anonymize'),
            };
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->error('Failed to encode DSR result');

            return self::FAILURE;
        }

        if ($action === 'export' && $this->option('output')) {
            $path = (string) $this->option('output');
            file_put_contents($path, $json."\n");
            $this->info("DSR export written to {$path}");

            return self::SUCCESS;
        }

        $this->line($json);

        return self::SUCCESS;
    }
}

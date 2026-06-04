<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Models\ProbeToken;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class IssueProbeToken extends Command
{
    protected $signature = 'iicp:probe-token
                            {label : Human-readable identifier, e.g. eu-de-cologne-01}
                            {region : Region tag, e.g. EU-DE}
                            {--expires= : Expiry in days from now (omit for never-expiring token)}';

    protected $description = 'Issue a new REACH probe token and print it once (hash stored in DB)';

    public function handle(): int
    {
        $label = $this->argument('label');
        $region = $this->argument('region');
        $expires = $this->option('expires');

        if ($expires !== null && (! is_numeric($expires) || (int) $expires < 1)) {
            $this->error('--expires must be a positive integer (number of days).');

            return Command::FAILURE;
        }

        $raw = Str::random(48);
        $hash = hash('sha256', $raw);
        $expiresAt = $expires !== null ? now()->addDays((int) $expires) : null;

        ProbeToken::create([
            'token_hash' => $hash,
            'label' => $label,
            'region' => $region,
            'expires_at' => $expiresAt,
        ]);

        $this->line('');
        $this->line('<fg=yellow>Probe token issued. Copy it now — it will NOT be shown again.</>');
        $this->line('');
        $this->line("  Label  : {$label}");
        $this->line("  Region : {$region}");
        $this->line('  Expires: '.($expiresAt ? $expiresAt->toDateString() : 'never'));
        $this->line('');
        $this->line("  Token  : {$raw}");
        $this->line('');
        $this->line('<fg=yellow>Add this to reach.toml:</>');
        $this->line('  [daemon]');
        $this->line('  telemetry_enabled = true');
        $this->line('  node_token = "'.$raw.'"');
        $this->line('');

        return Command::SUCCESS;
    }
}

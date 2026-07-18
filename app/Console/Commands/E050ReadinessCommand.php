<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Command;

class E050ReadinessCommand extends Command
{
    protected $signature = 'iicp:e050-readiness {--json : Emit machine-readable aggregate JSON}';

    protected $description = 'Report aggregate strict-E050 readiness without changing registrations';

    public function handle(): int
    {
        $nodes = Node::where('available', true)
            ->where('status', 'active')
            ->where('last_seen', '>=', now()->subSeconds(90))
            ->get(['sdk_version', 'operator_pubkey', 'cx_public_key']);
        $secured = $nodes->filter(fn (Node $node) => filled($node->operator_pubkey) || filled($node->cx_public_key))->count();
        $capable = $nodes->filter(fn (Node $node) => $this->tokenCapable($node->sdk_version))->count();
        $total = $nodes->count();
        $payload = [
            'schema' => 'iicp.e050_readiness.v1',
            'basis' => 'heartbeating_nodes',
            'strict_mode_enabled' => (bool) config('app.iicp_e050_strict_secured', false),
            'token_capability_floor' => '0.7.59',
            'total_heartbeating' => $total,
            'token_capable' => $capable,
            'token_capable_share' => $total === 0 ? 0.0 : round($capable / $total, 6),
            'secured_nodes' => $secured,
            'hypothetical_tokenless_secured_reregistration_rejections' => $secured,
            'content_free' => true,
            'mutates_state' => false,
            'authorizes_cutover' => false,
        ];

        $rendered = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($this->option('json')) {
            $this->line($rendered);
        } else {
            $this->info('Strict E050 readiness (aggregate, read-only)');
            $this->line($rendered);
        }

        return self::SUCCESS;
    }

    private function tokenCapable(?string $version): bool
    {
        if (! $version || ! preg_match('/^v?(\d+\.\d+\.\d+)/', $version, $matches)) {
            return false;
        }

        return version_compare($matches[1], '0.7.59', '>=');
    }
}

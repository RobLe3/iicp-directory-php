<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Models\Node;
use App\Models\TelemetryProbe;
use App\Services\NodeEventLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Directory active per-node reachability probing — #373, Phase B.
 *
 * Probes each registered node's HTTP endpoint and records the result in
 * iicp_telemetry_probes so NodeHealthService::reachabilityScore() can use an
 * independently observed signal instead of the node's self-attested
 * `public_reachable` flag.
 *
 * Gating: requires origin IPv6 egress (VPS migration 2026-06-02). Prior to
 * that, only IPv4-routable nodes can be verified; DS-Lite nodes remain
 * self-attested. The command is safe to run in either environment — it simply
 * skips or fails probes that can't be reached and records the outcome.
 *
 * SSRF guard: endpoints in RFC1918 / loopback / link-local ranges are skipped.
 * Timeout: 5 seconds (long enough for cold-start, short enough to prevent
 * blocking the cron queue). Probe type: 'reachability', test_id: DIR-PROBE-NODE-01.
 */
class ProbeNodesCommand extends Command
{
    protected $signature = 'iicp:probe-nodes {--batch=50 : Maximum nodes to probe per run}';

    protected $description = 'Actively probe registered node endpoints and record reachability (#373 Phase B)';

    /** RFC1918 / loopback / link-local ranges — SSRF guard. */
    private const SKIP_PREFIXES = [
        '10.', '172.16.', '172.17.', '172.18.', '172.19.',
        '172.20.', '172.21.', '172.22.', '172.23.', '172.24.',
        '172.25.', '172.26.', '172.27.', '172.28.', '172.29.',
        '172.30.', '172.31.', '192.168.', '127.', '169.254.',
    ];

    private const TIMEOUT_SECONDS = 5;

    private const RUN_ID_PREFIX = 'dir-probe-';

    private const PROBE_ID = 'dir-node-reachability';

    private const PROBE_TYPE = 'reachability';

    private const TEST_ID = 'DIR-PROBE-NODE-01';

    public function handle(NodeEventLogger $eventLogger): int
    {
        $batch = max(1, min(200, (int) $this->option('batch')));
        $runId = self::RUN_ID_PREFIX.Str::random(8);
        $probedAt = now();

        // Probe the most-recently-active nodes first (they're most likely still online)
        $nodes = Node::where('available', true)
            ->whereNotNull('last_seen')
            ->orderByDesc('last_seen')
            ->limit($batch)
            ->get(['id', 'endpoint', 'public_reachable']);

        $probed = 0;
        $passed = 0;

        foreach ($nodes as $node) {
            [$reachable, $latency] = $this->probe($node->endpoint);
            $probed++;
            if ($reachable) {
                $passed++;
            }

            TelemetryProbe::create([
                'probe_token_id' => null, // directory-internal probe
                'node_id' => $node->id,
                'run_id' => $runId,
                'probe_id' => self::PROBE_ID,
                'probe_type' => self::PROBE_TYPE,
                'test_id' => self::TEST_ID,
                'level' => 'MUST',
                'passed' => $reachable,
                'latency_ms' => $latency,
                'detail' => $reachable ? 'endpoint reachable' : 'endpoint unreachable',
                'metadata' => ['node_id' => $node->id, 'source' => 'directory_active_probe'],
                'probed_at' => $probedAt,
            ]);

            // Update self-attested column only when probe succeeds (never downgrade on
            // a single failure — transient network blips should not penalise the node).
            if ($reachable && ! $node->public_reachable) {
                $node->update(['public_reachable' => true]);
                // #413 — record the false→true transition in the signed event log so
                // discover (re)appearance is auditable, symmetric with REACHABILITY_DEMOTE.
                $eventLogger->log('REACHABILITY_RESTORE', (string) $node->id, [
                    'from' => false,
                    'to' => true,
                    'reason' => 'probe_success',
                    'endpoint' => (string) $node->endpoint,
                    'probe_source' => 'directory_active_probe',
                    'latency_ms' => $latency,
                ]);
            }
        }

        if ($probed > 0) {
            Log::channel('stderr')->info(
                sprintf('iicp:probe-nodes: %d/%d reachable (run %s)', $passed, $probed, $runId)
            );
        }

        return Command::SUCCESS;
    }

    /**
     * Send an HTTP HEAD (falling back to GET on 405) to the node's endpoint.
     * Returns [bool $reachable, int|null $latency_ms].
     *
     * Skips endpoints whose host resolves to a private/loopback range (SSRF guard).
     */
    private function probe(string $endpoint): array
    {
        $host = parse_url($endpoint, PHP_URL_HOST) ?? '';
        if ($this->isPrivateHost($host)) {
            return [false, null];
        }

        $start = microtime(true);
        try {
            $resp = Http::timeout(self::TIMEOUT_SECONDS)
                ->head($endpoint);
            // Fall back to GET if the server doesn't support HEAD
            if ($resp->status() === 405) {
                $resp = Http::timeout(self::TIMEOUT_SECONDS)
                    ->get($endpoint);
            }
            $latency = (int) ((microtime(true) - $start) * 1000);

            return [$resp->successful() || $resp->status() < 500, $latency];
        } catch (\Throwable) {
            return [false, null];
        }
    }

    private function isPrivateHost(string $host): bool
    {
        if ($host === '' || $host === 'localhost') {
            return true;
        }
        // Strip IPv6 brackets
        $host = ltrim(rtrim($host, ']'), '[');
        if ($host === '::1') {
            return true;
        }
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

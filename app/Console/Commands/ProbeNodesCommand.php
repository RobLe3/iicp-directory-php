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
 * Probes each registered node's canonical /iicp/health endpoint and records the result in
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
            ->get(['id', 'endpoint', 'public_reachable', 'endpoint_verified_dead_at']);

        $probed = 0;
        $passed = 0;

        foreach ($nodes as $node) {
            // No-IPv6-egress guard: a shared-hosting origin without IPv6 egress can
            // never reach an IPv6-literal endpoint, so probing it only produces
            // false negatives (655/655 failures observed 2026-06-11). Skip entirely —
            // the node stays self-attested, same as the relay-only guard below.
            if ($this->isIpv6Literal($node->endpoint) && ! config('app.iicp_probe_ipv6_egress')) {
                continue;
            }

            [$reachable, $latency] = $this->probe($node->endpoint);
            $probed++;
            if ($reachable) {
                $passed++;
            }

            // Only record a failed probe for a node that self-attests as public_reachable:
            // a relay-only node (public_reachable=false) cannot be reached from a non-IPv6
            // origin, so a failure is a false negative — don't let it drive reachabilityScore
            // to 0.  Successes are always recorded (they upgrade public_reachable when needed).
            if ($reachable || $node->public_reachable) {
                TelemetryProbe::create([
                    'probe_token_id' => null,
                    'node_id' => $node->id,
                    'run_id' => $runId,
                    'probe_id' => self::PROBE_ID,
                    'probe_type' => self::PROBE_TYPE,
                    'test_id' => self::TEST_ID,
                    'level' => 'MUST',
                    'passed' => $reachable,
                    'latency_ms' => $latency,
                    'detail' => $reachable ? 'health endpoint reachable' : 'health endpoint unreachable',
                    'metadata' => ['node_id' => $node->id, 'source' => 'directory_active_probe', 'path' => '/iicp/health'],
                    'probed_at' => $probedAt,
                ]);
            }

            // Update self-attested column only when probe succeeds (never downgrade on
            // a single failure — transient network blips should not penalise the node).
            if ($reachable && (! $node->public_reachable || $node->endpoint_verified_dead_at !== null)) {
                $wasPublicReachable = (bool) $node->public_reachable;
                $hadDeadEndpointFlag = $node->endpoint_verified_dead_at !== null;
                $node->update([
                    'public_reachable' => true,
                    'endpoint_verified_dead_at' => null,
                ]);
                // #413 — record the false→true transition in the signed event log so
                // discover (re)appearance is auditable, symmetric with REACHABILITY_DEMOTE.
                $eventLogger->log('REACHABILITY_RESTORE', (string) $node->id, [
                    'from' => $wasPublicReachable,
                    'to' => true,
                    'reason' => 'probe_success',
                    'endpoint' => (string) $node->endpoint,
                    'probe_source' => 'directory_active_probe',
                    'latency_ms' => $latency,
                    'endpoint_verified_dead_at_cleared' => $hadDeadEndpointFlag,
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
     * Send an HTTP GET to the node's canonical /iicp/health endpoint.
     * Returns [bool $reachable, int|null $latency_ms].
     *
     * Skips endpoints whose host resolves to a private/loopback range (SSRF guard).
     */
    private function probe(string $endpoint): array
    {
        $healthUrl = $this->healthUrl($endpoint);
        $host = parse_url($healthUrl, PHP_URL_HOST) ?? '';
        if ($this->isPrivateHost($host)) {
            return [false, null];
        }

        $start = microtime(true);
        try {
            $resp = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->get($healthUrl);
            $latency = (int) ((microtime(true) - $start) * 1000);

            return [$this->healthResponsePasses($resp), $latency];
        } catch (\Throwable) {
            return [false, null];
        }
    }

    private function healthUrl(string $endpoint): string
    {
        return rtrim($endpoint, '/').'/iicp/health';
    }

    private function healthResponsePasses($resp): bool
    {
        if (! $resp->successful()) {
            return false;
        }

        $body = trim((string) $resp->body());
        if ($body === '' || strtolower($body) === 'ok') {
            return true;
        }

        $json = json_decode($body, true);
        if (! is_array($json)) {
            return false;
        }

        $status = strtolower((string) ($json['status'] ?? 'ok'));
        if (! in_array($status, ['ok', 'available', 'healthy'], true)) {
            return false;
        }

        return ! array_key_exists('available', $json) || is_bool($json['available']);
    }

    /** True when the endpoint host is a bracketed IPv6 literal (e.g. http://[2a0a:…]:9484). */
    private function isIpv6Literal(string $endpoint): bool
    {
        $host = parse_url($endpoint, PHP_URL_HOST) ?? '';

        return str_starts_with($host, '[') || substr_count($host, ':') >= 2;
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

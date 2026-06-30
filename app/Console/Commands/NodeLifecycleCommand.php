<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Models\Node;
use App\Services\NodeEventLogger;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class NodeLifecycleCommand extends Command
{
    protected $signature = 'iicp:node-lifecycle';

    protected $description = 'Advance dormant nodes to archived, purge expired archived nodes, and re-verify public reachability';

    private const DORMANT_TO_ARCHIVED_DAYS = 90;

    private const ARCHIVED_TO_PURGE_DAYS = 365;

    // #325 Layer 3 — re-verify cadence + probe budget
    private const REACHABILITY_PROBE_TIMEOUT_S = 3;

    // #493 — ADR-047 Part A: a node that has answered the HMAC liveness challenge
    // within this window is cryptographically confirmed live. Skip the TCP probe so
    // the directory's egress limitations don't incorrectly demote publicly-reachable
    // nodes (e.g. nodes behind a firewall the directory host can't reach outbound).
    private const LIVENESS_VERIFIED_WINDOW_S = 300;

    public function handle(NodeEventLogger $eventLogger): int
    {
        $archived = $this->archiveDormant();
        $purged = $this->purgeArchived();
        $demoted = $this->verifyReachability($eventLogger);

        if ($archived > 0) {
            $this->info("Archived {$archived} dormant node(s) (>90 days offline).");
        }
        if ($purged > 0) {
            $this->info("Purged {$purged} archived node(s) (>365 days offline).");
        }
        if ($demoted > 0) {
            $this->info("Flagged {$demoted} node endpoint(s) dead after failed re-probe.");
        }

        return Command::SUCCESS;
    }

    /**
     * #325 Layer 3 — periodic re-verification of public reachability.
     *
     * For each fresh active node with a routable listed endpoint:
     *  - Direct nodes are probed when public_reachable=true.
     *  - Public HTTPS tunnel/relay endpoints are also probed even when
     *    public_reachable=false, because HMAC liveness only proves the process is
     *    alive — it does not prove the advertised endpoint is usable.
     *  - Other NATted nodes are skipped until transport-aware probes exist.
     *  - For NOT-NATted nodes: HEAD /iicp/health with a 3s timeout. On failure
     *    (connect refused, timeout, non-2xx) confirmed by a second probe, demote
     *    public_reachable=false. The node stays in the DB but is filtered out of
     *    default discover — operator can debug via ?include_internal=true.
     *
     * #413 — every demote emits a signed REACHABILITY_DEMOTE event to node_events
     * so the transition (the cause of a node "vanishing" from discover) is visible
     * in the public, federatable audit log instead of only in cron stderr.
     *
     * #413 (demote/promote-conservatism parity) — ProbeNodesCommand "never downgrades
     * on a single failure"; mirror that here with a confirm-probe so a one-off
     * directory-side network blip doesn't hide a healthy node for 24h.
     *
     * Skipped entirely in non-production environments — the dev/local dev loop
     * doesn't have routable endpoints and we don't want to flap public_reachable
     * on every nightly run.
     */
    private function verifyReachability(NodeEventLogger $eventLogger): int
    {
        if (config('app.env') !== 'production') {
            return 0;
        }

        // Only re-check fresh active nodes; dormant/stale rows are handled by
        // archive + NodeLifecycle expiry separately.
        $candidates = Node::query()
            ->where('available', true)
            ->where('status', 'active')
            ->where('last_seen', '>=', Carbon::now()->subSeconds(120))
            ->where(function ($q) {
                $q->where('public_reachable', true)
                    ->orWhere(function ($tunnel) {
                        $tunnel->where('endpoint', 'like', 'https://%')
                            ->whereIn('transport_method', ['external_tunnel', 'turn_relay']);
                    })
                    ->orWhereNotNull('endpoint_verified_dead_at');
            })
            ->get(['id', 'endpoint', 'transport_method', 'public_reachable', 'liveness_verified_at', 'endpoint_verified_dead_at']);

        $livenessWindow = Carbon::now()->subSeconds(self::LIVENESS_VERIFIED_WINDOW_S);

        $demoted = 0;
        foreach ($candidates as $node) {
            if (! $this->shouldProbeEndpoint($node)) {
                continue;
            }
            // A production origin without IPv6 egress cannot prove an IPv6-literal
            // direct endpoint dead.  If the node is cryptographically live, clear a
            // stale dead flag and keep the route as self-attested/unverified instead
            // of hiding a working operator node from discover.
            if ($this->isIpv6LiteralEndpoint((string) $node->endpoint)
                && ! config('app.iicp_probe_ipv6_egress')
            ) {
                if ($node->endpoint_verified_dead_at !== null
                    && $node->liveness_verified_at !== null
                    && $node->liveness_verified_at >= $livenessWindow
                ) {
                    Node::where('id', $node->id)->update([
                        'endpoint_verified_dead_at' => null,
                    ]);
                    $eventLogger->log('REACHABILITY_RESTORE', (string) $node->id, [
                        'from' => (bool) $node->public_reachable,
                        'to' => (bool) $node->public_reachable,
                        'reason' => 'liveness_verified_ipv6_probe_unavailable',
                        'endpoint' => (string) $node->endpoint,
                        'transport_method' => $node->transport_method ?: 'direct',
                        'probe_source' => 'node_lifecycle',
                        'endpoint_verified_dead_at_cleared' => true,
                        'directory_ipv6_egress' => false,
                    ]);
                }

                continue;
            }
            // #493 — ADR-047 Part A: skip TCP probe if the node answered the HMAC
            // liveness challenge recently. Cryptographic proof of liveness is stronger
            // than TCP dial-back and works when the directory's egress can't reach the node.
            // #536 exception: public HTTPS tunnel/relay URLs must still be endpoint-
            // probed; HMAC liveness can succeed while a Quick Tunnel URL is dead.
            if (! $this->isPublicHttpsTunnelEndpoint($node)
                && $node->liveness_verified_at !== null
                && $node->liveness_verified_at >= $livenessWindow
            ) {
                continue;
            }
            [$ok, $reason] = $this->probeReachable((string) $node->endpoint);
            if ($ok) {
                if ($node->endpoint_verified_dead_at !== null) {
                    Node::where('id', $node->id)->update([
                        'public_reachable' => true,
                        'endpoint_verified_dead_at' => null,
                    ]);
                    $eventLogger->log('REACHABILITY_RESTORE', (string) $node->id, [
                        'from' => false,
                        'to' => true,
                        'reason' => 'probe_success',
                        'endpoint' => (string) $node->endpoint,
                        'transport_method' => $node->transport_method ?: 'direct',
                        'probe_source' => 'node_lifecycle',
                        'endpoint_verified_dead_at_cleared' => true,
                    ]);
                }

                continue;
            }
            // Confirm-probe — only demote on two consecutive failures (parity with
            // ProbeNodesCommand's "never downgrade on a single failure").
            [$okRetry, $reasonRetry] = $this->probeReachable((string) $node->endpoint);
            if ($okRetry) {
                continue;
            }

            Node::where('id', $node->id)->update([
                'public_reachable' => false,
                'endpoint_verified_dead_at' => Carbon::now(),
            ]);
            $eventLogger->log('REACHABILITY_DEMOTE', (string) $node->id, [
                'from' => true,
                'to' => false,
                'reason' => $reasonRetry,
                'endpoint' => (string) $node->endpoint,
                'transport_method' => $node->transport_method ?: 'direct',
                'probe_source' => 'node_lifecycle',
                'endpoint_verified_dead_at_set' => true,
            ]);
            $demoted++;
        }

        return $demoted;
    }

    /**
     * @return array{0: bool, 1: string} [reachable, reason]
     *                                   reason ∈ {probe_success, probe_non_2xx, probe_connect_failed}
     */
    private function probeReachable(string $endpoint): array
    {
        try {
            $resp = Http::timeout(self::REACHABILITY_PROBE_TIMEOUT_S)
                ->withoutVerifying()
                ->head(rtrim($endpoint, '/').'/iicp/health');

            return [$resp->successful(), $resp->successful() ? 'probe_success' : 'probe_non_2xx'];
        } catch (ConnectionException) {
            return [false, 'probe_connect_failed'];
        }
    }

    private function shouldProbeEndpoint(Node $node): bool
    {
        if (! $this->isSafeProbeTarget((string) $node->endpoint)) {
            return false;
        }

        if ($node->endpoint_verified_dead_at !== null) {
            return true;
        }

        if ($this->isPublicHttpsTunnelEndpoint($node)) {
            return true;
        }

        // Skip NATted — directory can't dial back through NAT unless it is a
        // public HTTPS tunnel/relay URL covered above.
        return empty($node->transport_method) || $node->transport_method === 'direct';
    }

    private function isPublicHttpsTunnelEndpoint(Node $node): bool
    {
        return str_starts_with((string) $node->endpoint, 'https://')
            && in_array($node->transport_method, ['external_tunnel', 'turn_relay'], true);
    }

    private function isIpv6LiteralEndpoint(string $endpoint): bool
    {
        $host = parse_url($endpoint, PHP_URL_HOST) ?? '';

        return str_contains($host, ':') || str_starts_with($host, '[');
    }

    private function isSafeProbeTarget(string $endpoint): bool
    {
        $host = parse_url($endpoint, PHP_URL_HOST) ?? '';
        if ($host === '' || $host === 'localhost') {
            return false;
        }

        $host = ltrim(rtrim($host, ']'), '[');
        if ($host === '::1') {
            return false;
        }

        foreach (['10.', '172.16.', '172.17.', '172.18.', '172.19.', '172.20.', '172.21.', '172.22.', '172.23.', '172.24.', '172.25.', '172.26.', '172.27.', '172.28.', '172.29.', '172.30.', '172.31.', '192.168.', '127.', '169.254.'] as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function archiveDormant(): int
    {
        $cutoff = Carbon::now()->subDays(self::DORMANT_TO_ARCHIVED_DAYS);

        return Node::query()
            ->where('status', 'dormant')
            ->where('dormant_since', '<', $cutoff)
            ->update(['status' => 'archived']);
    }

    private function purgeArchived(): int
    {
        $cutoff = Carbon::now()->subDays(self::ARCHIVED_TO_PURGE_DAYS);

        $nodes = Node::query()
            ->where('status', 'archived')
            ->where('dormant_since', '<', $cutoff)
            ->get(['id', 'identity_key', 'dormant_since', 'lifetime_jobs']);

        if ($nodes->isEmpty()) {
            return 0;
        }

        $purged = 0;

        foreach ($nodes as $node) {
            DB::transaction(function () use ($node, &$purged) {
                // Copy reputation to archive before hard-delete (RESTRICT FK prevents delete without this)
                $rep = DB::table('reputations')->where('node_id', $node->id)->first();

                if ($rep) {
                    DB::table('reputation_archive')->updateOrInsert(
                        ['node_id' => $node->id],
                        [
                            'node_id' => $node->id,
                            'identity_key' => $node->identity_key ?? hash('sha256', $node->id),
                            'score' => $rep->score,
                            'tasks_total' => $rep->tasks_total,
                            'tasks_failed' => $rep->tasks_failed,
                            'completed_tasks_count' => $rep->completed_tasks_count ?? 0,
                            'lifetime_jobs' => $node->lifetime_jobs ?? 0,
                            'avg_latency_ms' => $rep->avg_latency_ms,
                            'dormant_since' => $node->dormant_since,
                            'archived_at' => now(),
                            'expires_at' => now()->addDays(self::ARCHIVED_TO_PURGE_DAYS),
                        ]
                    );

                    // Remove reputation row — required before node hard-delete (RESTRICT FK)
                    DB::table('reputations')->where('node_id', $node->id)->delete();
                }

                // Hard-delete — cascades to capabilities, availability_windows,
                // credits, credit_transactions, node_address_history
                $node->delete();
                $purged++;
            });
        }

        return $purged;
    }
}

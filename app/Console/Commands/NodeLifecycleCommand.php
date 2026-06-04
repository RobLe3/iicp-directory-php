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
            $this->info("Demoted {$demoted} node(s) to public_reachable=false after failed re-probe.");
        }

        return Command::SUCCESS;
    }

    /**
     * #325 Layer 3 — periodic re-verification of public reachability.
     *
     * For each active node that's currently marked public_reachable=true:
     *  - Skip NATted nodes (transport_method set + non-'direct'): the directory
     *    cannot dial back through NAT; Phase 6+ work will add transport-aware
     *    dial-back via the declared traversal method. Until then, NATted nodes
     *    trust the operator's claim.
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
            ->where('public_reachable', true)
            ->where('last_seen', '>=', Carbon::now()->subSeconds(120))
            ->get(['id', 'endpoint', 'transport_method']);

        $demoted = 0;
        foreach ($candidates as $node) {
            // Skip NATted — directory can't dial back through NAT
            if (! empty($node->transport_method) && $node->transport_method !== 'direct') {
                continue;
            }
            [$ok, $reason] = $this->probeReachable((string) $node->endpoint);
            if ($ok) {
                continue;
            }
            // Confirm-probe — only demote on two consecutive failures (parity with
            // ProbeNodesCommand's "never downgrade on a single failure").
            [$okRetry, $reasonRetry] = $this->probeReachable((string) $node->endpoint);
            if ($okRetry) {
                continue;
            }

            Node::where('id', $node->id)->update(['public_reachable' => false]);
            $eventLogger->log('REACHABILITY_DEMOTE', (string) $node->id, [
                'from' => true,
                'to' => false,
                'reason' => $reasonRetry,
                'endpoint' => (string) $node->endpoint,
                'transport_method' => $node->transport_method ?: 'direct',
                'probe_source' => 'node_lifecycle',
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

<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Observed-IP recording and address change detection (DIR-ADDR-01..DIR-ADDR-06).
 *
 * WHY track observed IP at all: IICP nodes register a public_endpoint that operators
 * manually configure. CGNAT, Cloudflare Tunnel, and VPN setups frequently mean the
 * registered endpoint doesn't match the IP the directory actually sees. Tracking the
 * observed source IP lets operators detect misconfigured endpoints before they cause
 * routing failures.
 *
 * WHY CF-Connecting-IP takes priority over X-Forwarded-For: Cloudflare strips and
 * re-writes CF-Connecting-IP with the real client IP before it reaches the origin.
 * X-Forwarded-For can be spoofed by the client; CF-Connecting-IP cannot (Cloudflare
 * controls it). When behind Cloudflare, CF-Connecting-IP is always authoritative.
 *
 * WHY take the leftmost value of X-Forwarded-For: RFC 7239 §5.2 specifies that the
 * leftmost address is the original client; rightmost is the nearest proxy. Reverse-proxy
 * chains append to the right, so leftmost = the actual client IP in typical deployments.
 *
 * Spec: spec/iicp-dir.md §observed-ip (DIR-ADDR-01..06). ADR: ADR-003 (source of truth).
 */
class NodeAddressObserver
{
    public function __construct(private ?NodeEventLogger $eventLogger = null) {}

    /**
     * Extract the client IP from the request, honouring Cloudflare and
     * standard proxy headers per DIR-ADDR-05.
     */
    public function getObservedIp(Request $request): string
    {
        // Cloudflare sets this header authoritatively when proxying
        $cfIp = $request->header('CF-Connecting-IP');
        if ($cfIp && filter_var(trim($cfIp), FILTER_VALIDATE_IP)) {
            return trim($cfIp);
        }

        // Standard proxy header — take the first (leftmost) value
        $forwarded = $request->header('X-Forwarded-For');
        if ($forwarded) {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        return $request->ip() ?? '0.0.0.0';
    }

    /**
     * Record an address observation and update the node's current IP per
     * DIR-ADDR-01, DIR-ADDR-03, and DIR-ADDR-06.
     */
    public function observe(Node $node, string $ip, string $requestType = 'other'): void
    {
        if (! in_array($requestType, ['register', 'heartbeat', 'other'], true)) {
            $requestType = 'other';
        }

        DB::transaction(function () use ($node, $ip, $requestType) {
            // Update current observed IP on node (DIR-ADDR-03)
            $node->observed_source_ip = $ip;
            $node->save();

            // W-042 / db-D2prime: compare-then-insert. Only persist a new
            // history row when the IP actually changed (or this is the first
            // observation for this node). Prior behaviour inserted one row
            // per heartbeat → 156k rows at 8 nodes; new behaviour produces
            // ~1 row per (node, distinct IP). Diagnostic value preserved;
            // per-heartbeat noise eliminated.
            $latestIp = DB::table('node_address_history')
                ->where('node_id', $node->id)
                ->orderByDesc('id')
                ->value('ip_address');

            if ($latestIp !== $ip) {
                DB::table('node_address_history')->insert([
                    'node_id' => $node->id,
                    'ip_address' => $ip,
                    'request_type' => $requestType,
                    'observed_at' => now(),
                ]);

                // Phase A.1 / blend §2.2 (issue 5.3 + 5.8): bounded retention.
                // Keep at most N=5 most-recent distinct IPs per node AND prune
                // rows older than 30 days. Privacy minimisation + bounded growth.
                // Run inline after each new-IP insert (the only time the table
                // grows); avoids a separate cron + race conditions.
                self::pruneAddressHistory($node->id);
            }
        });

        Log::info('IICP address observed', [
            'node_id' => $node->id,
            'ip' => $ip,
            'request_type' => $requestType,
        ]);

        // Soft region-mismatch flag (#303): private IPs are unroutable from the public
        // internet. A node claiming a production region from a private IP is likely a
        // dev/NAT setup. Log a structured warning so operators and trust auditors can act.
        // P6-2.2b: also emit OPERATOR_OBSERVED to federated event log so replicas
        // mirror the audit signal (W-033 audit-trail extension).
        if ($this->isPrivateIp($ip) && ! $this->isLocalRegion($node->region ?? '')) {
            Log::warning('IICP region-ip mismatch: private IP with production region', [
                'node_id' => $node->id,
                'observed_ip' => $ip,
                'region' => $node->region,
                'request_type' => $requestType,
                'trust_flag' => 'IICP-SEC-GEO-01',
            ]);

            if ($this->eventLogger) {
                try {
                    $this->eventLogger->log('OPERATOR_OBSERVED', $node->id, [
                        'observation_type' => 'private_ip_public_region',
                        'observed_at' => now()->toIso8601String(),
                        'evidence' => [
                            'claimed' => $node->region,
                            'observed' => 'private_ip',
                            'source' => 'NodeAddressObserver',
                            'rule_id' => 'IICP-SEC-GEO-01',
                        ],
                        'severity' => 'medium',
                    ]);
                } catch (\Throwable $e) {
                    // Audit-trail emission must NEVER break the observe path —
                    // a missed OPERATOR_OBSERVED is a missing-log issue, not a
                    // node-registration failure. Log and continue.
                    Log::warning('OPERATOR_OBSERVED emission failed', [
                        'node_id' => $node->id, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Phase A.1 retention policy (blend §2.2; issues 5.3 + 5.8):
     * keep at most N most-recent rows per node AND drop rows older than D days.
     *
     * Whichever bound triggers first wins. Static so it's cron-callable too.
     */
    public const ADDRESS_HISTORY_MAX_ROWS = 5;

    public const ADDRESS_HISTORY_MAX_AGE_DAYS = 30;

    public static function pruneAddressHistory(string $nodeId): int
    {
        // Age cap: drop rows older than 30 days.
        $ageDeleted = DB::table('node_address_history')
            ->where('node_id', $nodeId)
            ->where('observed_at', '<', now()->subDays(self::ADDRESS_HISTORY_MAX_AGE_DAYS))
            ->delete();

        // Count cap: if more than N remain, drop the oldest.
        $rowCount = DB::table('node_address_history')
            ->where('node_id', $nodeId)
            ->count();
        $countDeleted = 0;
        if ($rowCount > self::ADDRESS_HISTORY_MAX_ROWS) {
            $excess = $rowCount - self::ADDRESS_HISTORY_MAX_ROWS;
            // Find the IDs of the oldest $excess rows; delete those.
            $oldIds = DB::table('node_address_history')
                ->where('node_id', $nodeId)
                ->orderBy('observed_at', 'asc')
                ->limit($excess)
                ->pluck('id')
                ->toArray();
            if (! empty($oldIds)) {
                $countDeleted = DB::table('node_address_history')
                    ->whereIn('id', $oldIds)
                    ->delete();
            }
        }

        return $ageDeleted + $countDeleted;
    }

    /**
     * Returns true if the IP is in a private, loopback, or link-local range
     * (RFC 1918 / RFC 4193 / RFC 5735). Public IPs return false. (#303)
     */
    public function isPrivateIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Returns true if the declared region clearly signals a local/test deployment,
     * so we suppress spurious IICP-SEC-GEO-01 warnings for those nodes. (#303)
     */
    public function isLocalRegion(string $region): bool
    {
        $lower = strtolower($region);
        foreach (['local', 'dev', 'test', 'localhost', 'loopback', 'lan', 'private'] as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prune address history older than 30 days per DIR-ADDR-06.
     * Called from a scheduled artisan command.
     */
    public function pruneHistory(): int
    {
        return DB::table('node_address_history')
            ->where('observed_at', '<', now()->subDays(30))
            ->delete();
    }
}

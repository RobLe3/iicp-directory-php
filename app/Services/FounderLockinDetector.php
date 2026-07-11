<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;
use App\Models\Operator;
use Illuminate\Support\Facades\DB;

/**
 * #310 founder lock-in detector (spec iicp-recognition §5.4). Scans operators and assigns
 * the immutable founder ordinal/tier/badge to those who qualify — the side-effecting
 * counterpart to the pure {@see FounderRecognition} algorithm.
 *
 * Two phases:
 *   1. **Reserve #1** for the founder/maintainer, resolved by the configured founder
 *      `operator_pubkey` (`app.iicp_founder_one_pubkey`) — the cryptographic identity is the
 *      unique key (operator_contact is not sent to the directory). Assigned directly (founder
 *      privilege, maintainer directive 2026-06-06 — not subject to the 30-day gate).
 *   2. **Assign #2..N** to genuine external operators, in **first-appearance order**
 *      (`first_seen_ms`). "Genuine" is the unforgeable proof, all required: the operator has
 *      ≥1 node that is active + available + `public_reachable` (#326 — rules out localhost /
 *      docker / internal) + `operator_verified` (ADR-045 delegation-bound), and is NOT the
 *      founder. The 30-day-healthy window is enforced inside {@see FounderRecognition::decideLockin}.
 *
 * Idempotent: operators with a non-null ordinal are immutable and skipped. Safe to run daily.
 *
 * Privacy: `operator_pubkey` is used only to join operator↔node; it is never returned or logged.
 * The leaderboard serves display_name + ordinal + tier + badge only.
 *
 * NOTE (tracked follow-up #310 anti-spoof anchor): the signed `FOUNDER_LOCKIN` audit event
 * (#458 hash-chain) is intentionally NOT emitted onto the federated `node_events` stream —
 * `GET /v1/events` returns all types by default, so emitting it there would violate the
 * DIR-FED-16 closed federation list. It must ride a dedicated signed chain; deferred until
 * before the first external #2 can mint (~2026-07-06, given today's genesis + 30-day gate).
 */
class FounderLockinDetector
{
    /**
     * Run a detection pass. Returns a summary array (counts + assignments) for the command
     * to print. When $dryRun is true, computes assignments without persisting.
     *
     * @return array{genesis_ms:int, reserved:?array, assigned:array, scanned:int, dry_run:bool}
     */
    public function scan(bool $dryRun = false): array
    {
        $genesisMs = (int) config('app.iicp_genesis_ms');
        $founderOnePubkey = (string) config('app.iicp_founder_one_pubkey');
        $nowMs = (int) (microtime(true) * 1000);

        $reserved = $this->reserveFounderOne($founderOnePubkey, $nowMs, $genesisMs, $dryRun);

        $assigned = [];
        $candidates = Operator::query()
            ->where('identity_status', Operator::IDENTITY_ACTIVE)
            ->whereNull('ordinal')
            ->whereNotNull('first_seen_ms')
            ->orderBy('first_seen_ms')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $op) {
            if ($op->operator_pubkey === $founderOnePubkey) {
                continue; // the founder (#1) is handled in reserveFounderOne
            }
            if (! $this->hasGenuineServedNode($op->operator_pubkey)) {
                continue;
            }

            $priorLockinCount = Operator::where('identity_status', Operator::IDENTITY_ACTIVE)->whereNotNull('ordinal')->count();
            $decision = FounderRecognition::decideLockin(
                (int) $op->first_seen_ms,
                $nowMs,
                true, // genuine served-node presence is the directory-observed health signal
                $priorLockinCount,
                $genesisMs
            );
            if ($decision === null) {
                continue; // not yet 30 days, or past the final founder window
            }

            if (! $dryRun) {
                $this->persist($op, $decision, 'genuine-served-node');
            }
            $assigned[] = [
                'display_name' => $op->display_name,
                'ordinal' => $decision['ordinal'],
                'tier' => $decision['tier'],
                'badge' => $decision['badge'],
            ];
        }

        return [
            'genesis_ms' => $genesisMs,
            'reserved' => $reserved,
            'assigned' => $assigned,
            'scanned' => $candidates->count(),
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Reserve ordinal #1 for the founder by operator_pubkey (idempotent). Returns the assignment,
     * or null if the founder operator is unknown (not yet registered) or already holds an ordinal.
     * Founder privilege: #1 is minted directly at $nowMs, bypassing the 30-day gate (maintainer
     * directive 2026-06-06).
     *
     * @return ?array{display_name:?string, ordinal:int, tier:string, badge:?string}
     */
    private function reserveFounderOne(string $founderOnePubkey, int $nowMs, int $genesisMs, bool $dryRun): ?array
    {
        if ($founderOnePubkey === '') {
            return null;
        }
        $dev = Operator::where('operator_pubkey', $founderOnePubkey)
            ->where('identity_status', Operator::IDENTITY_ACTIVE)
            ->first();
        if ($dev === null || $dev->ordinal !== null) {
            return null;
        }

        $decision = [
            'ordinal' => 1,
            'tier' => FounderRecognition::founderTier(1, $nowMs, $genesisMs) ?? 'genesis_50',
            'badge' => FounderRecognition::founderBadge(1, $nowMs, $genesisMs),
            'locked_at_ms' => $nowMs,
        ];

        if (! $dryRun) {
            $this->persist($dev, $decision, 'founder-reserved');
        }

        return [
            'display_name' => $dev->display_name,
            'ordinal' => 1,
            'tier' => $decision['tier'],
            'badge' => $decision['badge'],
        ];
    }

    /**
     * True iff the operator has ≥1 node proving genuine external participation:
     * public_reachable (#326) + operator_verified (ADR-045) + served within the trailing
     * 24 hours (last_seen within the scan's daily window, OR currently active+available).
     *
     * WHY a 24h window and not "active at this instant": the scan runs once daily at a
     * fixed time (04:15). A self-hosted node that sleeps/reboots at that hour (Windows
     * updates, nightly suspend) would never be observed active at the scan instant despite
     * genuinely serving the mesh all day — penalizing exactly the home-hardware operators
     * the founder program targets. A node seen in the last 24h provably served during the
     * current scan window; the unforgeability is unchanged (last_seen is set only by
     * authenticated heartbeats, and public_reachable + operator_verified still must hold).
     */
    public function hasGenuineServedNode(?string $operatorPubkey): bool
    {
        if ($operatorPubkey === null) {
            return false;
        }

        return Node::query()
            ->where('operator_pubkey', $operatorPubkey)
            ->where('public_reachable', true)
            ->where('operator_verified', true)
            ->where(function ($q) {
                $q->where(function ($active) {
                    $active->where('status', 'active')->where('available', true);
                })->orWhere('last_seen', '>=', now()->subDay());
            })
            ->exists();
    }

    /** Persist the immutable recognition fields onto the operator row under a guarded update. */
    private function persist(Operator $op, array $decision, string $source): void
    {
        DB::table('operators')
            ->where('id', $op->id)
            ->whereNull('ordinal') // guard: never overwrite an already-minted (immutable) slot
            ->update([
                'ordinal' => $decision['ordinal'],
                'tier' => $decision['tier'],
                'badge' => $decision['badge'],
                'provenance' => json_encode([
                    'source' => $source,
                    'locked_at_ms' => $decision['locked_at_ms'],
                    'detector' => 'iicp:founder-lockin-scan',
                ]),
                'updated_at' => now(),
            ]);
    }
}

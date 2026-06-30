<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\Operator;
use App\Services\FounderLockinDetector;
use App\Services\FounderRecognition;
use Illuminate\Http\JsonResponse;

/**
 * #310/#463 — public recognition leaderboards (spec iicp-recognition §6/§7). Anonymous-read,
 * cacheable. Reads the operator-keyed `operators` record and serves only the PUBLIC handle
 * (display_name) + recognition state (ordinal/tier/badge) — the operator_pubkey is
 * directory-private and is NEVER included (the Operator model also hides it).
 *
 * First board: `founders` — operators that hold a founder ordinal (§5.4), ordered by that
 * ordinal (lower = earlier lock-in), plus a `pending` section: provisional operators serving
 * the mesh whose 30-day lock-in clock is still running (spec §5.4.2 provisional state made
 * visible — recognition before lock-in, and a public race for the next low ordinal).
 * Boards that need the §5 composite rank_score (living_mesh_lords, rising_stars,
 * most_reliable) are deferred until rank_score is computed — this endpoint does not
 * fabricate them.
 */
class LeaderboardController extends Controller
{
    /** Max rows returned for any board (spec leaderboards are top-N views). */
    private const MAX_ENTRIES = 100;

    /** Max provisional rows appended to the founders board. */
    private const MAX_PENDING = 25;

    public function __construct(private FounderLockinDetector $detector) {}

    public function show(string $boardId): JsonResponse
    {
        if ($boardId !== 'founders') {
            return response()->json([
                'error' => [
                    'code' => 'IICP-E050',
                    'message' => 'unknown or not-yet-computed leaderboard',
                ],
            ], 404);
        }

        // Founders = operators with an assigned ordinal (§5.4), best (lowest) first.
        // Explicit column projection — operator_pubkey is never selected or served.
        // (Visibility opt-out per §6 lands with the visibility column/endpoint; no operator
        // can opt out yet, so the exclusion is vacuously satisfied today.)
        $rows = Operator::whereNotNull('ordinal')
            ->orderBy('ordinal')
            ->limit(self::MAX_ENTRIES)
            ->get(['display_name', 'ordinal', 'tier', 'badge']);

        $entries = [];
        $place = 1;
        foreach ($rows as $op) {
            $entries[] = [
                'place' => $place++,
                'display_name' => $op->display_name,
                'ordinal' => $op->ordinal,
                'tier' => $op->tier,
                'badge' => $op->badge,
            ];
        }

        $pending = $this->pendingFounders((int) Operator::whereNotNull('ordinal')->count());

        return response()->json([
            'board_id' => 'founders',
            'title' => 'Founding Cohort',
            'count' => count($entries),
            'entries' => $entries,
            // Additive (spec §6, iicp-recognition 0.6.2): provisional operators on the
            // 30-day clock. projected_ordinal is an ESTIMATE (ordinals are assigned only
            // at lock-in, §5.4.3) — it shifts if an earlier provisional drops out or a
            // later one overtakes a lapsed predecessor. Never authoritative.
            'pending' => $pending,
        ])->header('Cache-Control', 'public, s-maxage=300');
    }

    /**
     * Provisional founders: no ordinal yet, but a genuine served node (same unforgeable
     * gate the lock-in detector uses — operator_verified + public_reachable + served
     * within the trailing 24h). Ordered by first appearance; the projection extends the
     * locked count. days_remaining 0 = eligible at the next daily scan.
     *
     * @return array<int, array{display_name:?string, projected_ordinal:int, days_remaining:int, provisional:bool}>
     */
    private function pendingFounders(int $lockedCount): array
    {
        $nowMs = (int) (microtime(true) * 1000);

        $candidates = Operator::whereNull('ordinal')
            ->whereNotNull('first_seen_ms')
            ->orderBy('first_seen_ms')
            ->limit(self::MAX_PENDING * 2) // headroom: some fail the served-node gate
            ->get(['display_name', 'first_seen_ms', 'operator_pubkey']);

        $pending = [];
        $projected = $lockedCount;
        foreach ($candidates as $op) {
            if (! $this->detector->hasGenuineServedNode($op->operator_pubkey)) {
                continue;
            }
            $elapsedMs = max(0, $nowMs - (int) $op->first_seen_ms);
            $remainingMs = max(0, FounderRecognition::LOCKIN_MIN_AGE_MS - $elapsedMs);
            $pending[] = [
                'display_name' => $op->display_name,
                'projected_ordinal' => ++$projected,
                'days_remaining' => (int) ceil($remainingMs / 86_400_000),
                'provisional' => true,
            ];
            if (count($pending) >= self::MAX_PENDING) {
                break;
            }
        }

        return $pending;
    }
}

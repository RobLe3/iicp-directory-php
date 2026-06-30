<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

/**
 * Founder-ordinal recognition computation — spec/iicp-recognition.md §5.4 (#310).
 *
 * Pure, side-effect-free functions: given a founder's ordinal + lock-in time + genesis time,
 * compute the single-best founder badge. The directory is authoritative (RECOG-FND); the
 * underlying state rides FOUNDER_LOCKIN / FOUNDER_SUCCESSION signed events on the #458
 * hash-chained log. The 30-day lock-in detector + persistence + read endpoint are a later
 * #310 slice; this is the algorithm they call.
 *
 * Byte-for-byte logic parity with iicp-directory-rs src/recognition.rs (#385).
 */
class FounderRecognition
{
    /** New signed event types (extend ADR-013 / iicp-dir §3.4). Emitted only by the directory. */
    public const EVENT_LOCKIN = 'FOUNDER_LOCKIN';

    public const EVENT_SUCCESSION = 'FOUNDER_SUCCESSION';

    /** One "month" = 30 days in ms — uniform tier-window unit (MUST match Rust MONTH_MS). */
    public const MONTH_MS = 30 * 24 * 60 * 60 * 1000;

    /** [ordinal cap, lock-in window ms, tier badge] — nested, ascending cap (spec §5.4.3). */
    private const TIERS = [
        [50, 3 * self::MONTH_MS, 'genesis_50'],
        [500, 6 * self::MONTH_MS, 'founders_500'],
        [1000, 12 * self::MONTH_MS, 'founders_1000'],
    ];

    /** Inner pure-ordinal sub-brackets inside Genesis-50 (single-best, finest threshold first). */
    private const INNER = [[10, 'first_10'], [20, 'first_20'], [30, 'first_30'], [50, 'first_50']];

    /**
     * §5.4.2 primary lock-in gate: ≥ 30 days of healthy operation. The demand-scaled task floor
     * (verified availability substitutes for a raw task count when there are no consumers) is a
     * refinement layered in the scan/detect step; this constant is the age component.
     */
    public const LOCKIN_MIN_AGE_MS = 30 * 24 * 60 * 60 * 1000;

    /**
     * The broad founder tier (genesis_50 / founders_500 / founders_1000) for an ordinal locked
     * at $lockedAtMs (genesis at $genesisMs), or null if outside every founder tier. A tier
     * requires BOTH its ordinal cap AND its lock-in window (spec §5.4.3, rule R2 — window on the
     * lock-in timestamp). Tiers are checked tightest-first.
     */
    public static function founderTier(int $ordinal, int $lockedAtMs, int $genesisMs): ?string
    {
        if ($ordinal <= 0) {
            return null;
        }
        $elapsed = max(0, $lockedAtMs - $genesisMs);
        foreach (self::TIERS as [$cap, $window, $tier]) {
            if ($ordinal <= $cap && $elapsed <= $window) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * The single-best founder badge — the broad tier, refined inside Genesis-50 to the finest
     * inner sub-bracket (first_10 / first_20 / first_30 / first_50). Null if not a founder.
     */
    public static function founderBadge(int $ordinal, int $lockedAtMs, int $genesisMs): ?string
    {
        $tier = self::founderTier($ordinal, $lockedAtMs, $genesisMs);
        if ($tier === 'genesis_50') {
            foreach (self::INNER as [$thresh, $badge]) {
                if ($ordinal <= $thresh) {
                    return $badge;
                }
            }
        }

        return $tier;
    }

    /**
     * Ordinal assigned at lock-in = (count of prior FOUNDER_LOCKIN events) + 1 (spec §5.4.3 /
     * caveat N4 — assigned at lock-in so reclaiming a provisional slot renumbers nobody).
     */
    public static function assignOrdinal(int $priorLockinCount): int
    {
        return $priorLockinCount + 1;
    }

    /**
     * §5.4.2 lock-in eligibility: ≥ 30 days of operation AND currently healthy
     * (heartbeat-challenge-verified by the caller). $firstSeenMs is the directory-observed
     * first-seen timestamp — NEVER the backdatable attested created_at.
     */
    public static function isLockinEligible(int $firstSeenMs, int $nowMs, bool $healthy): bool
    {
        return $healthy && ($nowMs - $firstSeenMs) >= self::LOCKIN_MIN_AGE_MS;
    }

    /**
     * Decide a founder lock-in (§5.4.2 gate + §5.4.3 assignment). Returns the immutable
     * assignment to persist + emit as a FOUNDER_LOCKIN event, or null when not (yet) eligible
     * or past the final founder window. The lock-in timestamp is $nowMs (the directory-observed
     * lock-in moment); tiers are measured against it.
     */
    public static function decideLockin(
        int $firstSeenMs,
        int $nowMs,
        bool $healthy,
        int $priorLockinCount,
        int $genesisMs
    ): ?array {
        if (! self::isLockinEligible($firstSeenMs, $nowMs, $healthy)) {
            return null;
        }
        $ordinal = self::assignOrdinal($priorLockinCount);
        $tier = self::founderTier($ordinal, $nowMs, $genesisMs);
        if ($tier === null) {
            return null; // past the final founder window — no founder ordinal is minted
        }

        return [
            'ordinal' => $ordinal,
            'tier' => $tier,
            'badge' => self::founderBadge($ordinal, $nowMs, $genesisMs),
            'locked_at_ms' => $nowMs,
        ];
    }
}

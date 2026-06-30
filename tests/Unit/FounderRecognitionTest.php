<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Services\FounderRecognition as FR;
use Tests\TestCase;

/**
 * #310 / spec §5.4 — founder-ordinal computation. Mirrors iicp-directory-rs
 * src/recognition.rs tests (#385 parity): single-best brackets, tier-needs-both-
 * ordinal-and-window, boundaries, ordinal assignment.
 */
class FounderRecognitionTest extends TestCase
{
    private const G = 0; // genesis at t=0

    private function day(int $n): int
    {
        return $n * 24 * 60 * 60 * 1000;
    }

    public function test_single_best_inner_brackets_within_genesis_50(): void
    {
        $this->assertSame('first_10', FR::founderBadge(1, $this->day(1), self::G));
        $this->assertSame('first_10', FR::founderBadge(10, $this->day(1), self::G));
        $this->assertSame('first_20', FR::founderBadge(11, $this->day(1), self::G));
        $this->assertSame('first_30', FR::founderBadge(25, $this->day(1), self::G));
        $this->assertSame('first_50', FR::founderBadge(45, $this->day(1), self::G));
    }

    public function test_tier_requires_both_ordinal_and_window(): void
    {
        // ordinal 5 but locked at day 100 (> 3mo) → Genesis-50 window fails → Founders-500.
        $this->assertSame('founders_500', FR::founderBadge(5, $this->day(100), self::G));
        $this->assertSame('founders_500', FR::founderBadge(51, $this->day(1), self::G));
        // ordinal 600 at day 200 (> 6mo, ≤ 12mo) → Founders-1000.
        $this->assertSame('founders_1000', FR::founderBadge(600, $this->day(200), self::G));
    }

    public function test_outside_every_tier_is_null(): void
    {
        $this->assertNull(FR::founderBadge(1001, $this->day(1), self::G)); // over the cap
        $this->assertNull(FR::founderBadge(600, $this->day(400), self::G)); // > 12mo
        $this->assertNull(FR::founderBadge(0, $this->day(1), self::G)); // no ordinal
    }

    public function test_window_boundaries_are_inclusive(): void
    {
        $this->assertSame('first_50', FR::founderBadge(50, 3 * FR::MONTH_MS, self::G));
        $this->assertSame('founders_500', FR::founderBadge(500, 6 * FR::MONTH_MS, self::G));
        $this->assertSame('founders_1000', FR::founderBadge(1000, 12 * FR::MONTH_MS, self::G));
        // 1ms over the Genesis-50 window → falls to the next tier.
        $this->assertSame('founders_500', FR::founderBadge(50, 3 * FR::MONTH_MS + 1, self::G));
    }

    public function test_ordinal_assigned_sequentially_at_lockin(): void
    {
        $this->assertSame(1, FR::assignOrdinal(0));
        $this->assertSame(50, FR::assignOrdinal(49));
        $this->assertSame(1000, FR::assignOrdinal(999));
    }

    public function test_founder_tier_is_broad_not_single_best(): void
    {
        // founderTier returns the broad tier; founderBadge refines inside Genesis-50.
        $this->assertSame('genesis_50', FR::founderTier(5, $this->day(1), self::G));
        $this->assertSame('first_10', FR::founderBadge(5, $this->day(1), self::G));
        $this->assertSame('founders_500', FR::founderTier(60, $this->day(1), self::G));
    }

    public function test_lockin_eligibility_age_and_health_gate(): void
    {
        // exactly 30 days + healthy → eligible; one ms short → not.
        $this->assertTrue(FR::isLockinEligible(0, FR::LOCKIN_MIN_AGE_MS, true));
        $this->assertFalse(FR::isLockinEligible(0, FR::LOCKIN_MIN_AGE_MS - 1, true));
        // 30 days but unhealthy → not eligible (uptime-primary, R1).
        $this->assertFalse(FR::isLockinEligible(0, FR::LOCKIN_MIN_AGE_MS, false));
    }

    public function test_decide_lockin_assigns_ordinal_tier_badge_when_eligible(): void
    {
        // First founder, locked at day 31 (genesis t=0), 0 prior lock-ins → ordinal 1, first_10.
        $lockedAt = $this->day(31);
        $d = FR::decideLockin(0, $lockedAt, true, 0, self::G);
        $this->assertSame(1, $d['ordinal']);
        $this->assertSame('genesis_50', $d['tier']);
        $this->assertSame('first_10', $d['badge']);
        $this->assertSame($lockedAt, $d['locked_at_ms']);
    }

    public function test_decide_lockin_null_when_not_yet_eligible(): void
    {
        // Only 10 days of operation → no lock-in yet.
        $this->assertNull(FR::decideLockin(0, $this->day(10), true, 0, self::G));
    }

    public function test_decide_lockin_null_past_final_window(): void
    {
        // Eligible by age + healthy, but lock-in at day 400 (> 12mo) → no founder ordinal minted.
        $this->assertNull(FR::decideLockin(0, $this->day(400), true, 0, self::G));
    }
}

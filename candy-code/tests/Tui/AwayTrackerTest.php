<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tui\AwayTracker;

/**
 * The away stamp behind the focus recap (C-TUI-3.g, GAP-UI-N4).
 *
 * Every case drives the injectable clock — the threshold is three minutes
 * of real time, and a test may not sleep through it. What is pinned is the
 * stamp algebra: only the last loss counts, a regain always consumes, and
 * only a gap of at least the threshold is ever handed back as a length.
 */
final class AwayTrackerTest extends TestCase
{
    /** A fixed epoch in the middle range of microtime() values. */
    private const T0 = 1_800_000_000.0;

    protected function setUp(): void
    {
        AwayTracker::reset();
    }

    protected function tearDown(): void
    {
        AwayTracker::reset();
    }

    public function testThresholdAndFloorAreTheClaudeCodeParityNumbers(): void
    {
        self::assertSame(180.0, AwayTracker::AWAY_SECONDS);
        self::assertSame(3, AwayTracker::MIN_USER_TURNS);
        self::assertSame('sessions.awayRecap', AwayTracker::SETTING_KEY);
    }

    public function testARegainWithNoStampClosesNothing(): void
    {
        self::assertNull(AwayTracker::focusGained(self::T0 + 10));
    }

    public function testAShortAbsenceIsConsumedAndNotCounted(): void
    {
        AwayTracker::focusLost(self::T0);

        // Under the threshold: nothing to act on...
        self::assertNull(AwayTracker::focusGained(self::T0 + 179.9));

        // ...but the stamp is gone anyway, so a stray second regain cannot
        // find the same absence waiting to be counted.
        self::assertNull(AwayTracker::focusGained(self::T0 + 400));
    }

    public function testAnAbsencePastTheThresholdIsReturnedOnce(): void
    {
        AwayTracker::focusLost(self::T0);

        self::assertSame(200.0, AwayTracker::focusGained(self::T0 + 200));
        self::assertNull(AwayTracker::focusGained(self::T0 + 400));
    }

    public function testTheThresholdEdgeCounts(): void
    {
        AwayTracker::focusLost(self::T0);

        self::assertSame(180.0, AwayTracker::focusGained(self::T0 + 180));
    }

    public function testALaterLossReplacesAnEarlierOne(): void
    {
        AwayTracker::focusLost(self::T0);
        AwayTracker::focusLost(self::T0 + 500);

        // The measured gap is from the LAST loss — a compositor that
        // double-reports a loss/gain pair must not inherit the older stamp.
        self::assertSame(190.0, AwayTracker::focusGained(self::T0 + 690));
    }

    public function testACountedAbsenceDoesNotPayForTheNextOne(): void
    {
        AwayTracker::focusLost(self::T0);
        self::assertSame(200.0, AwayTracker::focusGained(self::T0 + 200));

        AwayTracker::focusLost(self::T0 + 200);
        self::assertNull(AwayTracker::focusGained(self::T0 + 300));
    }

    public function testResetDropsAHeldStamp(): void
    {
        AwayTracker::focusLost(self::T0);
        AwayTracker::reset();

        self::assertNull(AwayTracker::focusGained(self::T0 + 400));
    }

    public function testProductionCallsWithoutAClockRoundTripTheStamp(): void
    {
        // The no-argument path production drives: two back-to-back calls
        // measure a real absence of microseconds — far under the threshold,
        // so nothing is counted, and the regain consumed the stamp anyway.
        AwayTracker::focusLost();

        self::assertNull(AwayTracker::focusGained());
        self::assertNull(AwayTracker::focusGained());
    }
}

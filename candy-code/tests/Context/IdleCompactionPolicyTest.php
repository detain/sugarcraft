<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\IdleCompactionPolicy;

/**
 * The predicate Chat and Runtime both delegate to, so the copy of the logic
 * each of them keeps cannot disagree about either number.
 *
 * The file carries a second tier's number now too — the compaction circuit
 * breaker's `REFILL_LIMIT` — and it is tested here for the same reason the idle
 * pair is: the caller holds a count and this decides whether that many is enough
 * to stop, which is not a question two callers should answer separately. And a
 * third predicate (CTX-5): {@see IdleCompactionPolicy::shouldAutoCompact()} is
 * the opt-in idle AUTO-fold's reuse of the idle clock, tested for the standing
 * reason — the two hosts that call it must not disagree about what "idle" means.
 * A fourth clock joins the file with C-CTX-5.c — the `cacheTtl` fold's
 * prompt-cache table ({@see IdleCompactionPolicy::shouldAutoCompactAtCacheTtl()});
 * its boundaries are pinned in {@see \SugarCraft\Code\Tests\Context\CacheTtlIdleFoldTest},
 * which keeps this file's band readable as the hour/offer story it tells.
 *
 * @see IdleCompactionPolicy
 */
final class IdleCompactionPolicyTest extends TestCase
{
    public function testFiresOnlyWhenBothIdleAndPastTheWholeLimit(): void
    {
        $this->assertTrue(IdleCompactionPolicy::shouldPrompt(
            150_000,
            new DateTimeImmutable('2 hours ago'),
            100_000,
        ));
    }

    public function testDoesNotFireWhenTheHistoryFitsTheLimit(): void
    {
        $this->assertFalse(IdleCompactionPolicy::shouldPrompt(
            50_000,
            new DateTimeImmutable('2 hours ago'),
            100_000,
        ));
    }

    /**
     * "Past the whole limit", not "at it" — the boundary the pre-existing
     * Chat/Runtime copies both drew with `<=`.
     */
    public function testBoundaryAtExactlyTheLimitDoesNotFire(): void
    {
        $idle = new DateTimeImmutable('2 hours ago');
        $this->assertFalse(IdleCompactionPolicy::shouldPrompt(100_000, $idle, 100_000));
        $this->assertTrue(IdleCompactionPolicy::shouldPrompt(100_001, $idle, 100_000));
    }

    public function testTheLimitIsWhatMovesTheThresholdNotAHardcodedNumber(): void
    {
        $idle = new DateTimeImmutable('2 hours ago');

        // 10,000 estimated tokens is nowhere near the old hardcoded 100,000,
        // but it is past an 8,192-token window.
        $this->assertTrue(IdleCompactionPolicy::shouldPrompt(10_000, $idle, 8_192));
        $this->assertFalse(IdleCompactionPolicy::shouldPrompt(10_000, $idle, 196_608));
    }

    public function testDoesNotFireWhenRecentlyActive(): void
    {
        $this->assertFalse(IdleCompactionPolicy::shouldPrompt(
            150_000,
            new DateTimeImmutable('30 minutes ago'),
            100_000,
        ));
    }

    /**
     * Both sides of the idle boundary, measured against ONE fixed instant.
     *
     * `new DateTimeImmutable('3600 seconds ago')` truncates sub-second precision
     * on the way to `getTimestamp()`, so with `time()` read separately inside the
     * predicate an integer-second rollover between the two reads makes an
     * exactly-3,600-second-old timestamp measure 3,601 and flip the assertFalse.
     * Low probability, and pinning a `>` boundary on a racing clock is not worth
     * any probability — hence the explicit $now.
     */
    public function testBoundaryAtExactlyTheIdleWindowDoesNotFire(): void
    {
        $this->assertSame(3600, IdleCompactionPolicy::IDLE_SECONDS);

        $now = 1_700_000_000;
        $at = new DateTimeImmutable('@' . ($now - IdleCompactionPolicy::IDLE_SECONDS));
        $past = new DateTimeImmutable('@' . ($now - IdleCompactionPolicy::IDLE_SECONDS - 1));

        $this->assertFalse(IdleCompactionPolicy::shouldPrompt(150_000, $at, 100_000, $now));
        $this->assertTrue(IdleCompactionPolicy::shouldPrompt(150_000, $past, 100_000, $now));
    }

    /**
     * And the default $now really is the wall clock — otherwise the seam above
     * could be the only thing the boundary test proves. Wide margins on both
     * sides, so no rollover can reach either.
     */
    public function testTheDefaultClockIsTheWallClock(): void
    {
        $this->assertTrue(IdleCompactionPolicy::shouldPrompt(
            150_000,
            new DateTimeImmutable('2 hours ago'),
            100_000,
        ));
        $this->assertFalse(IdleCompactionPolicy::shouldPrompt(
            150_000,
            new DateTimeImmutable('1 second ago'),
            100_000,
        ));
    }

    public function testUnknownIdlenessIsNeverGroundsForInterrupting(): void
    {
        $this->assertFalse(IdleCompactionPolicy::shouldPrompt(150_000, null, 100_000));
    }

    /**
     * Same guard as ContextCompactor's three predicates: an unusable limit
     * disables the check instead of making it fire on every turn.
     */
    public function testANonPositiveLimitDisablesTheCheck(): void
    {
        $idle = new DateTimeImmutable('2 hours ago');
        $this->assertFalse(IdleCompactionPolicy::shouldPrompt(150_000, $idle, 0));
        $this->assertFalse(IdleCompactionPolicy::shouldPrompt(150_000, $idle, -1));
    }

    // =====================================================================
    // The second number this file carries: the compaction circuit breaker.
    // Nothing above moves — the seven tests over there are the idle tier's
    // boundary and they read exactly as they did before this file grew.
    // =====================================================================

    /**
     * Three, because that is the number the upstream fix names, and this port
     * reproduces it rather than tuning its own: prompt_expand.md §4.23 quotes Claude
     * Code's changelog — "compacting three times in a row" — and a port that quietly
     * picked a different constant would be a different feature with the same name.
     */
    public function testTheRefillLimitIsTheOneUpstreamShipped(): void
    {
        $this->assertSame(3, IdleCompactionPolicy::REFILL_LIMIT);
    }

    /**
     * Both sides of the boundary. The count is "compactions that already refilled",
     * so two is still evidence of something working and three is the trip — one short
     * of the limit must never stop the tier, or the breaker would fire on its first
     * useful retry rather than on its third useless one.
     */
    public function testTheBreakerTripsAtTheLimitAndNotOneBefore(): void
    {
        $this->assertFalse(IdleCompactionPolicy::thrashTripped(IdleCompactionPolicy::REFILL_LIMIT - 1));
        $this->assertTrue(IdleCompactionPolicy::thrashTripped(IdleCompactionPolicy::REFILL_LIMIT));
    }

    /**
     * Tripping is a threshold being crossed and not an event being matched, so
     * everything above the limit stays tripped — including the absurd ends, which is
     * what `>=` costs nothing on and `===` fails at. A counter that overshot (a second
     * increment site added later, a reset wired to the wrong branch) must still stop
     * the spend; that is the whole job of this predicate.
     */
    public function testTheBreakerStaysTrippedAboveTheLimit(): void
    {
        $this->assertTrue(IdleCompactionPolicy::thrashTripped(IdleCompactionPolicy::REFILL_LIMIT + 1));
        $this->assertTrue(IdleCompactionPolicy::thrashTripped(40));
        $this->assertTrue(IdleCompactionPolicy::thrashTripped(\PHP_INT_MAX));
        $this->assertFalse(IdleCompactionPolicy::thrashTripped(0));
        $this->assertFalse(IdleCompactionPolicy::thrashTripped(-1), 'a negative run is no run at all');
    }

    // =====================================================================
    // The third predicate this file carries (CTX-5): the opt-in idle AUTO-fold.
    // It re-measures the clock above and takes the tier verdict from the
    // caller; nothing in the two sections before it moves.
    // =====================================================================

    public function testTheAutoFoldFiresOnlyWhenEnabledOverTierAndIdle(): void
    {
        $now = 1_700_000_000;
        $past = new DateTimeImmutable('@' . ($now - IdleCompactionPolicy::IDLE_SECONDS - 1));

        $this->assertTrue(IdleCompactionPolicy::shouldAutoCompact(true, $past, true, $now));
        $this->assertFalse(IdleCompactionPolicy::shouldAutoCompact(false, $past, true, $now), 'under-tier is the human-offer case, not this one');
        $this->assertFalse(IdleCompactionPolicy::shouldAutoCompact(true, $past, false, $now), 'default-off: nobody pays for a fold that was not opted into');
    }

    /**
     * The same two boundary rules the offer tier pins, because the fold
     * re-measures the SAME clock: strictly past the window, and `0` turns the
     * one idle clock off for both verdicts.
     */
    public function testTheAutoFoldKeepsTheOfferTiersClockBoundaries(): void
    {
        $now = 1_700_000_000;
        $at = new DateTimeImmutable('@' . ($now - IdleCompactionPolicy::IDLE_SECONDS));
        $past = new DateTimeImmutable('@' . ($now - IdleCompactionPolicy::IDLE_SECONDS - 1));

        $this->assertFalse(IdleCompactionPolicy::shouldAutoCompact(true, $at, true, $now));
        $this->assertTrue(IdleCompactionPolicy::shouldAutoCompact(true, $past, true, $now));
        $this->assertFalse(IdleCompactionPolicy::shouldAutoCompact(true, $past, true, $now, 0), 'idleOfferSeconds 0 disables the offer AND the fold');
        $this->assertTrue(IdleCompactionPolicy::shouldAutoCompact(true, new DateTimeImmutable('@' . ($now - 61)), true, $now, 60), 'the window is the session setting, not the constant');
    }

    public function testTheAutoFoldDoesNotGuessWhenIdlenessIsUnknown(): void
    {
        $this->assertFalse(IdleCompactionPolicy::shouldAutoCompact(true, null, true), 'same never-on-unknown rule as the offer');
    }

    /**
     * The default $now is the wall clock here too — the seam must not be the
     * only thing the boundary tests prove (mirrors the offer tier's own
     * wall-clock pin).
     */
    public function testTheAutoFoldDefaultsToTheWallClock(): void
    {
        $this->assertTrue(IdleCompactionPolicy::shouldAutoCompact(true, new DateTimeImmutable('2 hours ago'), true));
        $this->assertFalse(IdleCompactionPolicy::shouldAutoCompact(true, new DateTimeImmutable('1 second ago'), true));
    }
}

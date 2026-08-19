<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\IdleCompactionPolicy;

/**
 * The predicate Chat and Runtime both delegate to, so the copy of the logic
 * each of them keeps cannot disagree about either number.
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
}

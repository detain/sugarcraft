<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Deadline;

/**
 * Unit-math regressions for Deadline (lane p2, N1).
 *
 * hrtime(true) is nanoseconds; the original in() armed its target with
 * `$ms * 1_000` — microseconds on a nanosecond clock — so every budget
 * expired 1000x early, and remaining() divided the ns residual by 1_000,
 * returning microseconds labelled as milliseconds. The creation-time reading
 * was the nominal number (the two errors cancelled at t=0), which is exactly
 * why only sleeping across the gap discriminates: each test below burns a
 * bounded slice of a GENEROUS budget and asserts the remainder still speaks
 * milliseconds. Margins are wide (≥100 ms of slack) so CI scheduling noise
 * cannot flake what is fundamentally a unit pin, never a latency benchmark.
 */
final class DeadlineTest extends TestCase
{
    public function testFreshBudgetReadsItsNominalMilliseconds(): void
    {
        $deadline = Deadline::in(5000);

        $this->assertGreaterThanOrEqual(4990, $deadline->remaining());
        $this->assertLessThanOrEqual(5000, $deadline->remaining());
        $this->assertFalse($deadline->isExpired());
    }

    public function testTwentyMillisecondGapDoesNotExpireAFiveSecondBudget(): void
    {
        // The exact regression shape: under the broken math in(5000) was a
        // 5 ms wall budget, so after 20 ms this read 0/expired.
        $deadline = Deadline::in(5000);
        usleep(20_000);

        $this->assertFalse($deadline->isExpired(), 'a 5 s budget must survive a 20 ms sleep');
        $this->assertGreaterThanOrEqual(4900, $deadline->remaining());
    }

    public function testElapsedTimeDrainsAtOneMillisecondPerMillisecond(): void
    {
        $deadline = Deadline::in(2000);
        usleep(500_000); // >= 500 ms wall, never less

        $remaining = $deadline->remaining();
        // Under the old math the 2000 ms budget was really 2 ms: this read 0.
        $this->assertGreaterThanOrEqual(1400, $remaining, "budget drained ~1000x fast? remaining={$remaining}");
        $this->assertLessThanOrEqual(1500, $remaining);
    }

    public function testSubMillisecondBudgetRoundsInsideItsNominal(): void
    {
        // in(1) is a real 1 ms — it must never read more than its nominal.
        $this->assertLessThanOrEqual(1, Deadline::in(1)->remaining());
    }

    public function testZeroBudgetIsBornExpired(): void
    {
        $deadline = Deadline::in(0);

        $this->assertSame(0, $deadline->remaining());
        $this->assertTrue($deadline->isExpired());
    }

    public function testRemainingStaysClampedAtZeroPastExpiry(): void
    {
        $deadline = Deadline::in(1);
        usleep(20_000);
        $this->assertSame(0, $deadline->remaining());
        $this->assertTrue($deadline->isExpired());

        usleep(20_000);
        $this->assertSame(0, $deadline->remaining(), 'remaining() must never go negative');
    }

    /**
     * D-06: the deterministic 400 µs-before-zero pin (no sleeps).
     *
     * The class seam is the private float targetNs the constructor already
     * takes verbatim; reflection writes it so the residual is a chosen
     * fraction of a millisecond. Today's contradiction — a live budget
     * reading as expired — is exactly what this pins dead: 0 whole ms left
     * AND not expired must hold at the same instant.
     */
    public function testFractionalMillisecondIsZeroWholeMsButNotExpired(): void
    {
        $deadline = Deadline::in(0);
        $this->targetInTheFuture($deadline, 400_000.0); // 0.4 ms

        $this->assertSame(0, $deadline->remainingMs(), 'floor must not inflate 0.4 ms to 1');
        $this->assertFalse($deadline->isExpired(), '0.4 ms of live budget is NOT expired');
    }

    /**
     * D-06: floor never rounds up — the sleeping-caller half of the split.
     */
    public function testRemainingMsFloorsInsteadOfRounding(): void
    {
        $deadline = Deadline::in(0);
        $this->targetInTheFuture($deadline, 1_600_000.0); // 1.6 ms

        $this->assertSame(1, $deadline->remainingMs(), 'round() would answer 2 and oversleep past the target');
        $this->assertFalse($deadline->isExpired());
    }

    /**
     * D-06: the deprecated alias delegates — remaining() and remainingMs()
     * can no longer disagree at any reading.
     */
    public function testRemainingDelegatesToRemainingMs(): void
    {
        $deadline = Deadline::in(0);
        $this->targetInTheFuture($deadline, 1_600_000.0); // 1.6 ms

        $this->assertSame($deadline->remainingMs(), $deadline->remaining());
        $this->assertSame(1, $deadline->remaining());
    }

    /**
     * D-06: expiry judged on the ns clock — a target even 400 µs in the
     * past is expired, and exactly-at-target is expired (nothing left).
     */
    public function testIsExpiredIsExactAtAndPastTheTarget(): void
    {
        $deadline = Deadline::in(0);
        $this->targetInTheFuture($deadline, -400_000.0); // 0.4 ms ago

        $this->assertTrue($deadline->isExpired());
        $this->assertSame(0, $deadline->remainingMs());
        $this->assertSame(0, $deadline->remaining());
    }

    /**
     * D-06 seam: write the private ns target relative to "now" without a
     * sleep, so edge readings are deterministic.
     */
    private function targetInTheFuture(Deadline $deadline, float $ns): void
    {
        $property = new \ReflectionProperty(Deadline::class, 'targetNs');
        $property->setValue($deadline, hrtime(true) + $ns);
    }
}

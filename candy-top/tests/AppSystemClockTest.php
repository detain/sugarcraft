<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\App;
use SugarCraft\Top\Msg\ClockTickMsg;

/**
 * Finding 149 (S-X18): the clock answered 0 for uptime on every host
 * without /proc/uptime, so %u/%U showed a permanent zero off Linux. The
 * boot-time seam drives the fallback arm the Linux host can never reach
 * on its own; the /proc fast path stays untouched and is pinned directly.
 */
final class AppSystemClockTest extends TestCase
{
    protected function tearDown(): void
    {
        App::pretendBootTimeForTesting(null);
        App::pretendBootProbeForTesting(null);
    }

    public function testTheBootTimeFallbackAnswersWallTimeMinusBootInstant(): void
    {
        App::pretendBootTimeForTesting(microtime(true) - 3600.0);
        $tick = (App::systemClock())();
        self::assertInstanceOf(ClockTickMsg::class, $tick);
        self::assertEqualsWithDelta(3600.0, $tick->uptime, 2.0);
    }

    public function testABootInstantInTheFutureClampsToZeroInsteadOfGoingNegative(): void
    {
        // A clock adjusted backwards between boot and now must render 0s,
        // never a negative number — the format helpers are built to trust
        // this message's uptime floor.
        App::pretendBootTimeForTesting(microtime(true) + 60.0);
        $tick = (App::systemClock())();
        self::assertSame(0.0, $tick->uptime);
    }

    public function testLinuxStillAnswersFromProcUptimeWithoutBeingAsked(): void
    {
        if (!is_file('/proc/uptime')) {
            self::markTestSkipped('the /proc fast path is Linux-specific');
        }
        $expected = (float) strtok((string) file_get_contents('/proc/uptime'), ' ');
        $tick = (App::systemClock())();
        self::assertEqualsWithDelta($expected, $tick->uptime, 2.0);
    }

    /**
     * CI wave 5 (5A): on macOS the runner saw exactly ONE synced frame in
     * 15s — the clock closure used to discover the boot instant inline
     * (a synchronous sysctl child inside the loop's first callback), and
     * a stalled spawn there starves every paint behind it. The pins below
     * construct the off-Linux SHAPE on Linux through the probe seam and
     * hold the two invariants that make the stall structurally impossible:
     * the closure never discovers, and discovery is asked exactly once.
     */
    public function testTheClockClosureNeverDiscoversTheBootInstant(): void
    {
        $asked = 0;
        App::pretendBootProbeForTesting(static function () use (&$asked): float {
            ++$asked;

            return microtime(true) - 1000.0;
        });
        $clock = App::systemClock();
        foreach ([$clock, $clock, $clock] as $tick) {
            $msg = $tick();
            self::assertInstanceOf(ClockTickMsg::class, $msg);
            self::assertSame(0.0, $msg->uptime, 'an unprimed memo floors at 0 — it must not stop to ask');
        }
        self::assertSame(0, $asked, 'the loop callback must not be able to reach a probe, a child or the OS');
    }

    public function testPrimingRunsTheProbeOnceAndEveryLaterTickReadsTheMemo(): void
    {
        $asked = 0;
        $boot = microtime(true) - 1000.0;
        App::pretendBootProbeForTesting(static function () use (&$asked, $boot): float {
            ++$asked;

            return $boot;
        });
        App::primeBootTime();
        self::assertSame(1, $asked, 'discovery is one-shot at boot');
        App::primeBootTime();
        self::assertSame(1, $asked, 'a second prime reads the memo, never the probe');
        $first = (App::systemClock())();
        $second = (App::systemClock())();
        self::assertSame(1, $asked, 'clock ticks never discover');
        self::assertEqualsWithDelta(1000.0, $first->uptime, 2.0);
        self::assertGreaterThanOrEqual($first->uptime, $second->uptime, 'the same boot instant under every tick');
    }

    public function testAProbeThatCannotAnswerFloorsAtZeroForever(): void
    {
        App::pretendBootProbeForTesting(static fn (): ?float => null);
        App::primeBootTime();
        self::assertSame(0.0, (App::systemClock())()->uptime);
        App::primeBootTime();
        self::assertSame(0.0, (App::systemClock())()->uptime, 'the no-answer sentinel is memoised too');
    }

    public function testThePinnedOverrideWinsOverAnyProbe(): void
    {
        $asked = 0;
        App::pretendBootTimeForTesting(microtime(true) - 3600.0);
        App::pretendBootProbeForTesting(static function () use (&$asked): float {
            ++$asked;

            return 0.0;
        });
        App::primeBootTime();
        self::assertSame(0, $asked, 'a pinned boot instant needs no discovery');
        self::assertEqualsWithDelta(3600.0, (App::systemClock())()->uptime, 2.0);
    }

    public function testPrimingIsANoOpWhereProcUptimeAnswers(): void
    {
        if (!is_file('/proc/uptime')) {
            self::markTestSkipped('the /proc fast path is Linux-specific');
        }
        App::primeBootTime();
        $expected = (float) strtok((string) file_get_contents('/proc/uptime'), ' ');
        $tick = (App::systemClock())();
        self::assertEqualsWithDelta($expected, $tick->uptime, 2.0, 'priming must not move the Linux answer');
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Both seams return to the real OS rules before every pin, so a
        // failing assertion mid-test cannot leak discovery state.
        App::pretendBootTimeForTesting(null);
        App::pretendBootProbeForTesting(null);
    }
}

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
}

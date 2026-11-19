<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\Daemonize;
use SugarCraft\ServeProtocol\Discovery\ProcessIdentity;

/**
 * Pins the bundles' read-side liveness check (ProcessIdentity, plan P4) to the
 * host's Daemonize::isSameProcess on the cases a discovery record can meet: a
 * live pid with an unknown start time, a live pid with a mismatched start time,
 * and a pid that cannot be alive.
 */
final class ProcessIdentityParityTest extends TestCase
{
    public function testTheLivePidAgreesWithTheHostWithAnUnknownStartTime(): void
    {
        $pid = \getmypid();

        self::assertSame(Daemonize::isSameProcess($pid, null), ProcessIdentity::isSameProcess($pid, null));
        self::assertTrue(ProcessIdentity::isSameProcess($pid, null));
    }

    public function testAMismatchedStartTimeAgreesWithTheHost(): void
    {
        $pid = \getmypid();

        self::assertSame(Daemonize::isSameProcess($pid, -1), ProcessIdentity::isSameProcess($pid, -1));
        self::assertFalse(ProcessIdentity::isSameProcess($pid, -1));
    }

    public function testAPidThatCannotBeAliveAgreesWithTheHost(): void
    {
        $pid = 2147483646;

        self::assertSame(Daemonize::isSameProcess($pid, null), ProcessIdentity::isSameProcess($pid, null));
        self::assertFalse(ProcessIdentity::isSameProcess($pid, null));
    }
}

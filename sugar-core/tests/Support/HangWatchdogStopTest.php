<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Regression: teardown with nothing left on disk must not raise a PHP warning.
 * An @-suppressed unlink() of a missing file still reaches the error handler,
 * where PHPUnit turns it into an exception outside any test.
 */
final class HangWatchdogStopTest extends TestCase
{
    public function testStopWithNothingLeftOnDiskRaisesNoError(): void
    {
        $dir = sys_get_temp_dir() . '/sugar-core-hang-stop-' . bin2hex(random_bytes(6));
        // Neither the heartbeat, its tmp siblings nor the state dir exist.
        $make = \Closure::bind(
            static fn (string $heartbeat, string $stateDir): HangWatchdog => new HangWatchdog($heartbeat, $stateDir),
            null,
            HangWatchdog::class,
        );
        $watchdog = $make($dir . '/heartbeat', $dir);

        $raised = [];
        set_error_handler(static function (int $errno, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });
        try {
            $watchdog->stop();
            $watchdog->stop();
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $raised, 'stop() must only touch files that exist');
    }
}

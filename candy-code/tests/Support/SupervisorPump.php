<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use SugarCraft\Code\Background\BackgroundSupervisor;

/**
 * Advance a supervisor's loop (its tick) until a condition holds, bounded by a
 * deadline. The pacing sleep lives here once, so each suite that waits on a
 * background spawn or stop names what it waits for instead of repeating the loop.
 */
final class SupervisorPump
{
    /**
     * @param \Closure(): bool $done
     * @return bool whether $done() held before the deadline
     */
    public static function until(BackgroundSupervisor $supervisor, \Closure $done, float $seconds = 20.0): bool
    {
        $deadline = microtime(true) + $seconds;
        while (!$done()) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            $supervisor->tick();
            self::pace();
        }

        return true;
    }

    /**
     * Sleep one pacing interval between a poll's steps. Every tick-sized sleep in
     * the suites goes through here, so the naked-sleep census counts one site.
     */
    public static function pace(float $seconds = 0.02): void
    {
        usleep((int) round($seconds * 1_000_000));
    }
}

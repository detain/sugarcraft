<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

use SugarCraft\Async\WindowsPipes as AsyncWindowsPipes;

/**
 * candy-code's handle on sugar-async's {@see AsyncWindowsPipes}: non-blocking
 * reads of proc_open pipes on Windows, where PHP's own non-blocking machinery
 * does not apply to them.
 *
 * The measurements and the reasoning live on the sugar-async class, which
 * `ChildPump::drain()` uses too; this one forwards so candy-code's capture
 * loops and the shared pump read pipes the same way.
 */
final class WindowsPipes
{
    /** Poll slice while nothing is readable, so a silent child costs no CPU. */
    public const POLL_MICROSECONDS = AsyncWindowsPipes::POLL_MICROSECONDS;

    /** How long a caller keeps draining after the child exits (see sugar-async). */
    public const EXIT_DRAIN_GRACE_SECONDS = AsyncWindowsPipes::EXIT_DRAIN_GRACE_SECONDS;

    /**
     * Whether THIS PHP runtime has the blocking-pipe behavior: the real OS,
     * never WindowsShell's injectable family.
     */
    public static function runtimeNeedsIt(): bool
    {
        return AsyncWindowsPipes::runtimeNeedsIt();
    }

    /**
     * Bytes readable from $pipe right now, without blocking.
     *
     * @param resource $pipe
     */
    public static function available($pipe): int
    {
        return AsyncWindowsPipes::available($pipe);
    }

    /**
     * Read what is already waiting in $pipe, at most $max bytes; never blocks.
     *
     * @param resource $pipe
     */
    public static function readAvailable($pipe, int $max = 8192): string
    {
        return AsyncWindowsPipes::readAvailable($pipe, $max);
    }

    /**
     * Child-still-running probe for `ChildPump::drain(running:)`.
     *
     * @param resource $process
     * @return \Closure(): bool
     */
    public static function runningProbe($process): \Closure
    {
        return AsyncWindowsPipes::runningProbe($process);
    }
}

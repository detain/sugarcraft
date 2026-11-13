<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use SugarCraft\Core\Util\Proc\WindowsPipes as CoreWindowsPipes;

/**
 * sugar-async's handle on sugar-core's {@see CoreWindowsPipes}: non-blocking
 * reads of proc_open pipes on Windows, where PHP's own non-blocking machinery
 * does not apply to them. The measurements and reasoning live on the
 * sugar-core class (the primitive sits there so libraries that do not depend
 * on sugar-async can use it too); {@see ChildPump::drain()} reads through it.
 */
final class WindowsPipes
{
    /** Poll slice while nothing is readable, so a silent child costs no CPU. */
    public const POLL_MICROSECONDS = CoreWindowsPipes::POLL_MICROSECONDS;

    /** How long a caller keeps draining after the child exits (see sugar-core). */
    public const EXIT_DRAIN_GRACE_SECONDS = CoreWindowsPipes::EXIT_DRAIN_GRACE_SECONDS;

    /** Whether THIS PHP runtime has the blocking-pipe behavior (the real OS). */
    public static function runtimeNeedsIt(): bool
    {
        return CoreWindowsPipes::runtimeNeedsIt();
    }

    /**
     * Bytes readable from $pipe right now, without blocking.
     *
     * @param resource $pipe
     */
    public static function available($pipe): int
    {
        return CoreWindowsPipes::available($pipe);
    }

    /**
     * Read what is already waiting in $pipe, at most $max bytes; never blocks.
     *
     * @param resource $pipe
     */
    public static function readAvailable($pipe, int $max = 8192): string
    {
        return CoreWindowsPipes::readAvailable($pipe, $max);
    }

    /**
     * A "child still running" probe for {@see ChildPump::drain()}'s `running:`.
     *
     * @param resource $process
     * @return \Closure(): bool
     */
    public static function runningProbe($process): \Closure
    {
        return CoreWindowsPipes::runningProbe($process);
    }
}

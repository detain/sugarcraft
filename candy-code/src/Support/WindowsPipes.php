<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

use SugarCraft\Core\Util\Proc\WindowsPipes as CoreWindowsPipes;

/**
 * candy-code's handle on sugar-core's {@see CoreWindowsPipes}: non-blocking
 * reads of proc_open pipes on Windows, where PHP's own non-blocking machinery
 * does not apply to them.
 *
 * The measurements and the reasoning live on the sugar-core class, which
 * sugar-async's `ChildPump::drain()` reads through too; this one forwards so
 * candy-code's capture loops and the shared pump read pipes the same way.
 */
final class WindowsPipes
{
    /** Poll slice while nothing is readable, so a silent child costs no CPU. */
    public const POLL_MICROSECONDS = CoreWindowsPipes::POLL_MICROSECONDS;

    /** How long a caller keeps draining after the child exits (see sugar-core). */
    public const EXIT_DRAIN_GRACE_SECONDS = CoreWindowsPipes::EXIT_DRAIN_GRACE_SECONDS;

    /** A write of at most this many bytes into an empty Windows pipe never blocks. */
    public const PIPE_BUFFER_BYTES = CoreWindowsPipes::PIPE_BUFFER_BYTES;

    /**
     * Whether THIS PHP runtime has the blocking-pipe behavior: the real OS,
     * never WindowsShell's injectable family.
     */
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
     * Child-still-running probe for `ChildPump::drain(running:)`.
     *
     * @param resource $process
     * @return \Closure(): bool
     */
    public static function runningProbe($process): \Closure
    {
        return CoreWindowsPipes::runningProbe($process);
    }

    /**
     * Stage stdin bytes as a private temp file for a `['file', $path, 'r']`
     * descriptor (a Windows stdin pipe write can block past any deadline);
     * null when it cannot be written. The caller removes it after the reap.
     */
    public static function stageStdin(string $bytes): ?string
    {
        return CoreWindowsPipes::stageStdin($bytes);
    }

    /**
     * The whole Windows read loop for a caller that only collects output: read
     * until the child exits (and a last pass finds nothing) or the deadline.
     *
     * @param array<int|string, resource> $pipes
     * @param \Closure(): bool $running
     * @return array{buffers: array<int|string, string>, timedOut: bool, overflowed: bool}
     */
    public static function drainUntilExit(array $pipes, \Closure $running, float $deadline, int $maxBytes = \PHP_INT_MAX): array
    {
        return CoreWindowsPipes::drainUntilExit($pipes, $running, $deadline, $maxBytes);
    }
}

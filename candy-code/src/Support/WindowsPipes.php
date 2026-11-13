<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * Non-blocking reads of proc_open pipes on Windows, where PHP's own
 * non-blocking machinery does not apply to them.
 *
 * Measured on Windows 11 / PHP 8.4.27, against a `bash -c 'sleep 4'` child:
 *  - `stream_set_blocking($pipe, false)` answers FALSE and the pipe stays
 *    blocking;
 *  - `stream_select()` reports both output pipes ready at once, with no data
 *    in either;
 *  - the `fread()` that follows blocks until the child exits (4.07 s).
 *
 * A select-then-read loop therefore never regains control before the child
 * ends, so its deadline check never runs. That is why a `timeout: 2` Bash call
 * over `sleep 40 & sleep 40 & wait` returned after 40 s with no timeout
 * marker.
 *
 * What does work is `fstat()`. For a pipe, PHP's Windows fstat fills `size`
 * from PeekNamedPipe: the number of bytes readable right now. A read of at
 * most that many bytes returns at once. What fstat cannot report is EOF, and
 * neither can feof() until a read returns nothing, which could block. So a
 * caller ends its loop on the child's exit, after one last drain, rather than
 * on EOF.
 */
final class WindowsPipes
{
    /** Poll slice while nothing is readable, so a silent child costs no CPU. */
    public const POLL_MICROSECONDS = 10_000;

    /**
     * How long a caller keeps draining after the child exits, when it has no
     * deadline of its own. Bytes the child wrote before exiting are already
     * in the pipe; this grace exists only so a backgrounded grandchild that
     * keeps the pipe fed cannot hold the drain open forever.
     */
    public const EXIT_DRAIN_GRACE_SECONDS = 1.0;

    /**
     * Whether THIS PHP runtime has the blocking-pipe behavior. That depends on
     * the real OS PHP runs on, never on WindowsShell's injectable family: on
     * Linux, fstat() of a pipe always reports size 0, so the fstat-driven
     * reader would see nothing there.
     */
    public static function runtimeNeedsIt(): bool
    {
        return \PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Bytes readable from $pipe right now, without blocking; 0 when none or
     * when the stream cannot be measured.
     *
     * @param resource $pipe
     */
    public static function available($pipe): int
    {
        if (!\is_resource($pipe)) {
            return 0;
        }

        $stat = @\fstat($pipe);

        return \is_array($stat) ? \max(0, (int) ($stat['size'] ?? 0)) : 0;
    }

    /**
     * Read what is already waiting in $pipe, at most $max bytes; '' when
     * nothing is. Never blocks: it never asks for more than fstat reported.
     *
     * @param resource $pipe
     */
    public static function readAvailable($pipe, int $max = 8192): string
    {
        $ready = \min(self::available($pipe), \max(1, $max));

        if ($ready === 0) {
            return '';
        }

        $chunk = \fread($pipe, $ready);

        return \is_string($chunk) ? $chunk : '';
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Proc;

/**
 * Non-blocking reads of proc_open pipes on Windows, where PHP's own
 * non-blocking machinery does not apply to them.
 *
 * Measured on Windows 11 / PHP 8.4.27:
 *  - `stream_set_blocking($pipe, false)` answers FALSE and the pipe stays
 *    blocking;
 *  - `stream_select()` reports every pipe ready at once, with no data in any;
 *  - the `fread()` that follows parks until the child writes or exits (PHP's
 *    own pipe read spins on PeekNamedPipe for up to 3.2 million 10 µs naps);
 *  - a pipe holds 4096 bytes: a larger write blocks until the child reads,
 *    and fails outright, every byte lost, if the child exits first.
 *
 * So a select-then-read loop never regains control before the child ends,
 * its deadline check never runs, and reading an empty stderr while the child
 * fills stdout deadlocks both.
 *
 * Swapping the pipes for proc_open SOCKET descriptors is no general cure:
 * node.exe, python.exe, cmd.exe and every MSYS/Cygwin tool (Git-for-Windows
 * sh, bash, cat) lose their output or fail on a socket stdio (measured), so
 * an arbitrary child must keep pipes.
 *
 * What works with pipes is `fstat()`: for a pipe, PHP's Windows fstat fills
 * `size` from PeekNamedPipe, the bytes readable right now, and a read of at
 * most that many returns at once. EOF stays invisible (a pipe whose writer
 * is gone also reads size 0, and feof() only turns true after a read that
 * could block), so a caller ends its loop when the child exits and one last
 * pass finds nothing, never on EOF.
 */
final class WindowsPipes
{
    /** Poll slice while nothing is readable, so a silent child costs no CPU. */
    public const POLL_MICROSECONDS = 10_000;

    /**
     * How long a caller keeps draining after the child exits when it has no
     * deadline of its own: bytes written before the exit are already in the
     * pipe; this only stops a backgrounded grandchild that keeps the pipe
     * fed from holding the drain open forever.
     */
    public const EXIT_DRAIN_GRACE_SECONDS = 1.0;

    /** The pipe buffer: a write of at most this many bytes into an empty pipe never blocks. */
    public const PIPE_BUFFER_BYTES = 4096;

    private function __construct()
    {
    }

    /**
     * Whether THIS PHP runtime has the blocking-pipe behavior: the real OS
     * PHP runs on, never an injectable OS family (on Linux, fstat() of a pipe
     * always reports size 0, so the fstat-driven reader would see nothing).
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

    /**
     * A "child still running" probe over a proc_open handle. A handle that is
     * no longer a resource, or whose status cannot be read, counts as exited.
     *
     * @param resource $process
     * @return \Closure(): bool
     */
    public static function runningProbe($process): \Closure
    {
        return static function () use ($process): bool {
            if (!\is_resource($process)) {
                return false;
            }
            $status = @\proc_get_status($process);

            return \is_array($status) && $status['running'] === true;
        };
    }

    /**
     * Read $pipes until the child exits (and a final pass finds nothing) or
     * $deadline passes — the whole Windows read loop for callers that only
     * collect output. Each buffer keeps at most $maxBytes (the rest is read
     * and dropped so the child never blocks on a full pipe).
     *
     * @param array<int|string, resource> $pipes
     * @param \Closure(): bool $running
     * @param float $deadline microtime(true) instant
     * @return array{buffers: array<int|string, string>, timedOut: bool, overflowed: bool}
     */
    public static function drainUntilExit(array $pipes, \Closure $running, float $deadline, int $maxBytes = \PHP_INT_MAX): array
    {
        $buffers = \array_fill_keys(\array_keys($pipes), '');
        $overflowed = false;
        $exitedAt = null;

        while (true) {
            $now = \microtime(true);
            if ($now >= $deadline) {
                return ['buffers' => $buffers, 'timedOut' => true, 'overflowed' => $overflowed];
            }
            // Sampled BEFORE the reads: anything the child wrote before it
            // exited is already in the pipe, so an empty pass after an exit
            // seen here means the child has nothing more to say.
            if ($exitedAt === null && !$running()) {
                $exitedAt = $now;
            }

            $got = false;
            foreach ($pipes as $slot => $pipe) {
                $chunk = self::readAvailable($pipe);
                if ($chunk === '') {
                    continue;
                }
                $got = true;
                $room = $maxBytes - \strlen($buffers[$slot]);
                if ($room > 0) {
                    $buffers[$slot] .= \substr($chunk, 0, $room);
                }
                if (\strlen($chunk) > \max(0, $room)) {
                    $overflowed = true;
                }
            }

            if ($exitedAt !== null && (!$got || $now - $exitedAt >= self::EXIT_DRAIN_GRACE_SECONDS)) {
                return ['buffers' => $buffers, 'timedOut' => false, 'overflowed' => $overflowed];
            }
            if (!$got) {
                \usleep(\max(1, \min(self::POLL_MICROSECONDS, (int) \ceil(($deadline - $now) * 1_000_000))));
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * Every `pcntl_fork()`'d child in this codebase (see
 * {@see \SugarCraft\Crush\Backend\EngineBackend::runCompleteInChild()},
 * {@see \SugarCraft\Crush\Chat::forkToolCalls()}) MUST end with
 * {@see exitNow()} instead of a plain `exit()`.
 *
 * A forked child inherits a full copy of the parent's PHP object graph -
 * including, when running under `bin/sugarcrush`, candy-core's `Tty`/
 * `PosixBackend` object that put the real terminal into raw mode (it holds
 * the ORIGINAL, pre-raw-mode termios as `$saved`, ready to `restore()` it).
 * Termios settings live on the shared kernel TTY device, not per-process -
 * a plain `exit()` runs PHP's normal shutdown sequence, which destructs
 * every object still reachable in the child, including that inherited `Tty`,
 * whose destructor calls `restore()` and puts the REAL, shared terminal back
 * into cooked/echo mode. The parent process (and the user) sees the whole
 * TUI's raw mode silently die the instant the first backend call or tool
 * call round-trips - keystrokes get real terminal-echoed wherever the
 * cursor last was (looks exactly like "text ends up in the status bar"),
 * and canonical-mode line buffering means Enter/Ctrl+P etc. stop reaching
 * the program's own input parser correctly.
 *
 * {@see exitNow()} kills the process via `SIGKILL` on itself, bypassing
 * PHP's shutdown sequence (no destructors, no register_shutdown_function
 * callbacks) entirely - the standard fix for exactly this class of bug in
 * any forked PHP worker that inherits live OS-level resource state. Falls
 * back to a plain `exit()` only when posix functions are unavailable
 * (mirrors every other `function_exists('posix_kill')` guard already used
 * around forked children elsewhere in this codebase).
 */
final class ForkedChild
{
    /**
     * The server sockets a forked child must not keep: the `serve` listener
     * and every accepted connection, keyed by PHP resource id.
     *
     * WHY A REGISTRY AND NOT A SWEEP (o0-spikes (c)). A turn child forked from
     * `sugarcrush serve` inherits the listener and every browser socket. Held
     * there, the listener keeps the port bound after the server closes it (a
     * restart fails with "Address already in use") and still ACCEPTS into a
     * backlog nobody reads, so a client of the dead server hangs instead of
     * being refused. A blanket close of every `socket:`/`pipe:` descriptor is
     * not the fix: the child legitimately uses inherited pipes (stdio MCP
     * servers started in the parent) and possibly provider sockets. So the
     * server names exactly what is its own, and only that is closed.
     *
     * Empty in every process that never ran `serve` — the TUI and `-p` pay one
     * `=== []` per fork.
     *
     * @var array<int, resource>
     */
    private static array $serverStreams = [];

    /**
     * Name $stream as a server descriptor every later fork must close. Called
     * by the server for its listener and each connection it accepts.
     *
     * @param resource $stream
     */
    public static function registerServerStream(mixed $stream): void
    {
        if (\is_resource($stream)) {
            self::$serverStreams[(int) $stream] = $stream;
        }
    }

    /**
     * Forget $stream: the server closed it, so its descriptor number may be
     * reused by something the child legitimately needs.
     *
     * @param resource|mixed $stream
     */
    public static function forgetServerStream(mixed $stream): void
    {
        if (\is_resource($stream) || \gettype($stream) === 'resource (closed)') {
            unset(self::$serverStreams[(int) $stream]);
        }
    }

    /**
     * How many server descriptors are registered (the server's own
     * accounting, and what tests assert a close forgot).
     */
    public static function registeredServerStreams(): int
    {
        return \count(self::$serverStreams);
    }

    /**
     * Close, in a freshly forked child, every descriptor the server
     * registered; returns how many were closed.
     *
     * THE FIRST THING A TURN CHILD DOES (Appendix O §4.4). PHP has no
     * close-by-number and `php://fd/N` dups, and an `fclose()` here would be
     * the PHP resource, not necessarily the inherited descriptor the kernel
     * counts — so each stream's fd is found by dev+ino match in
     * `/proc/self/fd` (the technique `ProcessContainment::closeOnExec()` uses)
     * and closed with libc `close(2)` through FFI. Every match is closed: two
     * descriptors on one server socket are both the server's.
     *
     * The registry is then emptied, so a grandchild forked from this child
     * does no second pass over numbers that may since have been reused.
     * Best effort and SILENT, like `closeOnExec()`: this runs on the fork path,
     * where a warning or a throw would be worse than the leak. `serve` refuses
     * to start without ext-ffi, so on a server the close is not optional in
     * practice.
     */
    public static function closeInheritedServerFds(): int
    {
        if (self::$serverStreams === []) {
            return 0;
        }

        $streams = self::$serverStreams;
        self::$serverStreams = [];
        if (!\extension_loaded('ffi') || !\is_dir('/proc/self/fd')) {
            return 0;
        }

        $wanted = [];
        foreach ($streams as $stream) {
            $stat = \is_resource($stream) ? @\fstat($stream) : false;
            if (\is_array($stat) && ($stat['ino'] ?? 0) !== 0) {
                $wanted[$stat['dev'] . ':' . $stat['ino']] = true;
            }
        }
        if ($wanted === []) {
            return 0;
        }

        try {
            $libc = \FFI::cdef('int close(int fd);');
        } catch (\Throwable) {
            return 0;
        }

        $closed = 0;
        foreach (@\scandir('/proc/self/fd') ?: [] as $entry) {
            if (!\ctype_digit($entry) || (int) $entry <= 2) {
                continue;
            }
            $stat = @\stat('/proc/self/fd/' . $entry);
            if (\is_array($stat) && isset($wanted[$stat['dev'] . ':' . $stat['ino']]) && $libc->close((int) $entry) === 0) {
                ++$closed;
            }
        }

        return $closed;
    }

    public static function exitNow(int $code = 0): never
    {
        if (\function_exists('posix_kill') && \function_exists('posix_getpid') && \defined('SIGKILL')) {
            @\posix_kill(\posix_getpid(), \SIGKILL);
        }
        exit($code);
    }
}

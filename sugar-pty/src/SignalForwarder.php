<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use SugarCraft\Pty\Concerns\LibcAccess;
use SugarCraft\Pty\Contract\MasterPty;

/**
 * Forwards host-side `SIGWINCH` (terminal resize) and optionally
 * `SIGCHLD` (child termination) into PTY-aware callbacks.
 *
 * Requires `ext-pcntl`. When pcntl is missing, `attach*()` methods
 * return `false` cleanly so callers can fall back to polling.
 *
 * Mirrors charmbracelet/x/xpty's `SignalForwarder` Go type — the
 * resize handler mirrors `signal.Notify(c, syscall.SIGWINCH)` plus
 * a goroutine that calls `pty.SetSize(GetWinsize())`.
 *
 * ## Async vs sync dispatch
 *
 * By default `SignalForwarder` flips `pcntl_async_signals(true)` so
 * handlers fire as soon as PHP gets control between opcodes. Callers
 * already running their own event loop with `pcntl_signal_dispatch()`
 * polling can pass `async: false` to keep dispatch sync.
 *
 * ## Lifecycle in long-lived processes
 *
 * POSIX signal dispositions are process-global, so this facade is
 * deliberately static — an "instance" would only be a fiction over
 * shared kernel state. The cost is that installed handlers and the
 * async-dispatch memo survive for the whole process: PHP-FPM workers,
 * ReactPHP daemons, and test suites that span many logical sessions
 * would leak one session's handlers into the next. Call {@see reset()}
 * with no arguments at session teardown to detach every handler this
 * class installed and undo `pcntl_async_signals(true)` if this class
 * enabled it.
 */
final class SignalForwarder
{
    use LibcAccess;

    /** True once THIS class called `pcntl_async_signals(true)`. */
    private static bool $asyncEnabled = false;

    /**
     * Signal numbers with a handler currently installed by this class,
     * keyed by signo. Lets a no-argument {@see reset()} detach exactly
     * what was attached without guessing at dispositions owned by
     * other code in the process.
     *
     * @var array<int, true>
     */
    private static array $attached = [];

    /**
     * Variant of {@see attachSigwinch()} that targets a raw file
     * descriptor instead of a `MasterPty`. Calls
     * `SizeIoctl::setSizeViaLibc()` directly so callers holding a
     * plain `/dev/tty` fd can forward resize events without a PTY.
     *
     * `$sizeProvider` returns `array{cols:int, rows:int}`. Any
     * exception it throws is swallowed (signal handlers must not
     * propagate exceptions across the runtime).
     *
     * @param callable(): array{cols:int, rows:int} $sizeProvider
     * @param callable(int, int): void|null $onResize if provided,
     *               called with `(cols, rows)` after a successful resize
     * @return bool true if the handler installed; false on platforms
     *              without pcntl or `SIGWINCH`.
     * @see attachSigwinch()
     */
    public static function attachSigwinchToFd(int $fd, callable $sizeProvider, ?callable $onResize = null, bool $async = true): bool
    {
        if (!self::pcntlReady() || !\defined('SIGWINCH')) {
            return false;
        }

        $handler = static function (int $signo) use ($fd, $sizeProvider, $onResize): void {
            try {
                $size = $sizeProvider();
                $cols = (int) $size['cols'];
                $rows = (int) $size['rows'];
                $ws = \SugarCraft\Pty\SizeIoctl::pack($rows, $cols);
                $rc = \SugarCraft\Pty\SizeIoctl::setSizeViaLibc(self::libc(), $fd, $ws);
                if ($rc === 0 && $onResize !== null) {
                    $onResize($cols, $rows);
                }
            } catch (\Throwable) {
                // Signal handlers must not throw — best-effort only.
            }
        };

        if (!@\pcntl_signal(SIGWINCH, $handler)) {
            return false;
        }
        self::$attached[SIGWINCH] = true;
        self::ensureAsync($async);
        return true;
    }

    /**
     * Install a `SIGWINCH` handler that calls
     * `$sizeProvider()` and pipes the returned `[cols, rows]` into
     * `$pty->resize()`.
     *
     * `$sizeProvider` returns `array{cols:int, rows:int}` — typically
     * a thin wrapper over candy-core's `Util\Tty::size()`. Any
     * exception it throws is swallowed (signal handlers must not
     * propagate exceptions across the runtime).
     *
     * @param callable(): array{cols:int, rows:int} $sizeProvider
     * @return bool true if the handler installed; false on platforms
     *              without pcntl or `SIGWINCH`.
     * @see creack/pty.InheritSize()
     */
    public static function attachSigwinch(MasterPty $master, callable $sizeProvider, bool $async = true): bool
    {
        if (!self::pcntlReady() || !\defined('SIGWINCH')) {
            return false;
        }

        $handler = static function (int $signo) use ($master, $sizeProvider): void {
            if ($master->isClosed()) {
                return;
            }
            try {
                $size = $sizeProvider();
                $master->resize((int) $size['cols'], (int) $size['rows']);
            } catch (\Throwable) {
                // Signal handlers must not throw — best-effort only.
            }
        };

        if (!@\pcntl_signal(SIGWINCH, $handler)) {
            return false;
        }
        self::$attached[SIGWINCH] = true;
        self::ensureAsync($async);
        return true;
    }

    /**
     * Install a `SIGCHLD` handler that calls `$reaper()` whenever
     * any child terminates. The reaper typically iterates known
     * {@see Child} instances and probes `proc_get_status()` on each.
     *
     * @param callable(): void $reaper
     */
    public static function attachSigchld(callable $reaper, bool $async = true): bool
    {
        if (!self::pcntlReady() || !\defined('SIGCHLD')) {
            return false;
        }

        $handler = static function (int $signo) use ($reaper): void {
            try {
                $reaper();
            } catch (\Throwable) {
                // Signal handlers must not throw.
            }
        };

        if (!@\pcntl_signal(SIGCHLD, $handler)) {
            return false;
        }
        self::$attached[SIGCHLD] = true;
        self::ensureAsync($async);
        return true;
    }

    /**
     * Pump pending signals through to their handlers when async mode
     * is off. No-op if pcntl is missing.
     */
    public static function dispatch(): void
    {
        if (\function_exists('pcntl_signal_dispatch')) {
            @\pcntl_signal_dispatch();
        }
    }

    /**
     * Restore the default disposition for one or more signals — or,
     * called with NO arguments, perform a full lifecycle reset: detach
     * every handler this class installed and clear all static state.
     *
     * The full reset also flips `pcntl_async_signals(false)`, but only
     * when this class was the one to enable async dispatch — turning
     * it off unconditionally could break handlers installed by other
     * libraries in the same process. Targeted resets never touch the
     * async flag: other handlers may still rely on it.
     *
     * Long-lived processes (PHP-FPM workers, ReactPHP daemons, test
     * suites) should call `reset()` between logical sessions so one
     * session's handlers and async mode don't leak into the next.
     */
    public static function reset(int ...$signals): void
    {
        if (!self::pcntlReady()) {
            return;
        }

        if ($signals === []) {
            $signals = \array_keys(self::$attached);
            if (self::$asyncEnabled && \function_exists('pcntl_async_signals')) {
                \pcntl_async_signals(false);
            }
            self::$asyncEnabled = false;
        }

        foreach ($signals as $signo) {
            @\pcntl_signal($signo, \SIG_DFL);
            unset(self::$attached[$signo]);
        }
    }

    /**
     * Signal numbers with a handler currently installed by this class.
     * Diagnostic companion to {@see reset()}.
     *
     * @return list<int>
     */
    public static function attachedSignals(): array
    {
        return \array_keys(self::$attached);
    }

    /**
     * True while this class has `pcntl_async_signals(true)` in effect.
     */
    public static function asyncEnabled(): bool
    {
        return self::$asyncEnabled;
    }

    /**
     * `true` if the host has `ext-pcntl` and the signal-handling
     * primitives needed to install handlers.
     */
    public static function pcntlReady(): bool
    {
        return \function_exists('pcntl_signal')
            && \function_exists('pcntl_signal_dispatch');
    }

    private static function ensureAsync(bool $async): void
    {
        if (!$async || self::$asyncEnabled) {
            return;
        }
        if (\function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(true);
            self::$asyncEnabled = true;
        }
    }

    private function __construct() {}
}

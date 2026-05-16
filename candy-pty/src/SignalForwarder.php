<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

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
 */
final class SignalForwarder
{
    /** True once `pcntl_async_signals(true)` has been called. */
    private static bool $asyncEnabled = false;

    /**
     * Install a `SIGWINCH` handler that, on receipt, calls
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
     * Restore the default disposition for one or more signals.
     * Useful in test teardown to avoid handler bleed between tests.
     */
    public static function reset(int ...$signals): void
    {
        if (!self::pcntlReady()) {
            return;
        }
        foreach ($signals as $signo) {
            @\pcntl_signal($signo, \SIG_DFL);
        }
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

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli\Serve;

/**
 * A synchronous SIGTERM/SIGINT backstop for the foreground serve.
 *
 * The drain contract (`server.shutdown`, close 1001, then removal) rides on
 * the ReactPHP loop's signal dispatch, which only runs once the loop does:
 * a signal landing between the discovery record being written and
 * `Loop::addSignal` (and a loop blocked in synchronous work) would die on the
 * default disposition and leave `server.json` and the control socket behind,
 * so `status` would report a server that is gone. While armed, the signal
 * runs the residue cleanup right there, then re-raises under SIG_DFL, so the
 * exit shape stays the conventional signal death (143 for TERM).
 *
 * WHAT IS GUARANTEED. Residue removal (record, control socket, lock) for any
 * signal that arrives while this guard is armed. The client-facing half
 * (`server.shutdown` frame, 1001 closes) is guaranteed only when the loop's
 * own drain handler gets to run; the guard never tries to send frames.
 *
 * {@see disarm()} hands the signals back WITHOUT touching the handlers: the
 * loop's `addSignal` replaces them first, and resetting them here would
 * clobber the loop's.
 */
final class ServeResidueGuard
{
    private static ?bool $previousAsync = null;

    /**
     * @param \Closure():void $cleanup best-effort and idempotent; throwables are swallowed
     */
    public static function arm(\Closure $cleanup): void
    {
        if (!\function_exists('pcntl_signal') || !\function_exists('posix_kill') || self::$previousAsync !== null) {
            return;
        }
        self::$previousAsync = \pcntl_async_signals(true);
        foreach ([\SIGINT, \SIGTERM] as $signal) {
            \pcntl_signal($signal, static function (int $received) use ($cleanup): void {
                try {
                    $cleanup();
                } catch (\Throwable) {
                    // Dying anyway; a failed unlink must not block the re-raise.
                }
                \pcntl_signal($received, \SIG_DFL);
                \posix_kill(\getmypid(), $received);
            });
        }
    }

    public static function disarm(): void
    {
        if (self::$previousAsync === null) {
            return;
        }
        \pcntl_async_signals(self::$previousAsync);
        self::$previousAsync = null;
    }
}

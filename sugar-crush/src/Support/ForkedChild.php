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
    public static function exitNow(int $code = 0): never
    {
        if (\function_exists('posix_kill') && \function_exists('posix_getpid') && \defined('SIGKILL')) {
            @\posix_kill(\posix_getpid(), \SIGKILL);
        }
        exit($code);
    }
}

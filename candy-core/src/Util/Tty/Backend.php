<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Tty;

/**
 * Platform-specific TTY backend contract.
 *
 * Each concrete backend (PosixBackend, WindowsBackend) wraps the native
 * TTY primitives for its platform.  The façade ({@see \SugarCraft\Core\Util\Tty})
 * delegates to the appropriate backend at construction time so that all
 * public API methods remain platform-agnostic.
 */
interface Backend
{
    /**
     * drainSignals() return: a Ctrl+C (or equivalent) interrupt was received.
     */
    public const SIGNAL_INTERRUPT = 1;

    /**
     * drainSignals() return: the terminal was resized.
     */
    public const SIGNAL_RESIZE = 2;

    /**
     * Returns true when the wrapped stream is a terminal device.
     */
    public function isTty(): bool;

    /**
     * Open the controlling terminal directly (`/dev/tty` on POSIX,
     * `CONIN$`/`CONOUT$` on Windows).  This allows a program to read
     * keys even when stdin is redirected from a file or another process.
     *
     * Returns `[input, output]` handles on success, or `null` when the
     * controlling terminal cannot be opened (sandboxed or non-existent).
     *
     * @return array{0:resource,1:resource}|null
     */
    public static function openTty(): ?array;

    /**
     * Returns the current terminal dimensions as `[cols, rows]`.
     *
     * @return array{cols:int, rows:int}
     */
    public function size(): array;

    /**
     * Put the terminal into raw mode (no line buffering, no echo).
     * Backends that do not support raw mode are no-ops.
     */
    public function enableRawMode(): void;

    /**
     * Restore the terminal to its pre-{@see enableRawMode()} state.
     * Safe to call even when raw mode was never enabled.
     */
    public function restore(): void;

    /**
     * Register a callback to be invoked whenever the terminal is resized.
     * Returns `true` when the handler was installed, `false` when the
     * platform does not support resize signalling.
     *
     * The callback receives `(int $cols, int $rows)`.
     */
    public static function onResize(\Closure $onResize): bool;

    /**
     * Drain any pending resize or interrupt signals.
     *
     * Call once per event-loop tick.  Returns a bitmask of the signals
     * that were dispatched (zero or more of SIGNAL_INTERRUPT |
     * SIGNAL_RESIZE), or `false` when the platform does not support
     * signal draining (should not happen on Windows or POSIX).
     *
     * @return int|false bitmask of dispatched signals, or false on error
     */
    public static function drainSignals(): int|false;
}

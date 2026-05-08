<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Tty;

/**
 * Windows FFI-based TTY backend.
 *
 * Uses kernel32.dll FFI to query and manipulate the Windows console.
 * This backend targets Windows 10 1809+ (Windows Terminal or modern
 * ConHost) which supports Virtual Terminal processing.
 *
 * ## Supported in this slice (PR1)
 *
 * - `isTty()` — detects whether the stream is a console handle.
 * - `size()`   — queries the window dimensions via `GetConsoleScreenBufferInfo`.
 *
 * The following are stub no-ops in this slice and will be wired up
 * in subsequent slices:
 *
 * - `openTty()`           — PR5: `CONIN$`/`CONOUT$` via `CreateFileW`
 * - `enableRawMode()`     — PR2: VT input + raw mode flags
 * - `restore()`           — PR2: restore saved handle modes
 * - `onResize()`          — PR3: resize-poll loop
 * - `drainSignals()`      — PR3: resize-poll loop
 *
 * @see \SugarCraft\Core\Util\Tty\Kernel32
 */
final class WindowsBackend implements Backend
{
    /** @var resource */
    private $stream;

    /** @param resource|null $stream defaults to STDIN */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? STDIN;
    }

    public function isTty(): bool
    {
        if (!is_resource($this->stream)) {
            return false;
        }

        if (!stream_isatty($this->stream)) {
            return false;
        }

        // Verify GetStdHandle returns a real handle (not INVALID_HANDLE_VALUE).
        // Guard against non-Windows platforms where kernel32.dll does not exist.
        try {
            $h = Kernel32::stdIn();
            $ptrVal = \FFI::cast('intptr_t', $h)->cdata;
            return $ptrVal !== -1 && $ptrVal !== 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{0:resource,1:resource}|null
     */
    public static function openTty(): ?array
    {
        // Implemented in PR5: CONIN$/CONOUT$ via CreateFileW.
        return null;
    }

    /** @return array{cols:int, rows:int} */
    public function size(): array
    {
        try {
            $info = Kernel32::getConsoleScreenBufferInfo(Kernel32::stdOut());
            if ($info !== null) {
                return $info;
            }
        } catch (\Throwable) {
            // Fall through to fallback.
        }

        return ['cols' => 80, 'rows' => 24];
    }

    public function enableRawMode(): void
    {
        // PR2: set VT_INPUT | clear LINE_INPUT | ECHO_INPUT | PROCESSED_INPUT.
    }

    public function restore(): void
    {
        // PR2: restore saved mode flags.
    }

    public static function onResize(\Closure $_onResize): bool
    {
        // PR3: register resize-poll callback.
        return false;
    }

    public static function drainSignals(): bool
    {
        // PR3: poll GetConsoleScreenBufferInfo, fire callback on change.
        return false;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Tty;

/**
 * Output-side console-mode negotiation for native Windows consoles.
 *
 * ## Why this exists (campaign finding A-10)
 *
 * Every SGR-blob emitter in the monorepo writes raw bytes to STDOUT and
 * nobody ever asks the Windows console whether it will interpret them.
 * Legacy conhost only honours VT escapes once ENABLE_VIRTUAL_TERMINAL_
 * PROCESSING is armed on the output handle; without it, colour output
 * arrives as literal escape text. Chafa's stream writer negotiates exactly
 * this flag with a save/restore bracket around its whole write loop —
 * win32_stream_writer_init saves the prior mode via GetConsoleMode and
 * sets ENABLE_PROCESSED_OUTPUT|ENABLE_VIRTUAL_TERMINAL_PROCESSING|
 * ENABLE_WRAP_AT_EOL_OUTPUT (chafa-stream-writer.c:83-105), and
 * win32_stream_writer_deinit restores the saved mode (:107-111). This
 * class is the same bracket, expressed against the house
 * {@see Kernel32Interface} so the semantics are testable on Linux through
 * the scripted fake, exactly where sugar-core's TTY-side console handling
 * already lives (next to {@see WindowsBackend}).
 *
 * ## Scope decisions (kept tight per plan S3.5)
 *
 *  - Only ENABLE_VIRTUAL_TERMINAL_PROCESSING is OR'd into the live mode.
 *    Chafa replaces the mode with a wholesale literal; OR-ing preserves
 *    whatever else the host console (or a sibling component) already
 *    negotiated and cannot drop a flag something else armed.
 *  - DISABLE_NEWLINE_AUTO_RETURN is deliberately NOT touched: chafa does
 *    not use it, and clearing newline auto-return would strand every
 *    bare-LF writer in the tree mid-line unless they all migrated to
 *    explicit CRLF.
 *  - Chafa's legacy-fallback branch (SetConsoleMode of the non-VT mask on
 *    failure, :96-104) exists to license its WriteConsoleA path; this
 *    class reports capability only and has no write loop to license, so a
 *    rejected enable simply returns false.
 *
 * ## Degradation contract
 *
 * Off Windows the real bindings must never load: {@see isSupported()} is
 * the caller's gate, and constructing without an injected
 * {@see Kernel32Interface} fails LOUD (LogicException) rather than
 * silently no-op'ing. A test double may always be injected (house
 * FakeKernel32 pattern) — that is how the negotiation semantics below
 * are pinned on Linux CI.
 *
 * ## Deferred seam (W3 ruling, recorded honestly)
 *
 * Production wiring is NOT part of this step: the application output
 * bootstrap calling enable() at startup and consulting vtEnabled() as the
 * capability gate for sugar-image's REP run-encoder (campaign A-02, the
 * consumer that needs "VT negotiated? else do not emit REP") is deferred
 * to a later wave per plan_chafa.md §3 S3.5.
 *
 * Idempotency: enable() consults the live mode only on its first call;
 * afterwards it answers from instance state, so a second enable never
 * re-saves (a re-save of the already-VT-enabled mode would poison
 * restore() into leaving VT on forever).
 */
final class WindowsConsole
{
    /** Resolved lazily so an injected handle (or fake) never needs stdOut(). */
    private ?int $handle;

    /** Prior mode captured by the enable() that changed the console, null when nothing was changed. */
    private ?int $savedMode = null;

    /** VT processing is currently believed armed as far as this instance's negotiation knows. */
    private bool $enabled = false;

    private Kernel32Interface $kernel32;

    /**
     * @param Kernel32Interface|null $kernel32 injectable seam (house test-double pattern);
     *                                         defaults to the real FFI bindings, which
     *                                         requires {@see isSupported()}
     * @param int|null               $handle   console handle to negotiate, defaults to
     *                                         the resolved STD_OUTPUT_HANDLE on first use
     */
    public function __construct(?Kernel32Interface $kernel32 = null, ?int $handle = null)
    {
        if ($kernel32 === null && !self::isSupported()) {
            throw new \LogicException(
                'WindowsConsole drives kernel32.dll through FFI and only exists on native Windows;'
                . ' gate callers with WindowsConsole::isSupported(), or inject a Kernel32Interface seam.',
            );
        }

        $this->kernel32 = $kernel32 ?? Kernel32::self();
        $this->handle   = $handle;
    }

    /**
     * True when this process could drive the Windows console-mode API.
     *
     * MSYS2/Cygwin/WSL PHP are POSIX binaries and answer false — their
     * terminals are the POSIX layer's problem, mirroring EnvDetect's
     * WSL-before-Windows ordering for the backend selector.
     */
    public static function isSupported(): bool
    {
        return \PHP_OS_FAMILY === 'Windows' && \extension_loaded('ffi');
    }

    /**
     * OR ENABLE_VIRTUAL_TERMINAL_PROCESSING into the console output mode.
     *
     * @return bool true when VT processing is armed (now negotiated by us,
     *               or already armed before we looked); false when the
     *               handle is not a console (redirected output — chafa's
     *               ERROR_INVALID_HANDLE shape) or the console refused the
     *               flag (legacy Windows without VT). A false answer leaves
     *               no saved state behind.
     */
    public function enable(): bool
    {
        if ($this->enabled) {
            // Idempotent: never re-read, never re-save.
            return true;
        }

        $handle = $this->handle ??= $this->kernel32->stdOut();
        $prior  = $this->kernel32->getConsoleMode($handle);

        if ($prior === false) {
            return false;
        }

        if (($prior & Kernel32Interface::ENABLE_VIRTUAL_TERMINAL_PROCESSING) !== 0) {
            // Already armed by the host (modern Windows Terminal defaults this on):
            // nothing changed, nothing to restore.
            $this->enabled = true;

            return true;
        }

        if (!$this->kernel32->setConsoleMode(
            $handle,
            $prior | Kernel32Interface::ENABLE_VIRTUAL_TERMINAL_PROCESSING,
        )) {
            // Failed set leaves the console exactly as found: nothing was saved,
            // so restore() stays a no-op and a retry re-reads the live mode.
            return false;
        }

        $this->savedMode = $prior;
        $this->enabled   = true;

        return true;
    }

    /**
     * Return the console to the mode captured by the enable() that changed it.
     *
     * Mirrors chafa's win32_stream_writer_deinit (chafa-stream-writer.c:107-111).
     *
     * @return bool true when the console now holds the pre-enable mode (or
     *               when enable() never changed it); false only when the
     *               restore write itself failed — saved state is kept so a
     *               retry can succeed.
     */
    public function restore(): bool
    {
        if ($this->savedMode === null) {
            // Pristine, or an enable() that found VT already armed: no write owed.
            $this->enabled = false;

            return true;
        }

        $handle = $this->handle ??= $this->kernel32->stdOut();

        if (!$this->kernel32->setConsoleMode($handle, $this->savedMode)) {
            return false;
        }

        $this->savedMode = null;
        $this->enabled   = false;

        return true;
    }

    /** True while this instance's negotiation believes VT processing is armed. */
    public function vtEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The prior mode captured by a state-changing enable(), null when this
     * instance has not modified the console. Bare accessor; exposed so
     * consumers (and pins) can see there is exactly one saved generation.
     */
    public function savedMode(): ?int
    {
        return $this->savedMode;
    }
}

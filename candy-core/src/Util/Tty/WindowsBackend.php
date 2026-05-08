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
 * ## Implemented slices
 *
 * - `isTty()`         — PR1: detects whether the stream is a console handle.
 * - `size()`          — PR1: queries the window dimensions via `GetConsoleScreenBufferInfo`.
 * - `enableRawMode()` — PR2: captures modes, sets VT raw mode, sets UTF-8 codepage.
 * - `restore()`       — PR2: restores all captured modes and codepage.
 * - `onResize()`      — PR3: registers a resize callback.
 * - `drainSignals()`  — PR3: polls `GetConsoleScreenBufferInfo` each tick;
 *                        fires the callback when window dimensions change.
 *
 * The following are stub no-ops in this slice and will be wired up
 * in subsequent slices:
 *
 * - `openTty()` — PR5: `CONIN$`/`CONOUT$` via `CreateFileW`
 *
 * ## Resize signalling design
 *
 * Windows has no `SIGWINCH` equivalent.  The resize-poll loop calls
 * `GetConsoleScreenBufferInfo(stdout)` once per {@see drainSignals()}
 * invocation (i.e. once per event-loop tick) and compares the window
 * rect against the last known size.  When the dimensions differ, the
 * registered callback is fired with the new `(cols, rows)`.
 *
 * The poll frequency is therefore one check per tick — the same
 * granularity as POSIX `pcntl_signal(SIGWINCH)`.
 *
 * @see \SugarCraft\Core\Util\Tty\Kernel32
 * @see \SugarCraft\Core\Util\Tty\Kernel32Interface
 */
final class WindowsBackend implements Backend
{
    private const MASK_CLEAR_INPUT = ~(
        Kernel32Interface::ENABLE_PROCESSED_INPUT
        | Kernel32Interface::ENABLE_LINE_INPUT
        | Kernel32Interface::ENABLE_ECHO_INPUT
    );

    /** @var resource */
    private $stream;

    /** @var Kernel32Interface */
    private Kernel32Interface $kernel32;

    // ─── Raw-mode state ──────────────────────────────────────────────────────

    /** Saved input mode (null when raw mode is not active). */
    private int|null $savedInputMode = null;

    /** Saved output mode (null when raw mode is not active). */
    private int|null $savedOutputMode = null;

    /** Saved input codepage. */
    private int|null $savedInputCp = null;

    /** Saved output codepage. */
    private int|null $savedOutputCp = null;

    // ─── Resize-signalling state ─────────────────────────────────────────────

    /**
     * Registered resize callback, or null when none is active.
     *
     * Stored as a static so both {@see onResize()} (static façade) and
     * {@see drainSignalsInstance()} share the same reference without
     * needing a shared instance reference.
     *
     * @var (\Closure(int $cols, int $rows):void)|null
     */
    private static ?\Closure $resizeCallback = null;

    /**
     * Last observed dimensions, or null before the first poll.
     *
     * @var array{cols:int, rows:int}|null
     */
    private static ?array $resizeLastSize = null;

    /**
     * Injected Kernel32 instance for testing.
     * When set (via {@see setTestKernel32()}), drainSignals() uses this
     * instead of the real Kernel32 singleton.  Do not use in production.
     *
     * @var Kernel32Interface|null
     */
    private static ?Kernel32Interface $testKernel32 = null;

    // ─── Constructor ────────────────────────────────────────────────────────

    /**
     * @param resource|null          $stream   defaults to STDIN
     * @param Kernel32Interface|null $kernel32 defaults to real kernel32; pass a test double on Linux
     */
    public function __construct($stream = null, ?Kernel32Interface $kernel32 = null)
    {
        $this->stream   = $stream ?? STDIN;
        $this->kernel32 = $kernel32 ?? Kernel32::self();
    }

    // ─── TTY detection ───────────────────────────────────────────────────────

    public function isTty(): bool
    {
        if (!is_resource($this->stream)) {
            return false;
        }

        if (!stream_isatty($this->stream)) {
            return false;
        }

        try {
            $h = $this->kernel32->stdIn();

            return $h !== -1 && $h !== 0;
        } catch (\Throwable) {
            return false;
        }
    }

    // ─── Controlling terminal ────────────────────────────────────────────────

    /**
     * @return array{0:resource,1:resource}|null
     */
    public static function openTty(): ?array
    {
        // Implemented in PR5: CONIN$/CONOUT$ via CreateFileW.
        return null;
    }

    // ─── Dimensions ──────────────────────────────────────────────────────────

    /** @return array{cols:int, rows:int} */
    public function size(): array
    {
        try {
            $info = $this->kernel32->getConsoleScreenBufferInfo(
                $this->kernel32->stdOut(),
            );
            if ($info !== null) {
                return $info;
            }
        } catch (\Throwable) {
            // Fall through to fallback.
        }

        return ['cols' => 80, 'rows' => 24];
    }

    // ─── Raw mode ────────────────────────────────────────────────────────────

    public function enableRawMode(): void
    {
        if ($this->savedInputMode !== null) {
            return; // Idempotent: already in raw mode.
        }

        try {
            $stdin  = $this->kernel32->stdIn();
            $stdout = $this->kernel32->stdOut();

            // Capture current modes and codepages for restore().
            $this->savedInputMode  = $this->kernel32->getConsoleMode($stdin) ?? 0;
            $this->savedOutputMode = $this->kernel32->getConsoleMode($stdout) ?? 0;
            $this->savedInputCp    = $this->kernel32->getConsoleCP();
            $this->savedOutputCp   = $this->kernel32->getConsoleOutputCP();

            // Build raw input mode:
            //   clear  ENABLE_PROCESSED_INPUT  (cooked line editing)
            //   clear  ENABLE_LINE_INPUT       (wait for Enter per line)
            //   clear  ENABLE_ECHO_INPUT       (no auto-echo)
            //   set    ENABLE_VIRTUAL_TERMINAL_INPUT  (xterm-style key sequences)
            //   set    ENABLE_WINDOW_INPUT     (passthru resize events)
            $rawInput = ($this->savedInputMode & self::MASK_CLEAR_INPUT)
                | Kernel32Interface::ENABLE_VIRTUAL_TERMINAL_INPUT
                | Kernel32Interface::ENABLE_WINDOW_INPUT;

            // Build raw output mode:
            //   set    ENABLE_PROCESSED_OUTPUT            (handle ANSI internally)
            //   set    ENABLE_VIRTUAL_TERMINAL_PROCESSING (ANSI passthru)
            //   set    DISABLE_NEWLINE_AUTO_RETURN        (prevents double linefeed)
            $rawOutput = $this->savedOutputMode
                | Kernel32Interface::ENABLE_PROCESSED_OUTPUT
                | Kernel32Interface::ENABLE_VIRTUAL_TERMINAL_PROCESSING
                | Kernel32Interface::DISABLE_NEWLINE_AUTO_RETURN;

            $this->kernel32->setConsoleMode($stdin,  $rawInput);
            $this->kernel32->setConsoleMode($stdout, $rawOutput);

            // Switch console to UTF-8 so PHP multibyte strings map correctly
            // to the Windows console without requiring a BOM in output.
            $this->kernel32->setConsoleCP(65001);
            $this->kernel32->setConsoleOutputCP(65001);

            // Defensive shutdown guard (caveat 8): if PHP crashes without
            // calling restore(), the user's cmd.exe is left in UTF-8 mode.
            static $registered = false;
            if (!$registered) {
                $registered = true;
                register_shutdown_function([$this, 'restore']);
            }
        } catch (\Throwable) {
            // If anything fails during setup, leave console in original state.
            $this->savedInputMode  = null;
            $this->savedOutputMode = null;
            $this->savedInputCp    = null;
            $this->savedOutputCp   = null;
        }
    }

    public function restore(): void
    {
        if ($this->savedInputMode === null) {
            return; // Nothing to restore.
        }

        try {
            $stdin  = $this->kernel32->stdIn();
            $stdout = $this->kernel32->stdOut();

            $this->kernel32->setConsoleMode($stdin,  (int) $this->savedInputMode);
            $this->kernel32->setConsoleMode($stdout, (int) $this->savedOutputMode);
            $this->kernel32->setConsoleCP((int) $this->savedInputCp);
            $this->kernel32->setConsoleOutputCP((int) $this->savedOutputCp);
        } catch (\Throwable) {
            // Best-effort; nothing safe to do if restore fails.
        } finally {
            $this->savedInputMode  = null;
            $this->savedOutputMode = null;
            $this->savedInputCp    = null;
            $this->savedOutputCp   = null;
        }
    }

    public function __destruct()
    {
        $this->restore();
    }

    // ─── Resize signalling (PR3) ─────────────────────────────────────────────

    /**
     * Register a callback to be invoked whenever the terminal is resized.
     *
     * Windows has no SIGWINCH equivalent; this implementation uses a
     * poll loop: {@see drainSignals()} must be called once per event-loop
     * tick for resize detection to work.
     *
     * Only one callback can be active at a time.  Calling this a second
     * time replaces the previously registered callback.
     *
     * @param \Closure(int $cols, int $rows):void $onResize
     * @return bool true (Windows always supports polling-based resize detection)
     */
    public static function onResize(\Closure $onResize): bool
    {
        self::$resizeCallback = $onResize;

        return true;
    }

    /**
     * Drain any pending resize signals.
     *
     * On Windows this polls `GetConsoleScreenBufferInfo(stdout)` once,
     * compares the window rect against the last observed size, and fires
     * the registered callback when the dimensions differ.
     *
     * Call this exactly once per event-loop tick.
     *
     * @return bool true when a resize was detected and the callback fired
     */
    public static function drainSignals(): bool
    {
        $cb = self::$resizeCallback;

        if ($cb === null) {
            return false;
        }

        // Use the injected kernel32 in tests, otherwise the real singleton.
        $k = self::$testKernel32 ?? Kernel32::self();

        $info = $k->getConsoleScreenBufferInfo($k->stdOut());

        if ($info === null) {
            return false;
        }

        $current = ['cols' => $info['cols'], 'rows' => $info['rows']];

        if (self::$resizeLastSize !== null
            && self::$resizeLastSize['cols'] === $current['cols']
            && self::$resizeLastSize['rows'] === $current['rows']
        ) {
            return false; // No change.
        }

        self::$resizeLastSize = $current;
        $cb($current['cols'], $current['rows']);

        return true;
    }

    // ─── Test injection ──────────────────────────────────────────────────────

    /**
     * Inject a test Kernel32 double.
     *
     * This is an internal test-only API.  Do not call in production.
     *
     * @internal test-only
     */
    public static function setTestKernel32(?Kernel32Interface $k): void
    {
        self::$testKernel32 = $k;
    }

    /**
     * Reset all static state (called via reflection in test setUp).
     *
     * @internal test-only
     */
    public static function resetStaticState(): void
    {
        self::$testKernel32    = null;
        self::$resizeCallback  = null;
        self::$resizeLastSize  = null;
    }
}

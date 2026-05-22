<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Tty;

use SugarCraft\Core\Util\Tty\InterruptFlags;

/**
 * Windows-specific TTY backend using FFI to kernel32.dll.
 *
 * This class is never instantiated directly — use {@see Tty}
 * which selects the correct backend based on environment detection.
 *
 * Windows console handles (HANDLE) are represented as plain PHP `int`
 * values throughout this class.  FFI pointer types never leak outside
 * Kernel32.php / Kernel32Interface.php.
 *
 * @see Tty
 * @see PosixBackend
 */
final class WindowsBackend implements Backend
{
    // Mask for clearing input mode flags that are unsafe in raw mode.
    // Uses bitwise NOT so that AND-ing with the saved mode CLEARS those bits.
    private const MASK_CLEAR_INPUT = ~(
        Kernel32Interface::ENABLE_LINE_INPUT
        | Kernel32Interface::ENABLE_PROCESSED_INPUT
        | Kernel32Interface::ENABLE_ECHO_INPUT
    );

    /** @var resource|null */
    private $stream;

    /** @var Kernel32Interface */
    private Kernel32Interface $kernel32;

    /** Saved input mode (null when not in raw mode). */
    private ?int $savedInputMode = null;

    /** Saved output mode (null when not in raw mode). */
    private ?int $savedOutputMode = null;

    /** Saved input codepage. */
    private ?int $savedInputCp = null;

    /** Saved output codepage. */
    private ?int $savedOutputCp = null;

    // ─── Static resize state ─────────────────────────────────────────────────

    /**
     * Registered resize callback.
     *
     * @var \Closure(int $cols, int $rows): void|null
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
     *
     * When set (via {@see setTestKernel32()}), drainSignals() uses this
     * instead of the real Kernel32 singleton.  Do not use in production.
     *
     * @var Kernel32Interface|null
     */
    private static ?Kernel32Interface $testKernel32 = null;

    /**
     * CONIN$ handle opened by {@see openTty()}, or null when not opened.
     *
     * Stored as a plain int (handle pointer address) so drainSignals()
     * can poll ReadConsoleInputW for key events without needing a stream.
     *
     * @var int|null
     */
    private static ?int $coninHandle = null;

    /**
     * Injected InterruptFlags instance (or test double) for testing.
     *
     * @var object|null
     */
    private static ?object $testInterruptFlags = null;

    /**
     * Tracks whether an interrupt has been consumed from the shared flag
     * but not yet dispatched (for the current drainSignals() cycle).
     *
     * @var bool
     */
    private static bool $interruptPending = false;

    /**
     * Tracks whether a KEY_EVENT has been seen on the open CONIN$ handle.
     *
     * @var bool
     */
    private static bool $interruptKeySeen = false;

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
     * Open the system console TTY directly, bypassing any redirected stdin.
     *
     * On Windows, PHP's STDIN may be a pipe when launched from certain
     * launchers.  Opening `CONIN$` gives us a direct handle to the
     * active console's input queue, which is required for detecting
     * Ctrl+C via ReadConsoleInputW.
     *
     * The returned handles are PHP `resource` values wrapping the raw
     * CONIN$/CONOUT$ HANDLEs.  Callers can read from `$handles[0]`
     * (CONIN) and write to `$handles[1]` (CONOUT).
     *
     * When this method succeeds, drainSignals() will poll CONIN$ for
     * key events (Ctrl+C, Ctrl+Break) as a fallback when the native
     * SetConsoleCtrlHandler callback is unavailable.
     *
     * @return array{0:resource,1:resource}|null on success, null on failure
     */
    public static function openTty(): ?array
    {
        try {
            $k = self::$testKernel32 ?? Kernel32::self();

            // Open CONIN$ (console input) for raw read access.
            $conin = $k->createFile(
                'CONIN$',
                Kernel32Interface::GENERIC_READ | Kernel32Interface::GENERIC_WRITE,
                Kernel32Interface::FILE_SHARE_READ | Kernel32Interface::FILE_SHARE_WRITE,
            );
            if ($conin === false) {
                return null;
            }

            // Open CONOUT$ (console output) for raw write access.
            $conout = $k->createFile(
                'CONOUT$',
                Kernel32Interface::GENERIC_WRITE,
                Kernel32Interface::FILE_SHARE_READ | Kernel32Interface::FILE_SHARE_WRITE,
            );
            if ($conout === false) {
                $k->closeHandle($conin);
                return null;
            }

            // Store the CONIN$ handle so drainSignals() can poll it
            // for key events without needing a stream resource.
            self::$coninHandle = $conin;

            // Wrap the raw handles as PHP stream resources.
            // On Windows, fdopen() accepts an OS handle number.
            $fin  = @fopen('php://fd/' . $conin, 'rb');
            $fout = @fopen('php://fd/' . $conout, 'wb');

            if ($fin === false || $fout === false) {
                if ($fin !== false) {
                    fclose($fin);
                }
                $k->closeHandle($conin);
                if ($fout !== false) {
                    fclose($fout);
                }
                $k->closeHandle($conout);
                self::$coninHandle = null;
                return null;
            }

            return [$fin, $fout];
        } catch (\Throwable) {
            return null;
        }
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

            $this->kernel32->setConsoleMode($stdin, $rawInput);
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

    // ─── Resize + interrupt signalling (PR3 + PR4) ─────────────────────────

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
     * Drain any pending resize or interrupt signals.
     *
     * On Windows this polls `GetConsoleScreenBufferInfo(stdout)` once and
     * checks the shared interrupt flag once.  Returns a bitmask indicating
     * which signals were dispatched.
     *
     * When SIGNAL_INTERRUPT is returned, the caller (e.g. Program::tick)
     * is responsible for dispatching {@see InterruptMsg} to the running
     * Program instance.
     *
     * Call this exactly once per event-loop tick.
     *
     * @return int|false int bitmask (SIGNAL_INTERRUPT | SIGNAL_RESIZE) when signals
     *                   were dispatched, false when nothing happened
     */
    public static function drainSignals(): int|false
    {
        $signals = 0;
        $k = self::$testKernel32 ?? Kernel32::self();

        // 1. Check the shared interrupt flag (written by the native C
        //    Ctrl-handler callback on a separate OS thread when available).
        //    This is the primary path: works whenever SetConsoleCtrlHandler
        //    was successfully registered.
        $flags = self::$testInterruptFlags ?? InterruptFlags::self();
        if ($flags->consume()) {
            self::$interruptPending = true;
        }

        if (self::$interruptPending) {
            self::$interruptPending = false;
            $signals |= self::SIGNAL_INTERRUPT;
            // Note: the caller (e.g. Program::tick) is responsible for
            // dispatching InterruptMsg when this bit is returned.
        }

        // 2. Poll CONIN$ for key events (Ctrl+C, Ctrl+Break) if openTty()
        //    was called and the native Ctrl handler is not available.
        //    This is the fallback path for PHP 8.3.6 (no FFI::dynamicFunction)
        //    or when SetConsoleCtrlHandler was not successfully registered.
        //    ReadConsoleInputW delivers Ctrl+C as KEY_EVENT with
        //    VirtualKeyCode == 0x43 ('C') and ControlKeyState indicating
        //    LEFT_CTRL_PRESSED or RIGHT_CTRL_PRESSED.
        $conin = self::$coninHandle;
        if ($conin !== null) {
            $records = $k->readConsoleInput($conin, 64);
            if (is_array($records)) {
                foreach ($records as $rec) {
                    if ($rec['type'] === Kernel32Interface::KEY_EVENT) {
                        // KEY_EVENT is at index 0 of each 8-byte record.
                        // The VirtualKeyCode (byte 2) and ControlKeyState
                        // (byte 3) tell us whether Ctrl+C or Ctrl+Break fired.
                        // For now, any KEY_EVENT with Ctrl state while
                        // openTty is active is treated as an interrupt signal.
                        // The actual key-code filtering is done in the
                        // readConsoleInput path in openTty mode.
                        //
                        // Practical note: Ctrl+C generates TWO KEY_EVENTs —
                        // a key-down and a key-up.  We drain all and treat
                        // the presence of any Ctrl-key KEY_EVENT as the
                        // interrupt signal.
                        // A full implementation would parse the KeyDown flag
                        // and VirtualKeyCode; for now we conservatively set
                        // the interrupt bit if any KEY_EVENT was read.
                        if (self::$interruptKeySeen !== true) {
                            self::$interruptKeySeen = true;
                            self::$interruptPending = true;
                        }
                    }
                }

                if (self::$interruptPending && !($signals & self::SIGNAL_INTERRUPT)) {
                    // Only set if not already set via InterruptFlags above.
                    self::$interruptPending = false;
                    $signals |= self::SIGNAL_INTERRUPT;
                }
            }
        }

        // 3. Poll resize detection via GetConsoleScreenBufferInfo.
        $cb = self::$resizeCallback;
        if ($cb !== null) {
            $info = $k->getConsoleScreenBufferInfo($k->stdOut());

            if ($info !== null) {
                $current = ['cols' => $info['cols'], 'rows' => $info['rows']];
                if (self::$resizeLastSize === null
                    || self::$resizeLastSize['cols'] !== $current['cols']
                    || self::$resizeLastSize['rows'] !== $current['rows']
                ) {
                    self::$resizeLastSize = $current;
                    $cb($current['cols'], $current['rows']);
                    $signals |= self::SIGNAL_RESIZE;
                }
            }
        }

        return $signals ?: false;
    }

    // ─── Static last-resort restore ─────────────────────────────────────────

    /** Saved input mode for restoreLast(). */
    private static ?int $lastInputMode = null;

    /** Saved output mode for restoreLast(). */
    private static ?int $lastOutputMode = null;

    /** Saved input codepage for restoreLast(). */
    private static ?int $lastInputCp = null;

    /** Saved output codepage for restoreLast(). */
    private static ?int $lastOutputCp = null;

    public static function restoreLast(): void
    {
        if (self::$lastInputMode !== null) {
            // Second+ call: actually restore.
            try {
                $k = self::kernel32();
                $k->setConsoleMode($k->stdIn(), self::$lastInputMode);
                $k->setConsoleMode($k->stdOut(), self::$lastOutputMode);
                $k->setConsoleCP(self::$lastInputCp);
                $k->setConsoleOutputCP(self::$lastOutputCp);
            } catch (\Throwable) {
                // Best-effort.
            } finally {
                self::$lastInputMode  = null;
                self::$lastOutputMode = null;
                self::$lastInputCp    = null;
                self::$lastOutputCp   = null;
            }
            return;
        }
        // First call: save current state.
        try {
            $k = self::kernel32();
            $stdin  = $k->stdIn();
            $stdout = $k->stdOut();
            self::$lastInputMode  = $k->getConsoleMode($stdin) ?? 0;
            self::$lastOutputMode = $k->getConsoleMode($stdout) ?? 0;
            self::$lastInputCp    = $k->getConsoleCP();
            self::$lastOutputCp   = $k->getConsoleOutputCP();
        } catch (\Throwable) {
            // Best-effort.
        }
    }

    // ─── Interrupt flag cleanup ──────────────────────────────────────────────

    /**
     * Destroy the shared interrupt-memory segment.
     *
     * Called by the shutdown function registered in enableRawMode().
     */
    public function restore(): void
    {
        if ($this->savedInputMode === null) {
            return; // Nothing to restore.
        }

        try {
            $stdin  = $this->kernel32->stdIn();
            $stdout = $this->kernel32->stdOut();

            $this->kernel32->setConsoleMode($stdin, (int) $this->savedInputMode);
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

        // Clean up the shared interrupt-memory segment.
        try {
            InterruptFlags::self()->destroy();
        } catch (\Throwable) {
            // Best-effort.
        }
    }

    public function __destruct()
    {
        $this->restore();
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
     * Inject a test InterruptFlags double.
     *
     * @internal test-only
     *
     * @param object|null $flags any object with consume(): bool and set(): bool
     */
    public static function setTestInterruptFlags(?object $flags): void
    {
        self::$testInterruptFlags = $flags;
    }

    /**
     * Reset all static state (called via reflection in test setUp).
     *
     * @internal test-only
     */
    public static function resetStaticState(): void
    {
        self::$testKernel32        = null;
        self::$testInterruptFlags  = null;
        self::$resizeCallback      = null;
        self::$resizeLastSize      = null;
        self::$interruptPending    = false;
        self::$coninHandle         = null;
        self::$interruptKeySeen    = false;
    }
}

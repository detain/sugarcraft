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
     * The keystroke decoder bound to the current CONIN$ acquisition, or null.
     *
     * sc-02 (finding sugar-core-052): drainSignals() forwards the non-interrupt
     * KEY_EVENTs it reads here instead of shredding them; sharing one instance
     * with whoever drives WindowsConsoleInput keeps the surrogate-join state
     * in one place. Created at acquisition, dropped by resetStaticState().
     */
    private static ?WindowsConsoleInput $keyDecoder = null;

    /**
     * Keystroke bytes forwarded by drainSignals() and not yet taken.
     *
     * @see takePendingKeyBytes()
     */
    private static string $pendingKeyBytes = '';

    /**
     * One-per-process guard for the InterruptFlags shutdown cleanup.
     *
     * sc-02 (finding sugar-core-053): the interrupt segment is process-shared
     * machinery; its lifetime is the process, not any one backend instance,
     * so the destroy rides a single shutdown hook registered by
     * enableRawMode() — never a per-instance restore().
     */
    private static bool $flagsCleanupRegistered = false;

    /**
     * True when THIS instance registered its shutdown restore backstop.
     *
     * sc-40 (finding sugar-core-059): this used to be a method-local
     * `static $registered` — armed once per PROCESS while the backend
     * re-instantiates freely. A second WindowsBackend that enabled raw mode
     * never got a backstop (the one slot was spent on the first instance,
     * whose saved snapshot the closure referenced), so a crash after the
     * first instance had already restored left the user's console stuck in
     * raw mode with no teardown. Per-instance registration is safe because
     * restore() is idempotent (null saved state returns immediately).
     */
    private bool $shutdownRegistered = false;

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

    /**
     * True when this backend's OWN stream is a terminal AND the process is
     * attached to a console.
     *
     * sc-40 (finding sugar-core-056's right-handle leg): the two gates
     * answer two different questions and both are deliberate.
     * `stream_isatty($this->stream)` probes the stream the backend was
     * CONSTRUCTED with — not STDIN — so an injected-output backend never
     * answers about a device it does not own. The `stdIn()` check is NOT a
     * per-stream handle probe (Windows has no handle-to-stream map to
     * consult) but the process-wide "is there a console at all" gate: a
     * detached/GUI process with no console cannot drive any of the
     * console-mode machinery even when PHP's std streams are buffers.
     */
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
     * launchers.  Opening `CONIN$` via CreateFileW gives us a direct handle
     * to the active console's input queue — the only way to see keystrokes
     * (and Ctrl+C/Ctrl+Break as KEY_EVENT records) on a redirected stdin.
     *
     * The return value is the stream pair callers asked for: `[$fin, $fout]`
     * resources wrapping CONIN$/CONOUT$. But the ACQUISITION and the STREAM
     * WRAP are decoupled since sc-02 (finding sugar-core-051): once CONIN$
     * is open, drainSignals() polls it for interrupts regardless of whether
     * a php://fd stream could be produced on top — a null return therefore
     * means "no stream pair", NOT "the console input machine is dead".
     * Only a failed CreateFileW of CONIN$ itself leaves the machine dormant
     * (and even that no longer goes unnoticed — see the Program constructor
     * notice in the openTty fallback).
     *
     * @return array{0:resource,1:resource}|null on success, null on failure
     */
    public static function openTty(): ?array
    {
        try {
            $k = self::$testKernel32 ?? Kernel32::self();

            // Open CONIN$ (console input) for raw read access.
            // sc-02 (finding sugar-core-051): this IS the CreateFile('CONIN$')
            // acquisition — no php://fd re-numbering of a CRT fd it does not
            // own. The stream wrapping below is a convenience layer ON TOP.
            $conin = $k->createFile(
                'CONIN$',
                Kernel32Interface::GENERIC_READ | Kernel32Interface::GENERIC_WRITE,
                Kernel32Interface::FILE_SHARE_READ | Kernel32Interface::FILE_SHARE_WRITE,
            );
            if ($conin === false) {
                return null;
            }

            // The handle-driven machinery — drainSignals()'s CONIN$ poll and
            // the keystroke forward — needs the handle and NOTHING else.
            // Bind it (and the decoder that shares its surrogate state) the
            // moment acquisition succeeds, before any stream concern.
            self::$coninHandle = $conin;
            self::$keyDecoder  = new WindowsConsoleInput($conin, $k);

            // Open CONOUT$ (console output) for raw write access.
            $conout = $k->createFile(
                'CONOUT$',
                Kernel32Interface::GENERIC_WRITE,
                Kernel32Interface::FILE_SHARE_READ | Kernel32Interface::FILE_SHARE_WRITE,
            );
            if ($conout === false) {
                // sc-02 (051): a failed OUTPUT side must not blind the input
                // side. The caller asked for a stream pair and gets null —
                // but interrupt polling stays alive on the CONIN$ handle.
                // The handle deliberately leaks: it is process machinery now.
                return null;
            }

            // Wrap the raw handles as PHP stream resources.
            // NAMED SURVIVOR (sc-02, live-Windows leg): a Windows HANDLE is not
            // a CRT file descriptor, so `php://fd/<handle>` can legitimately
            // fail on a real console — exactly why the handle above is kept
            // independently of this wrap. Linux CI exercises both arms through
            // FakeKernel32 (small fds wrap fine; 999-series fail); the live
            // handle-vs-fd behaviour needs the owner's interactive Windows box.
            $fin  = @fopen('php://fd/' . $conin, 'rb');
            $fout = @fopen('php://fd/' . $conout, 'wb');

            if ($fin === false || $fout === false) {
                if ($fin !== false) {
                    fclose($fin);
                }
                if ($fout !== false) {
                    fclose($fout);
                }
                // sc-02 (051): the old shape closed both OS handles and
                // nulled self::$coninHandle here — a wrap failure silently
                // murdered the interrupt poll with it. The acquisition stands.
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
        } catch (\FFI\Exception) {
            /* Named FFI failure only.  The bare `\Throwable` catch here was
             * half of the CRITICAL mis-layout story: `\FFI::ptr()` never
             * existed, so EVERY Windows run swallowed a fatal and answered
             * LAST_RESORT_SIZE 200x60 — silently wrong geometry on every
             * sugar TUI.  Programming errors must propagate, not masquerade
             * as a size query that failed. */
        }

        // Last resort — the shared answer every backend gives (see Backend::LAST_RESORT_SIZE).
        // Pre-m17 this site returned its own 80x24 literal, diverging from the
        // POSIX backend under one size() contract; that split was the defect.
        return self::LAST_RESORT_SIZE;
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

            // sc-40 (finding sugar-core-059): make the static panic-rescue
            // slot coherent with THIS enable instead of letting a later
            // restoreLast() "save" the raw mode it is supposed to undo.
            // First-writer-wins keeps the ORIGINAL pre-raw geometry across
            // re-instantiation — a second enable must not overwrite the slot
            // with modes captured while the first instance's raw session is
            // still applied (that was the multi-instance overwrite defect).
            if (self::$lastInputMode === null) {
                self::$lastInputMode  = $this->savedInputMode;
                self::$lastOutputMode = $this->savedOutputMode;
                self::$lastInputCp    = $this->savedInputCp;
                self::$lastOutputCp   = $this->savedOutputCp;
            }

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
            // sc-40 (finding sugar-core-059): once per INSTANCE, not once
            // per process — see $shutdownRegistered for the story.
            if (!$this->shutdownRegistered) {
                $this->shutdownRegistered = true;
                register_shutdown_function([$this, 'restore']);
            }
            // sc-02 (finding sugar-core-053): the interrupt segment's owner
            // is the PROCESS, not any one backend. One shutdown cleanup
            // tears it down once; per-instance restore() no longer touches
            // it (see restore() for the sibling-poisoning story it stopped).
            if (!self::$flagsCleanupRegistered) {
                self::$flagsCleanupRegistered = true;
                register_shutdown_function(static function (): void {
                    InterruptFlags::destroyShared();
                });
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
     * On Windows this checks the shared interrupt flag, polls CONIN$ for
     * key-event records when openTty() bound one (interrupts signal; plain
     * keys forward to {@see takePendingKeyBytes()}, never drop), and polls
     * `GetConsoleScreenBufferInfo(stdout)` for a resize. Returns a bitmask
     * indicating which signals were dispatched.
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
        $interrupt = $flags->consume();

        // 2. Poll CONIN$ for key events when openTty() bound a handle.
        //
        //    sc-02 (finding sugar-core-052) retires the heuristic this block
        //    used to run: ANY KEY_EVENT counted as an interrupt, once — a
        //    $interruptKeySeen latch permanently armed after the first sight
        //    of a key, every other record was consumed-and-DROPPED, and the
        //    comment admitted the whole thing ("for now we conservatively set
        //    the interrupt bit if any KEY_EVENT was read"). A user typing
        //    'ctrl'-free text into a TEA program on this path lost keystrokes
        //    to the signal poll, and a second Ctrl+C after the first key was
        //    never delivered again.
        //
        //    Protocol truth: ReadConsoleInputW hands over KEY_EVENT_RECORDs
        //    with wVirtualKeyCode + dwControlKeyState. An interrupt is a
        //    Ctrl+C key-DOWN (vk 'C' + a Ctrl state bit) or a Ctrl+Break
        //    key-DOWN (vk CANCEL + a Ctrl state bit); the matching key-UP is
        //    the second half of the same press and carries no signal.
        //    Everything else is INPUT: forwarded through the shared decoder
        //    to takePendingKeyBytes() instead of silently eaten.
        $conin = self::$coninHandle;
        if ($conin !== null) {
            $records = $k->readConsoleInput($conin, 64);
            if (is_array($records)) {
                foreach ($records as $rec) {
                    if ($rec['type'] !== Kernel32Interface::KEY_EVENT) {
                        continue;
                    }
                    if (self::isInterruptKeyRecord($rec)) {
                        $interrupt = true;
                        continue;
                    }
                    if (self::$keyDecoder !== null) {
                        self::$pendingKeyBytes .= self::$keyDecoder->recordBytes($rec);
                    }
                }
            }
        }

        if ($interrupt) {
            $signals |= self::SIGNAL_INTERRUPT;
            // Note: the caller (e.g. Program::tick) is responsible for
            // dispatching InterruptMsg when this bit is returned.
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

    /**
     * Is this KEY_EVENT record a console interrupt (Ctrl+C / Ctrl+Break)?
     *
     * A press of Ctrl+C reaches the console input queue as TWO records — a
     * key-down carrying vk 0x43 plus a Ctrl state bit, and a key-up with the
     * same shape. Matching the down side only keeps one signal per press
     * without the latch the superseded heuristic needed (that latch was
     * itself the bug: it survived the press it was meant to deduplicate and
     * swallowed every later one).
     *
     * @param array<array-key, mixed> $rec a readConsoleInput() record
     */
    private static function isInterruptKeyRecord(array $rec): bool
    {
        if (($rec['keyDown'] ?? false) !== true) {
            return false;
        }
        if (((int) ($rec['ctrl'] ?? 0) & Kernel32Interface::CTRL_STATE_MASK) === 0) {
            return false;
        }

        return match ((int) ($rec['vk'] ?? 0)) {
            Kernel32Interface::VK_C, Kernel32Interface::VK_CANCEL => true,
            default => false,
        };
    }

    /**
     * Take the keystroke bytes a signal drain forwarded, leaving '' behind.
     *
     * sc-02 (finding 052) input-pump seam: production wiring for the
     * native-Windows pump is a separate ruling (crush_libs.md's sugar-core
     * RE-VERIFY 2026-10-08 block), but the record SHREDDER it was ruled
     * against is gone — non-interrupt keys a drainSignal() tick happens to
     * read now wait here, already decoded to the same UTF-8 bytes
     * WindowsConsoleInput::read() yields, until a reader takes them.
     */
    public static function takePendingKeyBytes(): string
    {
        $bytes = self::$pendingKeyBytes;
        self::$pendingKeyBytes = '';

        return $bytes;
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

    /**
     * Save-then-restore of the console modes, for panic handlers that hold
     * no Tty instance.
     *
     * sc-40 (finding sugar-core-059): both branches used to call
     * `self::kernel32()` — A METHOD THAT NEVER EXISTED. `Error` is a
     * Throwable, so the catch swallowed the fatal on every invocation: the
     * whole rescue path was a silent no-op (nothing saved, nothing restored)
     * and stayed that way because no Linux runner executes this class
     * outside its test doubles. It now takes the same accessor
     * openTty()/drainSignals() use — the injected test double when present,
     * the real singleton otherwise — which is also what makes the path
     * observable in the suite.
     *
     * Coherence with {@see enableRawMode()}: a successful enable seeds this
     * slot with the PRE-raw modes (first-writer-wins), so a panic handler's
     * first restoreLast() after any raw session lands on the real original
     * state instead of "saving" the raw mode it was meant to undo. With no
     * enable in the process history, the classic two-call shape (save on
     * arm, restore on fire) still works.
     */
    public static function restoreLast(): void
    {
        if (self::$lastInputMode !== null) {
            // Second+ call (or any call after a raw enable seeded the slot):
            // actually restore.
            try {
                $k = self::$testKernel32 ?? Kernel32::self();
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
        // First call: save current state (only reached when no raw mode was
        // ever enabled in this process — enableRawMode seeds the slot above).
        try {
            $k = self::$testKernel32 ?? Kernel32::self();
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

        // sc-02 (finding sugar-core-053): this used to end with
        // InterruptFlags::self()->destroy(). That was sibling poisoning —
        // EVERY backend's restore() (and so every __destruct, including a
        // plain go-out-of-scope) tore down the ONE process-shared segment
        // all siblings still depended on, and the cached singleton kept
        // answering consume()===false against the dead handle forever after.
        // Segment lifetime is the process now: enableRawMode() registers the
        // single destroyShared() shutdown hook.
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
        self::$coninHandle         = null;
        self::$keyDecoder          = null;
        self::$pendingKeyBytes     = '';
        // sc-02: the superseded $interruptPending / $interruptKeySeen latches
        // are gone — signal assembly is per-tick locals now (finding 052).
        self::$flagsCleanupRegistered = false;
        // sc-40: the panic-rescue slot is static state too — enableRawMode
        // now seeds it, so a test that enables must not leak the seed into
        // the next test's restoreLast accounting.
        self::$lastInputMode  = null;
        self::$lastOutputMode = null;
        self::$lastInputCp    = null;
        self::$lastOutputCp   = null;
    }
}

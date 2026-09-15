<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

use SugarCraft\Input\InputDriver;
use SugarCraft\Input\Event;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\Event\MouseEvent;
use SugarCraft\Input\Event\FocusEvent;
use SugarCraft\Input\Event\PasteEvent;
use SugarCraft\Input\KeyModifier;
use SugarCraft\Input\Driver\StreamInputDriver;

/**
 * Run a sugar-readline prompt with real TTY input via candy-input.
 *
 * Wiring:
 * 1. Construct with an InputDriver (production default: StreamInputDriver::fromStdin()).
 * 2. Register handlers via onKey(), onMouse(), onFocus(), onPaste().
 * 3. Call run() with a prompt that has a handleKey(string) method.
 *
 * The decode loop reads bytes from InputDriver, emits typed Events, and
 * routes KeyEvents to the symbolic key handler map. Mouse / focus / paste
 * events are dispatched to optional callbacks — ignored when no handler
 * is registered.
 *
 * @see Mirrors charmbracelet/bubbletea (input handling).
 */
final class Readline
{
    /** @var InputDriver|null */
    private ?InputDriver $input;

    /** @var array<string, callable(KeyEvent): void> Symbolic key → handler */
    private array $keyHandlers = [];

    /** @var callable(MouseEvent): void|null */
    private $mouseHandler = null;

    /** @var callable(FocusEvent): void|null */
    private $focusHandler = null;

    /** @var callable(PasteEvent): void|null */
    private $pasteHandler = null;

    /** @var (callable(int): void)|null Idle-poll sleep seam (microseconds); null = usleep() */
    private $idleSleeper;

    /** Ceiling flag for the input pump — see stop(). Plain bool store, safe from a signal callback. */
    private bool $stopped = false;

    /**
     * @param InputDriver|null $input       Defaults to StreamInputDriver::fromStdin()
     * @param callable|null    $idleSleeper Test seam invoked with the computed idle-poll
     *                                      delay in microseconds; defaults to usleep().
     */
    public function __construct(?InputDriver $input = null, ?callable $idleSleeper = null)
    {
        $this->input = $input;
        $this->idleSleeper = $idleSleeper;
    }

    /**
     * Factory: build a Readline that reads from STDIN.
     */
    public static function fromStdin(): self
    {
        return new self(new StreamInputDriver(fopen('php://stdin', 'r')));
    }

    // -------------------------------------------------------------------------
    // Handler registration
    // -------------------------------------------------------------------------

    /**
     * Register a handler for a symbolic key name.
     *
     * Symbolic names:
     *  - Navigation: 'up', 'down', 'left', 'right', 'home', 'end', 'pageup', 'pagedown'
     *  - Editing: 'tab', 'enter', 'backspace', 'delete', 'space', 'undo', 'redo'
     *  - Control: 'ctrl_c', 'ctrl_u', 'ctrl_k', 'ctrl_w'
     *  - Meta: 'escape'
     *  - Plain chars: 'a'–'z', 'A'–'Z', '0'–'9', etc.
     *  - Function: 'f1'–'f12'
     *
     * @param string $key      Symbolic key name
     * @param callable(KeyEvent): void $handler
     */
    public function onKey(string $key, callable $handler): self
    {
        $clone = clone $this;
        $clone->keyHandlers[$key] = $handler;
        return $clone;
    }

    /**
     * Register a handler for mouse events.
     *
     * @param callable(MouseEvent): void $handler
     */
    public function onMouse(callable $handler): self
    {
        $clone = clone $this;
        $clone->mouseHandler = $handler;
        return $clone;
    }

    /**
     * Register a handler for focus events (terminal gained/lost focus).
     *
     * @param callable(FocusEvent): void $handler
     */
    public function onFocus(callable $handler): self
    {
        $clone = clone $this;
        $clone->focusHandler = $handler;
        return $clone;
    }

    /**
     * Register a handler for bracketed paste events.
     *
     * @param callable(PasteEvent): void $handler
     */
    public function onPaste(callable $handler): self
    {
        $clone = clone $this;
        $clone->pasteHandler = $handler;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Run loop
    // -------------------------------------------------------------------------

    /**
     * Sleep for the first empty (no-data) read; doubles per consecutive empty
     * read until capped at IDLE_POLL_MAX_MICROSECONDS (E713 ladder floor).
     */
    public const IDLE_POLL_MIN_MICROSECONDS = 1_000;

    /** Idle-poll cap: a fully idle pump costs at most 50 wake-ups per second. */
    public const IDLE_POLL_MAX_MICROSECONDS = 20_000;

    /**
     * Request the input pump to cease on its next loop pass.
     *
     * sugar-readline arms no signal handlers of its own (a deliberate lib
     * posture — prompts own their ctrl_c/esc aborts via isAborted()). This
     * method is the cancel ceiling for everything else: a caller wiring
     * SIGINT through pcntl_async_signals(), a watchdog timer, or another
     * part of the same process. Its only work is a bool store, which is
     * safe to perform from a signal callback.
     *
     * Affects the instance whose run() is executing — the loop reads the
     * flag off $this, so hold on to the object passed to run(), not to a
     * pre-onKey() clone (the registration methods clone; the flag lives on
     * whichever instance is running).
     *
     * The flag LATCHES: stop() is one-shot per instance — a stopped
     * instance never polls again, and there is no resume. Construct a
     * fresh Readline to restart prompting.
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /** Whether stop() has been requested for this instance. */
    public function isStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * Sleep duration in microseconds for the Nth consecutive empty read:
     * 1ms floor, doubling per pass, 20ms cap (E713 idle backoff ladder).
     *
     * Pure and static so the ladder is pinned without wall-clock timing.
     * Counts below 1 coerce to the floor; absurd counts stay capped — the
     * shift is clamped before it could overflow into garbage.
     *
     * @param int $consecutiveEmptyReads 1 = first empty read since last activity
     */
    public static function idlePollDelayMicroseconds(int $consecutiveEmptyReads): int
    {
        if ($consecutiveEmptyReads <= 1) {
            return self::IDLE_POLL_MIN_MICROSECONDS;
        }

        $shift = min($consecutiveEmptyReads - 1, 16);
        $doubled = self::IDLE_POLL_MIN_MICROSECONDS << $shift;

        return min($doubled, self::IDLE_POLL_MAX_MICROSECONDS);
    }

    /**
     * Run the readline loop over a prompt object.
     *
     * Reads events from InputDriver and routes them to registered handlers.
     * For KeyEvents, dispatches to the symbolic key handler (by key name)
     * and also invokes the prompt's handleChar() for printable keys or
     * handleKey() for control keys.
     *
     * After each state change, if an output stream is provided and the prompt
     * exposes a view() method, writes a redraw sequence to the output.
     *
     * While running, bracketed paste mode (DEC private mode 2004) is enabled
     * on the output terminal so pastes arrive as a single PasteEvent instead
     * of a keystroke storm; it is restored on every exit path. Only emitted
     * when the output is a real TTY, so tests and piped output are unaffected.
     *
     * Poll contract (E713, round 82): this is the interactive input pump —
     * by design it waits for input rather than returning on a schedule. Its
     * idle cost and lifetime are nevertheless bounded. Empty reads back off
     * exponentially from IDLE_POLL_MIN_MICROSECONDS (1ms) doubling per
     * consecutive empty read up to the IDLE_POLL_MAX_MICROSECONDS cap (20ms),
     * and snap back to the floor on any activity; a fully idle pump costs at
     * most 50 wake-ups per second. The wait itself is bounded by stop(): the
     * loop checks the ceiling flag on every pass, so run() returns within one
     * idle poll of the call. When the loop ends via stop() the current prompt
     * state is returned untouched — neither submitted nor aborted; inspect
     * isSubmitted()/isAborted() to learn how the exchange ended. Note this is
     * an input-side idle bound, not a wall-clock kill on a request (E646).
     *
     * @param object       $prompt  Object with handleKey(string): object method
     * @param resource|null $output Output stream for repainting (default: STDOUT)
     * @return object  The final prompt state after user submits, aborts, or stop() fires
     */
    public function run(object $prompt, $output = null): object
    {
        $bracketedPaste = $this->enableBracketedPaste($output);
        try {
            return $this->runLoop($prompt, $output);
        } finally {
            if ($bracketedPaste) {
                fwrite($output, "\x1b[?2004l");
            }
        }
    }

    /**
     * @param object        $prompt
     * @param resource|null $output
     */
    private function runLoop(object $prompt, $output): object
    {
        $driver = $this->input ?? new StreamInputDriver(fopen('php://stdin', 'r'));

        // Initial frame: repaint before entering the loop
        $this->repaint($prompt, $output);

        $consecutiveEmptyReads = 0;

        while (true) {
            if ($this->stopped) {
                // Cancel ceiling (E713): stop() from a signal handler,
                // watchdog, or this process. Last prompt state, untouched.
                return $prompt;
            }

            $event = $driver->read();

            if ($event === null) {
                // No data this pass. Back off along the E713 ladder instead
                // of a fixed 20ms spin; the sleeper seam lets tests pin the
                // schedule without wall-clock time.
                $consecutiveEmptyReads++;
                $this->sleepIdlePoll(self::idlePollDelayMicroseconds($consecutiveEmptyReads));
                continue;
            }

            // Any activity snaps the ladder back to the 1ms floor.
            $consecutiveEmptyReads = 0;

            if ($event instanceof KeyEvent) {
                $keyName = $this->symbolicKey($event);
                $result = $this->dispatchKey($event, $keyName, $prompt);
                if ($result['stop']) {
                    return $result['prompt'];
                }
                $prompt = $result['prompt'];
                $this->repaint($prompt, $output);
                continue;
            }

            if ($event instanceof MouseEvent) {
                if ($this->mouseHandler !== null) {
                    ($this->mouseHandler)($event);
                }
                continue;
            }

            if ($event instanceof FocusEvent) {
                if ($this->focusHandler !== null) {
                    ($this->focusHandler)($event);
                }
                continue;
            }

            if ($event instanceof PasteEvent) {
                if ($this->pasteHandler !== null) {
                    ($this->pasteHandler)($event);
                }
                // Also feed pasted text into the prompt when it accepts characters
                if (is_callable([$prompt, 'handleChar']) && $event->content !== '') {
                    foreach (mb_str_split($event->content, 1, 'UTF-8') as $char) {
                        $next = $prompt->handleChar($char);
                        if (is_callable([$next, 'isSubmitted']) && $next->isSubmitted()) {
                            return $next;
                        }
                        if (is_callable([$next, 'isAborted']) && $next->isAborted()) {
                            return $next;
                        }
                        $prompt = $next;
                    }
                    $this->repaint($prompt, $output);
                }
                continue;
            }

            // Unknown event — ignore
        }
    }

    /**
     * Perform one idle-poll sleep: through the injected seam when present,
     * otherwise usleep(). Never called while events are flowing.
     */
    private function sleepIdlePoll(int $microseconds): void
    {
        $sleeper = $this->idleSleeper;
        if ($sleeper === null) {
            usleep($microseconds);
            return;
        }

        $sleeper($microseconds);
    }

    /**
     * Emit `CSI ?2004h` (mirrors candy-core Ansi::bracketedPasteOn()) so the
     * terminal wraps pastes in ESC[200~ / ESC[201~ markers that candy-input's
     * decoder turns into PasteEvents.
     *
     * Guarded to real TTYs only: tests write to memory streams and pipes,
     * which must not receive stray control sequences.
     *
     * @return bool True when the mode was enabled (caller must disable on exit).
     */
    private function enableBracketedPaste(mixed $output): bool
    {
        if (!\is_resource($output) || !stream_isatty($output)) {
            return false;
        }
        fwrite($output, "\x1b[?2004h");
        return true;
    }

    /**
     * Write a redraw sequence to the output stream if provided and the prompt
     * exposes a view() method.
     *
     * Emits "\r\x1b[K" (carriage-return + clear-line) followed by the view
     * output to ensure the prompt line is refreshed cleanly.
     */
    private function repaint(object $prompt, mixed $output): void
    {
        if ($output === null) {
            return;
        }
        if (!is_callable([$prompt, 'view'])) {
            return;
        }
        $view = $prompt->view();
        if ($view === '') {
            return;
        }
        fwrite($output, "\r\x1b[K" . $view . "\n");
    }

    /**
     * Dispatch a KeyEvent to registered key handlers and the prompt.
     *
     * For printable single-character keys (no Ctrl/Alt modifier), prefers
     * calling handleChar() when the prompt exposes it (TextPrompt, TextareaPrompt).
     * Falls back to handleKey() for non-printable keys and prompts that only
     * implement handleKey (SelectionPrompt, MultiSelect, Confirmation).
     *
     * @return array{stop: bool, prompt: object}
     */
    private function dispatchKey(KeyEvent $event, string $keyName, object $prompt): array
    {
        // Always route to registered symbolic handler if present
        if (isset($this->keyHandlers[$keyName])) {
            ($this->keyHandlers[$keyName])($event);
        }

        // Prefer handleChar() for printable keys when the prompt exposes it
        if ($this->isPrintable($event, $keyName) && is_callable([$prompt, 'handleChar'])) {
            $next = $prompt->handleChar($event->key);
            // Check if the prompt considers itself done
            if (is_callable([$next, 'isSubmitted'])) {
                if ($next->isSubmitted()) {
                    return ['stop' => true, 'prompt' => $next];
                }
            }
            if (is_callable([$next, 'isAborted'])) {
                if ($next->isAborted()) {
                    return ['stop' => true, 'prompt' => $next];
                }
            }
            $prompt = $next;
            return ['stop' => false, 'prompt' => $prompt];
        }

        // Delegate to the prompt's handleKey method if it has one
        if (is_callable([$prompt, 'handleKey'])) {
            $next = $prompt->handleKey($keyName);
            // Check if the prompt considers itself done
            if (is_callable([$next, 'isSubmitted'])) {
                if ($next->isSubmitted()) {
                    return ['stop' => true, 'prompt' => $next];
                }
            }
            if (is_callable([$next, 'isAborted'])) {
                if ($next->isAborted()) {
                    return ['stop' => true, 'prompt' => $next];
                }
            }
            $prompt = $next;
        }

        return ['stop' => false, 'prompt' => $prompt];
    }

    /**
     * Determine if a KeyEvent represents a printable character that should
     * be routed through handleChar() instead of handleKey().
     *
     * A printable key has:
     * - No Ctrl or Alt modifier
     * - A key that is a single multibyte character
     * - A symbolic name that is NOT a reserved word (space, tab, enter, etc.)
     */
    private function isPrintable(KeyEvent $event, string $keyName): bool
    {
        // Must be a single character
        if (mb_strlen($event->key, 'UTF-8') !== 1) {
            return false;
        }

        // No Ctrl or Alt modifier
        $mod = $event->modifiers;
        if ($mod->includes(\SugarCraft\Input\KeyModifier::CTRL) || $mod->includes(\SugarCraft\Input\KeyModifier::ALT)) {
            return false;
        }

        // Symbolic name must not be a reserved non-printable key
        static $reserved = ['space', 'tab', 'enter', 'backspace', 'delete',
                            'up', 'down', 'left', 'right', 'home', 'end',
                            'pageup', 'pagedown', 'escape', 'esc', 'f1', 'f2', 'f3',
                            'f4', 'f5', 'f6', 'f7', 'f8', 'f9', 'f10', 'f11', 'f12',
                            'ctrl_c', 'ctrl_u', 'ctrl_k', 'ctrl_w', 'undo', 'redo'];
        if (in_array($keyName, $reserved, true)) {
            return false;
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Key name mapping
    // -------------------------------------------------------------------------

    /**
     * Convert a KeyEvent into a sugar-readline symbolic key name.
     *
     * Maps EscapeDecoder output (ArrowUp, ArrowDown, Enter, etc.)
     * to sugar-readline Key constants (up, down, enter, etc.).
     * Handles Ctrl modifier: Ctrl+C → 'ctrl_c', Ctrl+letter → 'ctrl_<letter>'.
     * Handles Alt modifier: Alt+X → 'alt_x'.
     * Handles Shift modifier: Shift+ArrowUp → 'shift_up'.
     * Handles plain printable chars: 'a'–'z', 'A'–'Z', '0'–'9'.
     */
    private function symbolicKey(KeyEvent $event): string
    {
        $key = $event->key;
        $mod = $event->modifiers;

        // Ctrl modifier — map to 'ctrl_<letter>' symbolic name
        if ($mod->includes(KeyModifier::CTRL)) {
            // Ctrl letters come through as lowercase 'a'-'z' from EscapeDecoder
            $letter = mb_strtolower($key, 'UTF-8');
            // Ctrl+A = ord 1, so ctrl+letter name maps as ctrl_a, ctrl_b, etc.
            // For Ctrl+C specifically, EscapeDecoder gives key='c' with Ctrl modifier
            if (strlen($letter) === 1 && ctype_alpha($letter)) {
                return 'ctrl_' . $letter;
            }
            // If key is a special name like 'Escape' with Ctrl, map specially
            $ctrlMap = [
                'Escape' => 'ctrl_c',  // Ctrl+[ is escape, but Ctrl+C is the canonical abort
                '[' => 'ctrl_c',
            ];
            return $ctrlMap[$key] ?? 'ctrl_' . $letter;
        }

        // Alt modifier — map to 'alt_<key>'
        if ($mod->includes(KeyModifier::ALT)) {
            $lower = mb_strtolower($key, 'UTF-8');
            if (strlen($lower) === 1) {
                return 'alt_' . $lower;
            }
            // Alt+ArrowUp, Alt+ArrowDown etc.
            return 'alt_' . mb_strtolower($this->stripPrefix($key, 'Arrow'), 'UTF-8');
        }

        // Shift modifier — map to 'shift_<key>'
        if ($mod->includes(KeyModifier::SHIFT)) {
            // Shift only matters for uppercase letters
            if (strlen($key) === 1 && ctype_upper($key)) {
                return mb_strtolower($key, 'UTF-8');
            }
            return 'shift_' . mb_strtolower($this->stripPrefix($key, 'Arrow'), 'UTF-8');
        }

        // Plain key — map EscapeDecoder names to sugar-readline Key constants
        return $this->mapPlainKey($key);
    }

    /**
     * Map a plain (no modifiers) EscapeDecoder key name to sugar-readline Key constant.
     */
    private function mapPlainKey(string $key): string
    {
        // Arrow keys
        static $arrowMap = [
            'ArrowUp'    => 'up',
            'ArrowDown'  => 'down',
            'ArrowLeft'  => 'left',
            'ArrowRight' => 'right',
        ];
        if (isset($arrowMap[$key])) {
            return $arrowMap[$key];
        }

        // Function keys F1–F12
        if (preg_match('/^F(\d+)$/', $key, $m)) {
            return 'f' . $m[1];
        }

        // Standard Edit/Navigation keys
        static $keyMap = [
            'Home'       => 'home',
            'End'        => 'end',
            'PageUp'     => 'pageup',
            'PageDown'   => 'pagedown',
            'Insert'     => 'insert',
            'Delete'     => 'delete',
            'Tab'        => 'tab',
            'Enter'      => 'enter',
            'Escape'     => 'esc',
            'Backspace' => 'backspace',
            'Space'      => 'space',
        ];
        if (isset($keyMap[$key])) {
            return $keyMap[$key];
        }

        // Ctrl+letters come through EscapeDecoder as lowercase letters
        // with no modifier flag — these are plain 'a'-'z' from type-to.
        // Check if it's a control letter (EscapeDecoder doesn't flag ctrl
        // for lowercase letters unless the Ctrl modifier was actually set).
        // Handle Ctrl+C (0x03) which arrives as key='c' with modifiers=ctrl
        // — that case is handled above. Here we just return the lowercase char.
        if (strlen($key) === 1) {
            return $key;
        }

        // Fallback: lowercase the key name
        return mb_strtolower($key, 'UTF-8');
    }

    /**
     * Strip a prefix from a string if it matches (case-sensitive).
     */
    private function stripPrefix(string $value, string $prefix): string
    {
        if (str_starts_with($value, $prefix)) {
            return substr($value, strlen($prefix));
        }
        return $value;
    }
}

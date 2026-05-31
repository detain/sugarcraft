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

    /**
     * @param InputDriver|null $input  Defaults to StreamInputDriver::fromStdin()
     */
    public function __construct(?InputDriver $input = null)
    {
        $this->input = $input;
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
     * Run the readline loop over a prompt object.
     *
     * Reads events from InputDriver and routes them to registered handlers.
     * For KeyEvents, dispatches to the symbolic key handler (by key name)
     * and also invokes the prompt's handleKey(string) method.
     *
     * @param object $prompt  Object with handleKey(string): object method
     * @return object  The final prompt state after user submits or aborts
     */
    public function run(object $prompt): object
    {
        $driver = $this->input ?? new StreamInputDriver(fopen('php://stdin', 'r'));

        while (true) {
            $event = $driver->read();

            if ($event === null) {
                // Non-blocking empty read — continue polling
                continue;
            }

            if ($event instanceof KeyEvent) {
                $keyName = $this->symbolicKey($event);
                $result = $this->dispatchKey($event, $keyName, $prompt);
                if ($result['stop']) {
                    return $result['prompt'];
                }
                $prompt = $result['prompt'];
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
                continue;
            }

            // Unknown event — ignore
        }
    }

    /**
     * Dispatch a KeyEvent to registered key handlers and the prompt.
     *
     * @return array{stop: bool, prompt: object}
     */
    private function dispatchKey(KeyEvent $event, string $keyName, object $prompt): array
    {
        // Always route to registered symbolic handler if present
        if (isset($this->keyHandlers[$keyName])) {
            ($this->keyHandlers[$keyName])($event);
        }

        // Also delegate to the prompt's handleKey method if it has one
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

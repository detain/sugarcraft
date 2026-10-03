<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Input;

use SugarCraft\Core\InputReader;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyboardEnhancementsMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\ClipboardMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Testing\Lang;

/**
 * Builder for sequences of input messages to feed a {@see \SugarCraft\Testing\ProgramSimulator}.
 *
 * ScriptedInput provides a fluent API for composing test input scripts:
 *
 *   ScriptedInput::new()
 *       ->key('a')
 *       ->key('Enter')
 *       ->ticks(5)
 *       ->resize(80, 24)
 *       ->key('q')
 *       ->build();
 *
 * Terminal-shaped input — {@see paste()} and {@see bytes()} — is not
 * re-implemented here: the bytes are fed through candy-core's real
 * {@see InputReader}, the same parser {@see \SugarCraft\Core\Program}
 * reads stdin with, so a script sees exactly the message sequence the live
 * runtime would deliver (bracketed-paste envelope, chunking past
 * {@see InputReader::MAX_PASTE_BYTES}, sanitized payload, stale-paste close).
 *
 * @readonly
 * @see Mirrors charmbracelet/bubbletea — scripted input pattern (issue #1654)
 */
final readonly class ScriptedInput
{
    /**
     * Bytes per read {@see bytes()} hands the parser — the `fread()` size
     * {@see \SugarCraft\Core\Program} drains stdin with. It matters: the
     * reader only surfaces an oversized paste chunk between reads, so one
     * giant parse() would hide the chunking the runtime really produces.
     */
    public const READ_SIZE = 4096;

    /** Bracketed-paste start marker a terminal sends after `CSI ?2004h`. */
    public const PASTE_START = "\x1b[200~";

    /** Bracketed-paste end marker. */
    public const PASTE_END = "\x1b[201~";

    /** @var list<Msg> */
    private array $messages;

    /**
     * Parser state carried between {@see bytes()} calls. Never mutated in
     * place — every call works on a clone — so builders that branch from a
     * shared prefix stay independent, as the rest of this class's API does.
     */
    private InputReader $reader;

    /**
     * @param list<Msg> $messages
     */
    private function __construct(array $messages, ?InputReader $reader = null)
    {
        $this->messages = $messages;
        $this->reader = $reader ?? new InputReader();
    }

    /**
     * Default factory.
     */
    public static function new(): self
    {
        return new self([]);
    }

    /**
     * Mirror {@see \SugarCraft\Core\ProgramOptions::$sanitizePaste} for the
     * pastes this script builds. On (the default, as in the runtime) a
     * pasted payload is neutralized by
     * {@see \SugarCraft\Core\Util\Sanitize::untrustedForMarkedFrames()};
     * off, it reaches the model verbatim.
     *
     * Starts a fresh parser, so call it before {@see bytes()} — an
     * incomplete sequence buffered by an earlier call is discarded.
     */
    public function withSanitizePaste(bool $sanitize): self
    {
        return new self($this->messages, new InputReader($sanitize));
    }

    /**
     * Append a character key message.
     *
     * @param string $char Single character
     * @param bool   $shift Whether to set the shift modifier
     * @param bool   $ctrl  Whether to set the ctrl modifier
     * @param bool   $alt   Whether to set the alt modifier
     * @return self
     */
    public function key(string $char, bool $shift = false, bool $ctrl = false, bool $alt = false): self
    {
        return $this->push(new KeyMsg(
            type: KeyType::Char,
            rune: $char,
            alt: $alt,
            ctrl: $ctrl,
            shift: $shift,
        ));
    }

    /**
     * Append a named key message.
     *
     * @param \SugarCraft\Core\KeyType $type KeyType enum value
     * @param string                  $rune Character representation (empty for non-char keys)
     * @return self
     */
    public function namedKey(\SugarCraft\Core\KeyType $type, string $rune = ''): self
    {
        return $this->push(new KeyMsg(
            type: $type,
            rune: $rune,
            alt: false,
            ctrl: false,
            shift: false,
        ));
    }

    /**
     * Append pressing Enter.
     *
     * @return self
     */
    public function enter(): self
    {
        return $this->namedKey(KeyType::Enter);
    }

    /**
     * Append pressing Escape.
     *
     * @return self
     */
    public function escape(): self
    {
        return $this->namedKey(KeyType::Escape);
    }

    /**
     * Append pressing Backspace.
     *
     * @return self
     */
    public function backspace(): self
    {
        return $this->namedKey(KeyType::Backspace);
    }

    /**
     * Append pressing Tab.
     *
     * @return self
     */
    public function tab(): self
    {
        return $this->namedKey(KeyType::Tab);
    }

    /**
     * Append arrow keys.
     *
     * @param 'up'|'down'|'left'|'right' $dir
     * @return self
     */
    public function arrow(string $dir): self
    {
        $type = match ($dir) {
            'up' => KeyType::Up,
            'down' => KeyType::Down,
            'left' => KeyType::Left,
            'right' => KeyType::Right,
            default => throw new \InvalidArgumentException(Lang::t('input.invalid_arrow', ['dir' => $dir])),
        };
        return $this->namedKey($type);
    }

    /**
     * Append $count tick messages with $seconds interval each.
     *
     * Used to advance the virtual clock and trigger subscription handlers.
     * Emits {@see TickMsg} instances so models can match on them via
     * instanceof rather than relying on anonymous class identity.
     *
     * @param int   $count   Number of ticks
     * @param float $seconds Interval between ticks
     * @return self
     */
    public function ticks(int $count, float $seconds = 1.0): self
    {
        $messages = $this->messages;
        for ($i = 0; $i < $count; $i++) {
            $messages[] = new TickMsg($seconds);
        }
        return new self($messages, $this->reader);
    }

    /**
     * Append a window resize message.
     *
     * @param int $cols
     * @param int $rows
     * @return self
     */
    public function resize(int $cols, int $rows): self
    {
        return $this->push(new WindowSizeMsg($cols, $rows));
    }

    /**
     * Append a quit message.
     *
     * @return self
     */
    public function quit(): self
    {
        return $this->push(new QuitMsg());
    }

    /**
     * Append a mouse event.
     *
     * @param \SugarCraft\Core\MouseButton $button
     * @param \SugarCraft\Core\MouseAction $action
     * @param int                          $x      1-based column
     * @param int                          $y      1-based row
     * @return self
     */
    public function mouse(
        \SugarCraft\Core\MouseButton $button,
        \SugarCraft\Core\MouseAction $action,
        int $x,
        int $y,
    ): self {
        return $this->push(new MouseMsg(
            x: $x,
            y: $y,
            button: $button,
            action: $action,
        ));
    }

    /**
     * Append an arbitrary message.
     *
     * @param Msg $msg
     * @return self
     */
    public function push(Msg $msg): self
    {
        return new self([...$this->messages, $msg], $this->reader);
    }

    /**
     * Return the built message sequence.
     *
     * @return list<Msg>
     */
    public function build(): array
    {
        return $this->messages;
    }

    /**
     * Return the count of messages.
     */
    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * Append a mouse wheel scroll event.
     *
     * @param \SugarCraft\Core\MouseButton $button WheelUp or WheelDown
     * @param int                          $x      1-based column
     * @param int                          $y      1-based row
     * @return self
     */
    public function wheel(
        \SugarCraft\Core\MouseButton $button,
        int $x,
        int $y,
    ): self {
        return $this->push(new MouseWheelMsg(
            x: $x,
            y: $y,
            button: $button,
            action: MouseAction::Press,
        ));
    }

    /**
     * Append the messages a terminal paste of `$content` produces.
     *
     * The content is wrapped in the bracketed-paste envelope and fed through
     * the real {@see InputReader} ({@see bytes()}), so the model receives
     * what the runtime delivers: {@see \SugarCraft\Core\Msg\PasteStartMsg},
     * then — for a paste past {@see InputReader::MAX_PASTE_BYTES} — leading
     * {@see PasteMsg} chunks, {@see \SugarCraft\Core\Msg\PasteEndMsg}, and a
     * final PasteMsg. Payloads are sanitized unless
     * {@see withSanitizePaste()} turned that off; newlines, CR and tab
     * survive either way. Content that itself contains {@see PASTE_END}
     * closes the envelope there, exactly as it would on a real terminal.
     *
     * To hand a model one bare PasteMsg with no envelope, use
     * `push(new PasteMsg($content))`.
     *
     * @param string $content Raw pasted text
     * @return self
     */
    public function paste(string $content): self
    {
        return $this->bytes(self::PASTE_START . $content . self::PASTE_END);
    }

    /**
     * Append the messages raw terminal input decodes to.
     *
     * Each argument is one read from the terminal (split further into
     * {@see READ_SIZE}-byte reads, the runtime's `fread()` size) and is
     * parsed by the real {@see InputReader}. Several arguments model a
     * sequence split across reads with no pause between them — e.g. a paste
     * end marker straddling two reads.
     *
     * After the last read the input goes silent, and the runtime's idle
     * recovery runs as {@see \SugarCraft\Core\Program} would run it: a
     * lone buffered ESC becomes an Escape key, and a paste whose end marker
     * never arrived is closed after {@see InputReader::PASTE_IDLE_TIMEOUT}
     * (PasteEndMsg + PasteMsg), handing the keyboard back. Any other
     * incomplete sequence stays buffered for the next call, as it does in
     * the runtime.
     *
     * @param string ...$reads Raw bytes, one string per read
     * @return self
     */
    public function bytes(string ...$reads): self
    {
        $reader = clone $this->reader;
        $messages = $this->messages;
        foreach ($reads as $read) {
            foreach (str_split($read, self::READ_SIZE) as $chunk) {
                array_push($messages, ...$reader->parse($chunk));
            }
        }
        if ($reader->hasPendingEscape()) {
            $escape = $reader->flushPending();
            if ($escape !== null) {
                $messages[] = $escape;
            }
        }
        array_push($messages, ...$reader->flushStalePaste());

        return new self($messages, $reader);
    }

    /**
     * Append a clipboard message with the given content.
     *
     * @param string $content    Decoded clipboard content
     * @param string $selection  Selection key (default 'c' for clipboard)
     * @return self
     */
    public function clipboard(string $content, string $selection = 'c'): self
    {
        return $this->push(new ClipboardMsg($content, $selection));
    }

    /**
     * Append a keyboard enhancements reply message.
     *
     * Use this to simulate responses to kitty keyboard protocol requests.
     * Combine flags as a bitmask: DISAMBIGUATE | REPORT_EVENT_TYPES | etc.
     *
     * @param int $flags Kitty keyboard protocol flag bitmask
     * @return self
     */
    public function keyboardEnhancements(int $flags): self
    {
        return $this->push(new KeyboardEnhancementsMsg($flags));
    }
}

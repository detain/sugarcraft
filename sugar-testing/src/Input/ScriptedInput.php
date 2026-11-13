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
 *       ->enter()
 *       ->ticks(5, 1.0, static fn (int $i, float $s) => new \Your\TickMsg($s))  // factory required — see ticks()
 *       ->resize(80, 24)
 *       ->key('q')
 *       ->build();
 *
 * Terminal-shaped input — {@see paste()} and {@see bytes()} — is not
 * re-implemented here: the bytes are fed through sugar-core's real
 * {@see InputReader}, the same parser {@see \SugarCraft\Core\Program}
 * reads stdin with, so a script decodes through the same code path as the
 * live runtime (bracketed-paste envelope, chunking past
 * {@see InputReader::MAX_PASTE_BYTES}, sanitized payload, stale-paste
 * close). One deliberate divergence: the runtime performs idle recovery on
 * a timer, while a script runs it eagerly when the last read is consumed —
 * same resulting messages, no wall clock involved.
 *
 * Symbolic vs wire-shaped input — the arrow-key contract:
 * {@see arrow()} (and {@see key()}/{@see namedKey()}) build SYMBOLIC messages
 * (e.g. KeyType::Down) the way a decoded keyboard press arrives. {@see bytes()}
 * carries the REAL wire form, arrow keys as CSI sequences such as ESC [ C.
 * Do not paste a literal '→' (U+2192) glyph expecting it to act as an arrow:
 * a UTF-8 glyph is plain text — it takes the paste/IME path and decodes as
 * character input, never as KeyType::Down. A PTY harness driving a program
 * must send the ESC [ C bytes, not the glyph.
 *
 * First-party harness — upstream-antecedented (charmbracelet/teatest input
 * scripting) but re-implemented for sugar-core's message types.
 */
final readonly class ScriptedInput implements \Countable
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
     * @param string $char Single grapheme cluster — enforced: a multi-cluster string is a wire state no keyboard emits (chaining is the multi-key spelling: ->key('a')->key('b'))
     * @param bool   $shift Whether to set the shift modifier
     * @param bool   $ctrl  Whether to set the ctrl modifier
     * @param bool   $alt   Whether to set the alt modifier
     * @return self
     * @throws \InvalidArgumentException when $char is not exactly one grapheme cluster
     */
    public function key(string $char, bool $shift = false, bool $ctrl = false, bool $alt = false): self
    {
        // \X is one UAX#29 extended grapheme cluster: 'é' (precomposed or e+
        // combining) and the 🇧🇪 flag (two regional indicators) pass as one;
        // 'ab', '' and malformed UTF-8 (preg_match returns false) throw.
        if (!\preg_match('/^\X$/u', $char)) {
            throw new \InvalidArgumentException(Lang::t('input.key_not_single_cluster', ['chars' => $char]));
        }

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
     * Modifiers default to false but are settable — kitty keyboard-protocol
     * programs distinguish Ctrl+Enter from plain Enter, and a script that
     * cannot raise the flags cannot exercise those chords.
     *
     * @param \SugarCraft\Core\KeyType $type KeyType enum value
     * @param string                  $rune Character representation (empty for non-char keys)
     * @param bool                    $shift Whether to set the shift modifier
     * @param bool                    $ctrl  Whether to set the ctrl modifier
     * @param bool                    $alt   Whether to set the alt modifier
     * @return self
     */
    public function namedKey(
        \SugarCraft\Core\KeyType $type,
        string $rune = '',
        bool $shift = false,
        bool $ctrl = false,
        bool $alt = false,
    ): self {
        return $this->push(new KeyMsg(
            type: $type,
            rune: $rune,
            alt: $alt,
            ctrl: $ctrl,
            shift: $shift,
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
     * @param bool $shift Whether to set the shift modifier (Shift+Arrow selection)
     * @param bool $ctrl  Whether to set the ctrl modifier (word-wise motion)
     * @param bool $alt   Whether to set the alt modifier
     * @return self
     */
    public function arrow(string $dir, bool $shift = false, bool $ctrl = false, bool $alt = false): self
    {
        $type = match ($dir) {
            'up' => KeyType::Up,
            'down' => KeyType::Down,
            'left' => KeyType::Left,
            'right' => KeyType::Right,
            default => throw new \InvalidArgumentException(Lang::t('input.invalid_arrow', ['dir' => $dir])),
        };
        return $this->namedKey($type, shift: $shift, ctrl: $ctrl, alt: $alt);
    }

    /**
     * Append $count tick messages, one per interval of $seconds.
     *
     * IMPORTANT — there is no virtual clock. The simulator has no timer: it
     * pumps the message queue, and a tick is just another message arriving
     * between the others. A model therefore only advances if it matches the
     * exact class it was written to match, and fleet libs each ship their
     * OWN TickMsg class (SugarCraft\Dash\TickMsg, SugarCraft\Forms\TickMsg,
     * ...). Enqueueing this harness's {@see TickMsg} into a lib with its own
     * class matches NOTHING and passes vacuously — so a tick factory is
     * REQUIRED: omitting it throws instead of warning in prose. The harness
     * cannot guess your lib's tick class; the factory states it.
     *
     * Two correct uses:
     *  - pass a $factory minting your lib's tick class:
     *    ->ticks(3, 1.0, static fn (int $i, float $s) => new \Your\TickMsg($s))
     *  - or push() those messages directly.
     *
     * @param int $count Number of ticks
     * @param float $seconds Interval each tick represents
     * @param (callable(int, float): Msg)|null $factory Produces the tick message for index/interval; required — a null factory throws
     * @return self
     * @throws \InvalidArgumentException when $factory is null (the vacuous-tick trap, enforced)
     */
    public function ticks(int $count, float $seconds = 1.0, ?callable $factory = null): self
    {
        if ($factory === null) {
            throw new \InvalidArgumentException(Lang::t('input.ticks_factory_required'));
        }

        $messages = $this->messages;
        for ($i = 0; $i < $count; $i++) {
            $messages[] = $factory($i, $seconds);
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
     * After the last read the script closes out the way the runtime's idle
     * recovery does — but eagerly, not on a timer: a lone buffered ESC
     * becomes an Escape key, and a paste whose end marker never arrived is
     * closed ({@see InputReader::flushStalePaste()}: PasteEndMsg + PasteMsg),
     * handing the keyboard back. Any other incomplete sequence stays
     * buffered in the returned builder and joins the next {@see bytes()}
     * call's decode, as it would straddle reads in the runtime.
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

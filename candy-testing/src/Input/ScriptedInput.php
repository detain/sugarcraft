<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Input;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\KeyType;
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
 * @readonly
 * @see Mirrors charmbracelet/bubbletea — scripted input pattern (issue #1654)
 */
final readonly class ScriptedInput
{
    /** @var list<Msg> */
    private array $messages;

    /**
     * @param list<Msg> $messages
     */
    private function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    /**
     * Default factory.
     */
    public static function new(): self
    {
        return new self([]);
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
        return new self($messages);
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
        return new self([...$this->messages, $msg]);
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
}

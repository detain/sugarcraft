<?php

declare(strict_types=1);

namespace CandyCore\Bits\Key;

use CandyCore\Core\Msg\KeyMsg;

/**
 * A keybinding: a list of accepted key strings (matched against
 * {@see KeyMsg::string()}), an optional {@see Help} label, and a disabled
 * flag that suppresses both matching and help display.
 *
 * ```php
 * $up = new Binding(['up', 'k'], new Help('↑/k', 'move up'));
 * if ($up->matches($keyMsg)) { ... }
 * ```
 */
final class Binding
{
    /** @param list<string> $keys */
    public function __construct(
        public readonly array $keys,
        public readonly Help $help = new Help(),
        public readonly bool $disabled = false,
    ) {}

    public function matches(KeyMsg $msg): bool
    {
        if ($this->disabled) {
            return false;
        }
        return in_array($msg->string(), $this->keys, true);
    }

    public function withHelp(string $key, string $desc): self
    {
        return new self($this->keys, new Help($key, $desc), $this->disabled);
    }

    public function disable(bool $disabled = true): self
    {
        return new self($this->keys, $this->help, $disabled);
    }
}

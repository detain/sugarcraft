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

    /**
     * Construct a disabled-from-the-start binding. Equivalent to
     * `(new Binding($keys, $help))->disable()` — kept as a factory so
     * callers configuring a KeyMap up front can express the intent
     * inline. Mirrors upstream Bubbles' `key.WithDisabled`.
     *
     * @param list<string> $keys
     */
    public static function withDisabled(array $keys, Help $help = new Help()): self
    {
        return new self(array_values($keys), $help, true);
    }

    /**
     * Variadic factory mirroring upstream Bubbles' `key.NewBinding`.
     * Without options the binding is enabled with no keys / no help.
     * Pass options as keyword args:
     *
     * ```php
     * Binding::new(keys: ['ctrl+c'], help: new Help('ctrl+c', 'quit'));
     * Binding::new(keys: ['?'], help: new Help('?', 'help'), disabled: true);
     * ```
     *
     * @param list<string> $keys
     */
    public static function new(array $keys = [], Help $help = new Help(), bool $disabled = false): self
    {
        return new self(array_values($keys), $help, $disabled);
    }

    public function matches(KeyMsg $msg): bool
    {
        if ($this->disabled) {
            return false;
        }
        return in_array($msg->string(), $this->keys, true);
    }

    /**
     * Variadic top-level helper: `Binding::any($key, $up, $down)`
     * returns true when `$key` matches any of the supplied bindings.
     * Mirrors upstream `Matches(KeyMsg, ...Binding)`.
     */
    public static function any(KeyMsg $msg, self ...$bindings): bool
    {
        foreach ($bindings as $b) {
            if ($b->matches($msg)) {
                return true;
            }
        }
        return false;
    }

    public function withHelp(string $key, string $desc): self
    {
        return new self($this->keys, new Help($key, $desc), $this->disabled);
    }

    public function disable(bool $disabled = true): self
    {
        return new self($this->keys, $this->help, $disabled);
    }

    /**
     * Replace the matched key list. Mirrors upstream `SetKeys`.
     *
     * @param list<string> $keys
     */
    public function setKeys(array $keys): self
    {
        return new self(array_values($keys), $this->help, $this->disabled);
    }

    /** @return list<string> */
    public function getKeys(): array
    {
        return $this->keys;
    }

    /**
     * Replace the help label. Mirrors upstream `SetHelp` — `withHelp`
     * returns the same shape, kept as the historical name.
     */
    public function setHelp(string $key, string $desc): self
    {
        return $this->withHelp($key, $desc);
    }

    public function getHelp(): Help
    {
        return $this->help;
    }

    /**
     * `enabled()` returns the inverse of `disabled` for read ergonomics
     * and parity with upstream Bubbles (`Enabled()`).
     */
    public function enabled(): bool
    {
        return !$this->disabled;
    }

    /**
     * Mirror of {@see disable()} that takes the positive-sense flag.
     * `setEnabled(false)` is equivalent to `disable(true)`.
     */
    public function setEnabled(bool $on): self
    {
        return new self($this->keys, $this->help, !$on);
    }

    /**
     * Drop the binding's keys (still keeps help / disabled). Bound
     * keys can be repopulated later with {@see setKeys()}; useful
     * when a Bubbles-style component wants to take an existing
     * binding out of rotation without losing its label.
     */
    public function unbind(): self
    {
        return new self([], $this->help, $this->disabled);
    }
}

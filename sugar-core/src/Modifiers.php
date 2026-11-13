<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Immutable bitfield wrapping the `shift`, `alt`, `ctrl` modifier set
 * for a key event. Mirrors charmbracelet/bubbletea.Key.Mod so existing
 * code that reads `KeyMsg::$alt` / `$ctrl` keeps working while new
 * code can pattern-match on a single value.
 *
 * The bit layout matches xterm modified-CSI sequences:
 *   1 → shift
 *   2 → alt
 *   4 → ctrl
 *
 * Construct via {@see Modifiers::of()} (boolean form) or
 * {@see Modifiers::fromXtermMod()} (DEC parameter form).
 */
final class Modifiers
{
    public const SHIFT = 1;
    public const ALT   = 2;
    public const CTRL  = 4;

    public function __construct(
        public readonly bool $shift = false,
        public readonly bool $alt   = false,
        public readonly bool $ctrl  = false,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public static function of(bool $shift = false, bool $alt = false, bool $ctrl = false): self
    {
        return new self($shift, $alt, $ctrl);
    }

    /**
     * Decode the modifier byte from an xterm modified-CSI sequence
     * (`CSI 1 ; <mod> A` etc.). The standard says
     * `mod = 1 + (1·shift + 2·alt + 4·ctrl)`.
     */
    public static function fromXtermMod(int $mod): self
    {
        $bits = max(0, $mod - 1);
        return new self(
            shift: ($bits & 0b001) !== 0,
            alt:   ($bits & 0b010) !== 0,
            ctrl:  ($bits & 0b100) !== 0,
        );
    }

    /** @return int bit-OR of {@see SHIFT} / {@see ALT} / {@see CTRL}. */
    public function toBitfield(): int
    {
        return ($this->shift ? self::SHIFT : 0)
             | ($this->alt ? self::ALT : 0)
             | ($this->ctrl ? self::CTRL : 0);
    }

    public function isEmpty(): bool
    {
        return !$this->shift && !$this->alt && !$this->ctrl;
    }

    /**
     * Test one {@see SHIFT} / {@see ALT} / {@see CTRL} bit. The single
     * predicate behind the msg-level {@see \SugarCraft\Core\Msg\Modifiable::has()}
     * idiom, so key and mouse events answer modifier questions the same
     * way regardless of which internal shape their carrier stores.
     */
    public function has(int $modifier): bool
    {
        return match ($modifier) {
            self::SHIFT => $this->shift,
            self::ALT   => $this->alt,
            self::CTRL  => $this->ctrl,
            default => throw new \InvalidArgumentException(
                Lang::t('modifiers.unknown_bit', ['modifier' => $modifier])
            ),
        };
    }
}

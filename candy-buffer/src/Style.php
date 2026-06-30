<?php

declare(strict_types=1);

namespace SugarCraft\Buffer;

/**
 * Minimal per-cell style record — foreground colour, background colour,
 * and a raw attribute bitmask.
 *
 * This is intentionally a stripped-down cousin of
 * {@see \SugarCraft\Sprinkles\Style}. The rich styling API (border,
 * padding, underline, transform, etc.) lives in candy-sprinkles and
 * builds on top of these primitives. Buffer/Cell carry only what the
 * terminal needs to emit SGR sequences.
 *
 * @readonly
 */
final class Style
{
    /** Attribute bits: bold=1, italic=2, underline=4, strike=8, … */
    public const ATTR_BOLD      = 1 << 0;
    public const ATTR_ITALIC   = 1 << 1;
    public const ATTR_UNDERLINE = 1 << 2;
    public const ATTR_STRIKE   = 1 << 3;
    public const ATTR_FAINT    = 1 << 4;
    public const ATTR_BLINK    = 1 << 5;
    public const ATTR_REVERSE  = 1 << 6;
    public const ATTR_OVERLINE  = 1 << 7;
    public const ATTR_INVISIBLE= 1 << 8;

    public function __construct(
        public readonly ?int $fg = null,       // 0xRRGGBB or null = default
        public readonly ?int $bg = null,       // 0xRRGGBB or null = default
        public readonly int $attrs = 0,       // bitmask of ATTR_* constants
    ) {}

    /**
     * Default factory.
     */
    public static function new(?int $fg = null, ?int $bg = null, int $attrs = 0): self
    {
        return new self($fg, $bg, $attrs);
    }

    /** Factory: bold. */
    public static function bold(): self
    {
        return new self(null, null, self::ATTR_BOLD);
    }

    /** Factory: reverse. */
    public static function reverse(): self
    {
        return new self(null, null, self::ATTR_REVERSE);
    }

    public function fg(): ?int      { return $this->fg; }
    public function bg(): ?int      { return $this->bg; }
    public function attrs(): int    { return $this->attrs; }

    public function hasBold(): bool      { return (bool)($this->attrs & self::ATTR_BOLD); }
    public function hasItalic(): bool    { return (bool)($this->attrs & self::ATTR_ITALIC); }
    public function hasUnderline(): bool  { return (bool)($this->attrs & self::ATTR_UNDERLINE); }
    public function hasStrike(): bool     { return (bool)($this->attrs & self::ATTR_STRIKE); }
    public function hasFaint(): bool    { return (bool)($this->attrs & self::ATTR_FAINT); }
    public function hasBlink(): bool    { return (bool)($this->attrs & self::ATTR_BLINK); }
    public function hasReverse(): bool   { return (bool)($this->attrs & self::ATTR_REVERSE); }
    public function hasOverline(): bool { return (bool)($this->attrs & self::ATTR_OVERLINE); }
    public function hasInvisible(): bool { return (bool)($this->attrs & self::ATTR_INVISIBLE); }

    // ─── Fluent builders ────────────────────────────────────────────────

    /**
     * @return static
     */
    private function mutate(array $changes): static
    {
        return new static(...array_merge(
            ['fg' => $this->fg, 'bg' => $this->bg, 'attrs' => $this->attrs],
            $changes,
        ));
    }

    /** Return a new Style with the given foreground colour. */
    public function withFg(?int $fg): self
    {
        return $this->mutate(['fg' => $fg]);
    }

    /** Return a new Style with the given background colour. */
    public function withBg(?int $bg): self
    {
        return $this->mutate(['bg' => $bg]);
    }

    /** Return a new Style with the given attribute bitmask (replaces). */
    public function withAttrs(int $attrs): self
    {
        return $this->mutate(['attrs' => $attrs]);
    }

    /** Return a new Style with bold toggled on or off. */
    public function withBold(bool $on = true): self
    {
        return $this->mutate(['attrs' => $on
            ? ($this->attrs | self::ATTR_BOLD)
            : ($this->attrs & ~self::ATTR_BOLD)]);
    }

    /** Return a new Style with reverse toggled on or off. */
    public function withReverse(bool $on = true): self
    {
        return $this->mutate(['attrs' => $on
            ? ($this->attrs | self::ATTR_REVERSE)
            : ($this->attrs & ~self::ATTR_REVERSE)]);
    }

    /**
     * Value equality: two styles are equal iff their fg, bg, and attrs
     * values are identical.
     */
    public function equals(self $other): bool
    {
        return $this->fg === $other->fg
            && $this->bg === $other->bg
            && $this->attrs === $other->attrs;
    }
}

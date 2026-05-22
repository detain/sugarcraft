<?php

declare(strict_types=1);

namespace SugarCraft\Vt;

/**
 * A single cell in the terminal grid for the vcr renderer path.
 *
 * Readonly value object — char, foreground (0-255), background (0-255),
 * and an attribute bitfield (bold/italic/underline/inverse/strikethrough).
 *
 * Mirrors charmbracelet/x/vt Cell (simplified for renderer use).
 */
final readonly class Cell
{
    public const ATTR_BOLD = 1 << 0;
    public const ATTR_ITALIC = 1 << 1;
    public const ATTR_UNDERLINE = 1 << 2;
    public const ATTR_INVERSE = 1 << 3;
    public const ATTR_STRIKETHROUGH = 1 << 4;

    public function __construct(
        public string $char = ' ',
        public int $fg = 7,
        public int $bg = 0,
        public int $attrs = 0,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @param array{char?:string, fg?:int, bg?:int, attrs?:int} $changes
     */
    private function mutate(array $changes): self
    {
        return new self(
            char: $changes['char'] ?? $this->char,
            fg: $changes['fg'] ?? $this->fg,
            bg: $changes['bg'] ?? $this->bg,
            attrs: $changes['attrs'] ?? $this->attrs,
        );
    }

    public function withFg(int $c): self
    {
        return $this->mutate(['fg' => $c]);
    }

    public function withBg(int $c): self
    {
        return $this->mutate(['bg' => $c]);
    }

    public function withAttrs(int $flags): self
    {
        return $this->mutate(['attrs' => $flags]);
    }

    public function equals(self $other): bool
    {
        return $this->char === $other->char
            && $this->fg === $other->fg
            && $this->bg === $other->bg
            && $this->attrs === $other->attrs;
    }
}

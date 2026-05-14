<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

use SugarCraft\Core\Util\ColorProfile;

/**
 * A parsed inline style from termui-style [text](fg:red,bg:blue) syntax.
 *
 * Mirrors termui state machine parsing for inline style annotations.
 */
final readonly class Style
{
    public function __construct(
        public ?Color $foreground = null,
        public ?Color $background = null,
        public bool $bold = false,
        public bool $dim = false,
        public bool $italic = false,
        public bool $underline = false,
        public bool $reverse = false,
        public bool $strike = false,
    ) {}

    /**
     * Render this style as ANSI SGR sequences.
     */
    public function toAnsi(ColorProfile $profile = ColorProfile::TrueColor): string
    {
        $codes = [];

        if ($this->foreground !== null) {
            $codes[] = $this->foreground->toFg($profile);
        }
        if ($this->background !== null) {
            $codes[] = $this->background->toBg($profile);
        }
        if ($this->bold)         { $codes[] = "\x1b[1m"; }
        if ($this->dim)          { $codes[] = "\x1b[2m"; }
        if ($this->italic)       { $codes[] = "\x1b[3m"; }
        if ($this->underline)    { $codes[] = "\x1b[4m"; }
        if ($this->reverse)      { $codes[] = "\x1b[7m"; }
        if ($this->strike)       { $codes[] = "\x1b[9m"; }

        return implode('', $codes);
    }

    /**
     * Apply this style's attributes to a foreground Color.
     */
    public function withForeground(Color $color): self
    {
        return new self(
            foreground: $color,
            background: $this->background,
            bold: $this->bold,
            dim: $this->dim,
            italic: $this->italic,
            underline: $this->underline,
            reverse: $this->reverse,
            strike: $this->strike,
        );
    }

    /**
     * Apply this style's attributes to a background Color.
     */
    public function withBackground(Color $color): self
    {
        return new self(
            foreground: $this->foreground,
            background: $color,
            bold: $this->bold,
            dim: $this->dim,
            italic: $this->italic,
            underline: $this->underline,
            reverse: $this->reverse,
            strike: $this->strike,
        );
    }

    /**
     * Add bold modifier.
     */
    public function withBold(bool $value = true): self
    {
        return new self(
            foreground: $this->foreground,
            background: $this->background,
            bold: $value,
            dim: $this->dim,
            italic: $this->italic,
            underline: $this->underline,
            reverse: $this->reverse,
            strike: $this->strike,
        );
    }
}

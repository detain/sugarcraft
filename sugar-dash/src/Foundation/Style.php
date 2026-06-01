<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Sugar-dash inline-termui Style. Intentionally distinct from
 * \SugarCraft\Sprinkles\Style — this carries the inline-termui
 * layout-engine semantics (toAnsi(ColorProfile), inline foreground/
 * background slots) while Sprinkles\Style carries the lipgloss
 * padding/margin/borders semantics. Both are canonical for their
 * lib — do NOT alias one to the other.
 *
 * See sugar-dash/CALIBER_LEARNINGS.md entry [pattern:dual-style-ssot].
 */
readonly class Style
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
        if ($this->bold)         { $codes[] = Ansi::sgr(Ansi::BOLD); }
        if ($this->dim)          { $codes[] = Ansi::sgr(Ansi::FAINT); }
        if ($this->italic)       { $codes[] = Ansi::sgr(Ansi::ITALIC); }
        if ($this->underline)    { $codes[] = Ansi::sgr(Ansi::UNDERLINE); }
        if ($this->reverse)      { $codes[] = Ansi::sgr(Ansi::REVERSE); }
        if ($this->strike)       { $codes[] = Ansi::sgr(Ansi::STRIKE); }

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

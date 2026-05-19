<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Sgr;

use SugarCraft\Vt\Color\Color;

/**
 * Select Graphic Rendition flags — bold, italic, underline, etc.
 *
 * Mirrors charmbracelet/x/vt SGR.
 */
final readonly class Sgr
{
    public function __construct(
        public bool $bold = false,
        public bool $italic = false,
        public bool $underline = false,
        public UnderlineStyle $underlineStyle = UnderlineStyle::None,
        public bool $strikethrough = false,
        public bool $blink = false,
        public bool $reverse = false,
        public bool $dim = false,
        public bool $hidden = false,
        public bool $invisible = false,
        public ?Color $foreground = null,
        public ?Color $background = null,
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    public function withBold(bool $v): self
    {
        return new self(
            bold: $v,
            italic: $this->italic,
            underline: $this->underline,
            underlineStyle: $this->underlineStyle,
            strikethrough: $this->strikethrough,
            blink: $this->blink,
            reverse: $this->reverse,
            dim: $this->dim,
            hidden: $this->hidden,
            invisible: $this->invisible,
            foreground: $this->foreground,
            background: $this->background,
        );
    }

    public function withItalic(bool $v): self
    {
        return new self(
            bold: $this->bold, italic: $v,
            underline: $this->underline, underlineStyle: $this->underlineStyle,
            strikethrough: $this->strikethrough,
            blink: $this->blink, reverse: $this->reverse,
            dim: $this->dim, hidden: $this->hidden,
            invisible: $this->invisible,
            foreground: $this->foreground, background: $this->background,
        );
    }

    public function withUnderline(bool $v): self
    {
        return new self(
            bold: $this->bold, italic: $this->italic,
            underline: $v, underlineStyle: $this->underlineStyle,
            strikethrough: $this->strikethrough,
            blink: $this->blink, reverse: $this->reverse,
            dim: $this->dim, hidden: $this->hidden,
            invisible: $this->invisible,
            foreground: $this->foreground, background: $this->background,
        );
    }

    public function withUnderlineStyle(UnderlineStyle $style): self
    {
        return new self(
            bold: $this->bold, italic: $this->italic,
            underline: $style !== UnderlineStyle::None,
            underlineStyle: $style,
            strikethrough: $this->strikethrough,
            blink: $this->blink, reverse: $this->reverse,
            dim: $this->dim, hidden: $this->hidden,
            invisible: $this->invisible,
            foreground: $this->foreground, background: $this->background,
        );
    }

    public function withStrikethrough(bool $v): self
    {
        return new self(
            bold: $this->bold, italic: $this->italic,
            underline: $this->underline, underlineStyle: $this->underlineStyle,
            strikethrough: $v,
            blink: $this->blink, reverse: $this->reverse,
            dim: $this->dim, hidden: $this->hidden,
            invisible: $this->invisible,
            foreground: $this->foreground, background: $this->background,
        );
    }

    public function withBlink(bool $v): self
    {
        return new self(
            bold: $this->bold, italic: $this->italic,
            underline: $this->underline, underlineStyle: $this->underlineStyle,
            strikethrough: $this->strikethrough,
            blink: $v, reverse: $this->reverse,
            dim: $this->dim, hidden: $this->hidden,
            invisible: $this->invisible,
            foreground: $this->foreground, background: $this->background,
        );
    }

    public function withReverse(bool $v): self
    {
        return new self(
            bold: $this->bold, italic: $this->italic,
            underline: $this->underline, underlineStyle: $this->underlineStyle,
            strikethrough: $this->strikethrough,
            blink: $this->blink, reverse: $v,
            dim: $this->dim, hidden: $this->hidden,
            invisible: $this->invisible,
            foreground: $this->foreground, background: $this->background,
        );
    }

    public function withDim(bool $v): self
    {
        return new self(
            bold: $this->bold, italic: $this->italic,
            underline: $this->underline, underlineStyle: $this->underlineStyle,
            strikethrough: $this->strikethrough,
            blink: $this->blink, reverse: $this->reverse,
            dim: $v, hidden: $this->hidden,
            invisible: $this->invisible,
            foreground: $this->foreground, background: $this->background,
        );
    }

    public function withHidden(bool $v): self
    {
        return new self(
            bold: $this->bold, italic: $this->italic,
            underline: $this->underline, underlineStyle: $this->underlineStyle,
            strikethrough: $this->strikethrough,
            blink: $this->blink, reverse: $this->reverse,
            dim: $this->dim, hidden: $v,
            invisible: $this->invisible,
            foreground: $this->foreground, background: $this->background,
        );
    }

    public function withForeground(?Color $c): self
    {
        return new self(
            bold: $this->bold, italic: $this->italic,
            underline: $this->underline, underlineStyle: $this->underlineStyle,
            strikethrough: $this->strikethrough,
            blink: $this->blink, reverse: $this->reverse,
            dim: $this->dim, hidden: $this->hidden,
            invisible: $this->invisible,
            foreground: $c, background: $this->background,
        );
    }

    public function withBackground(?Color $c): self
    {
        return new self(
            bold: $this->bold, italic: $this->italic,
            underline: $this->underline, underlineStyle: $this->underlineStyle,
            strikethrough: $this->strikethrough,
            blink: $this->blink, reverse: $this->reverse,
            dim: $this->dim, hidden: $this->hidden,
            invisible: $this->invisible,
            foreground: $this->foreground, background: $c,
        );
    }

    public function equals(self $other): bool
    {
        if ($this->bold !== $other->bold) return false;
        if ($this->italic !== $other->italic) return false;
        if ($this->underline !== $other->underline) return false;
        if ($this->underlineStyle !== $other->underlineStyle) return false;
        if ($this->strikethrough !== $other->strikethrough) return false;
        if ($this->blink !== $other->blink) return false;
        if ($this->reverse !== $other->reverse) return false;
        if ($this->dim !== $other->dim) return false;
        if ($this->hidden !== $other->hidden) return false;
        if ($this->invisible !== $other->invisible) return false;

        $thisFg = $this->foreground;
        $otherFg = $other->foreground;
        if ($thisFg === null && $otherFg === null) {
            $fgEqual = true;
        } elseif ($thisFg === null || $otherFg === null) {
            $fgEqual = false;
        } else {
            $fgEqual = $thisFg->equals($otherFg);
        }

        $thisBg = $this->background;
        $otherBg = $other->background;
        if ($thisBg === null && $otherBg === null) {
            $bgEqual = true;
        } elseif ($thisBg === null || $otherBg === null) {
            $bgEqual = false;
        } else {
            $bgEqual = $thisBg->equals($otherBg);
        }

        return $fgEqual && $bgEqual;
    }
}

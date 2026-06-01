<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Output;

/**
 * Immutable snapshot of the current SGR (Select Graphic Rendition) state.
 *
 * Tracks foreground color, background color, and text attributes as set
 * by ANSI SGR sequences (e.g. ESC[31m for red foreground).
 *
 * @readonly
 * @immutable
 */
final readonly class SgrState
{
    /** Standard ANSI 3/4-bit colors. */
    public const COLOR_BLACK = 0;
    public const COLOR_RED = 1;
    public const COLOR_GREEN = 2;
    public const COLOR_YELLOW = 3;
    public const COLOR_BLUE = 4;
    public const COLOR_MAGENTA = 5;
    public const COLOR_CYAN = 6;
    public const COLOR_WHITE = 7;
    public const COLOR_DEFAULT = 9;

    /** 256-color and RGB sentinel values. */
    public const COLOR_256 = -1;
    public const COLOR_RGB = -2;
    public const COLOR_DEFAULT_256 = -3;

    public function __construct(
        public int $foreground = self::COLOR_DEFAULT,
        public int $background = self::COLOR_DEFAULT,
        public bool $bold = false,
        public bool $italic = false,
        public bool $underline = false,
        public bool $reverse = false,
        public bool $strike = false,
        public bool $dim = false,
        public bool $invisible = false,
        public bool $blink = false,
        /** 0-255 for 256-color, or r<<16|g<<8|b for 24-bit RGB */
        public int $foreground256 = self::COLOR_256,
        public int $background256 = self::COLOR_256,
        public int $foregroundRgb = 0,
        public int $backgroundRgb = 0,
    ) {}

    /**
     * Returns true when $this represents the same visual style as $other.
     * Used to detect SGR transitions in output tests.
     */
    public function equals(self $other): bool
    {
        return $this->foreground === $other->foreground
            && $this->background === $other->background
            && $this->bold === $other->bold
            && $this->italic === $other->italic
            && $this->underline === $other->underline
            && $this->reverse === $other->reverse
            && $this->strike === $other->strike
            && $this->dim === $other->dim
            && $this->invisible === $other->invisible
            && $this->blink === $other->blink
            && $this->foreground256 === $other->foreground256
            && $this->background256 === $other->background256
            && $this->foregroundRgb === $other->foregroundRgb
            && $this->backgroundRgb === $other->backgroundRgb;
    }

    /** Describe the state as a human-readable string for debugging. */
    public function describe(): string
    {
        $parts = [];
        if ($this->bold)           $parts[] = 'bold';
        if ($this->italic)         $parts[] = 'italic';
        if ($this->underline)      $parts[] = 'underline';
        if ($this->reverse)       $parts[] = 'reverse';
        if ($this->strike)         $parts[] = 'strike';
        if ($this->dim)            $parts[] = 'dim';
        if ($this->blink)          $parts[] = 'blink';
        if ($this->invisible)      $parts[] = 'invisible';

        $fg = $this->colorName($this->foreground, $this->foreground256, $this->foregroundRgb, 'fg');
        $bg = $this->colorName($this->background, $this->background256, $this->backgroundRgb, 'bg');

        if ($fg !== null) $parts[] = $fg;
        if ($bg !== null) $parts[] = $bg;

        return $parts === [] ? 'default' : \implode(' ', $parts);
    }

    private function colorName(int $basic, int $c256, int $rgb, string $which): ?string
    {
        if ($rgb !== 0) {
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            return "{$which}=rgb({$r},{$g},{$b})";
        }
        if ($c256 !== self::COLOR_256 && $c256 >= 0) {
            return "{$which}={$c256}";
        }
        if ($basic >= 0 && $basic <= 7) {
            $names = ['black', 'red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'white'];
            return "{$which}={$names[$basic]}";
        }
        return null;
    }
}

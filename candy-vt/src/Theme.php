<?php

declare(strict_types=1);

namespace SugarCraft\Vt;

use SugarCraft\Vt\Theme\TokyoNight;
use SugarCraft\Vt\Theme\TokyoNightLight;
use SugarCraft\Vt\Theme\TokyoNightStorm;

/**
 * Terminal theme with 256-color palette and default fg/bg.
 *
 * Mirrors charmbracelet/x/vt Theme (simplified for renderer use).
 */
final class Theme
{
    public const ATTR_BOLD = 1 << 0;
    public const ATTR_ITALIC = 1 << 1;
    public const ATTR_UNDERLINE = 1 << 2;
    public const ATTR_INVERSE = 1 << 3;
    public const ATTR_STRIKETHROUGH = 1 << 4;

    public const ANSI_OFFSET = 0;
    public const CUBE_OFFSET = 16;
    public const GRAYSCALE_OFFSET = 232;

    /** @var array<int, int> 256-color palette as RGB ints (0xRRGGBB). */
    private array $palette;

    /** @var array<int, int> Maps ANSI slot 0-15 → 256-color index (foreground). */
    private static array $fgIndexMap = [];

    /** @var array<int, int> Maps ANSI slot 0-15 → 256-color index (background). */
    private static array $bgIndexMap = [];

    public function __construct(
        public readonly int $defaultFg = 7,
        public readonly int $defaultBg = 0,
        ?array $palette = null,
    ) {
        $this->palette = $palette ?? self::defaultPalette();
    }

    public static function defaultPalette(): array
    {
        return [
            0x000000, 0x800000, 0x008000, 0x808000, 0x000080, 0x800080, 0x008080, 0xc0c0c0,
            0x808080, 0xff0000, 0x00ff00, 0xffff00, 0x0000ff, 0xff00ff, 0x00ffff, 0xffffff,
        ] + self::cubePalette();
    }

    private static function cubePalette(): array
    {
        $cube = [];
        for ($r = 0; $r < 6; $r++) {
            for ($g = 0; $g < 6; $g++) {
                for ($b = 0; $b < 6; $b++) {
                    $cube[] = (($r ? $r * 40 + 55 : 0) << 16) | (($g ? $g * 40 + 55 : 0) << 8) | ($b ? $b * 40 + 55 : 0);
                }
            }
        }
        return $cube;
    }

    public function color(int $index): int
    {
        if ($index < 0 || $index > 255) {
            return 0;
        }
        if (isset($this->palette[$index])) {
            return $this->palette[$index];
        }
        // Palette only carries 0..15 + 6x6x6 cube (16..231). For the
        // grayscale ramp (232..255) — and any unset slot — fall back
        // to the standard xterm 256 mapping computed by self::rgb().
        [$r, $g, $b] = self::rgb($index);
        return ($r << 16) | ($g << 8) | $b;
    }

    public static function tokyoNight(): self
    {
        return new self(
            defaultFg: 7,
            defaultBg: 0,
            palette: [
                0x15161e,
                0xf7768e,
                0x9ece6a,
                0xe0af68,
                0x7aa2f7,
                0xbb9af7,
                0x7dcfff,
                0xa9b1d6,
                0x414868,
                0xf7768e,
                0x9ece6a,
                0xe0af68,
                0x7aa2f7,
                0xbb9af7,
                0x7dcfff,
                0xc0caf5,
            ] + self::cubePalette(),
        );
    }

    public static function dracula(): self
    {
        return new self(
            defaultFg: 7,
            defaultBg: 0,
            palette: [
                0x21222c,
                0xff5555,
                0x50fa7b,
                0xf1fa8c,
                0x6272a4,
                0xff79c6,
                0x8be9fd,
                0xf8f8f2,
                0x6272a4,
                0xff6e6e,
                0x69ff94,
                0xffffa5,
                0xd6acff,
                0xff92df,
                0xa4ffff,
                0xffffff,
            ] + self::cubePalette(),
        );
    }

    public static function solarizedDark(): self
    {
        return new self(
            defaultFg: 7,
            defaultBg: 0,
            palette: [
                0x073642,
                0xdc322f,
                0x859900,
                0xb58900,
                0x268bd2,
                0xd33682,
                0x2aa198,
                0xeee8d5,
                0x586e75,
                0xcb4b16,
                0x93a1a1,
                0x839496,
                0x6c71c4,
                0xcb4b16,
                0x2aa198,
                0xfdf6e3,
            ] + self::cubePalette(),
        );
    }

    /**
     * Tokyo Night Light theme variant.
     */
    public static function tokyoNightLight(): self
    {
        return new self(
            defaultFg: 7,
            defaultBg: 15,
            palette: [
                0x15161e,
                0xf7768e,
                0x9ece6a,
                0xe0af68,
                0x7aa2f7,
                0xbb9af7,
                0x7dcfff,
                0xa9b1d6,
                0x414868,
                0xf7768e,
                0x9ece6a,
                0xe0af68,
                0x7aa2f7,
                0xbb9af7,
                0x7dcfff,
                0xc0caf5,
            ] + self::cubePalette(),
        );
    }

    /**
     * Tokyo Night Storm theme variant.
     */
    public static function tokyoNightStorm(): self
    {
        return new self(
            defaultFg: 7,
            defaultBg: 0,
            palette: [
                0x1a1b26,
                0xf7768e,
                0x9ece6a,
                0xe0af68,
                0x7aa2f7,
                0xbb9af7,
                0x7dcfff,
                0xc0caf5,
                0x414868,
                0xf7768e,
                0x9ece6a,
                0xe0af68,
                0x7aa2f7,
                0xbb9af7,
                0x7dcfff,
                0xc0caf5,
            ] + self::cubePalette(),
        );
    }

    /**
     * Given an ANSI slot 0-15, return the corresponding 256-color index for foreground.
     */
    public static function fgIndex(int $slot): int
    {
        if ($slot < 0 || $slot > 15) {
            return 0;
        }
        self::buildAnsiMaps();
        return self::$fgIndexMap[$slot];
    }

    /**
     * Given an ANSI slot 0-15, return the corresponding 256-color index for background.
     */
    public static function bgIndex(int $slot): int
    {
        if ($slot < 0 || $slot > 15) {
            return 0;
        }
        self::buildAnsiMaps();
        return self::$bgIndexMap[$slot];
    }

    /**
     * Given a 256-color index, return [r, g, b].
     *
     * @return array{0:int, 1:int, 2:int}
     */
    public static function rgb(int $index): array
    {
        if ($index < 0 || $index > 255) {
            return [0, 0, 0];
        }

        if ($index < 16) {
            /** @var int $color */
            $color = self::defaultPalette()[$index];
            return self::fromRgbInt($color);
        }

        if ($index < 232) {
            $adjusted = $index - 16;
            $r = (int) floor($adjusted / 36);
            $g = (int) floor(($adjusted % 36) / 6);
            $b = $adjusted % 6;
            return [
                $r ? $r * 40 + 55 : 0,
                $g ? $g * 40 + 55 : 0,
                $b ? $b * 40 + 55 : 0,
            ];
        }

        $gray = (int) floor(($index - 232) * 10 + 8);
        return [$gray, $gray, $gray];
    }

    /**
     * @return array{0:int, 1:int, 2:int}
     */
    private static function fromRgbInt(int $rgb): array
    {
        return [
            ($rgb >> 16) & 0xff,
            ($rgb >> 8) & 0xff,
            $rgb & 0xff,
        ];
    }

    private static function buildAnsiMaps(): void
    {
        if (self::$fgIndexMap !== []) {
            return;
        }

        self::$fgIndexMap = [
            0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15,
        ];
        self::$bgIndexMap = [
            0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15,
        ];
    }
}

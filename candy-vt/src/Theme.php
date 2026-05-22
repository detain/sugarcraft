<?php

declare(strict_types=1);

namespace SugarCraft\Vt;

/**
 * Terminal theme with 256-color palette and default fg/bg.
 *
 * Mirrors charmbracelet/x/vt Theme (simplified for renderer use).
 */
final class Theme
{
    /** @var array<int, int> 256-color palette as RGB ints (0xRRGGBB). */
    private array $palette;

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
        return $this->palette[$index] ?? 0;
    }
}

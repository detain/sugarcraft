<?php

declare(strict_types=1);

namespace CandyCore\Flip;

/**
 * Render a {@see Frame} as ANSI-coloured Unicode block-glyphs.
 *
 * Two presets:
 *   - `solid`    — every cell is `█` painted in the cell's RGB.
 *                  Looks like a real image; takes a wide terminal.
 *   - `density`  — pick a glyph from a luminance ramp (` .:-=+*#%@`).
 *                  Reads as ASCII art, easier on narrow windows.
 */
final class Renderer
{
    public const PRESET_SOLID   = 'solid';
    public const PRESET_DENSITY = 'density';

    private const RAMP = ' .:-=+*#%@';

    public static function render(Frame $f, string $preset = self::PRESET_SOLID): string
    {
        $rows = [];
        foreach ($f->cells as $row) {
            $line = '';
            foreach ($row as [$r, $g, $b]) {
                $line .= self::cell($r, $g, $b, $preset);
            }
            $rows[] = $line . "\033[0m";
        }
        return implode("\n", $rows);
    }

    private static function cell(int $r, int $g, int $b, string $preset): string
    {
        if ($preset === self::PRESET_DENSITY) {
            // 0.299r + 0.587g + 0.114b is the standard luminance weight.
            $lum = (int) (0.299 * $r + 0.587 * $g + 0.114 * $b);
            $idx = (int) round($lum / 255 * (strlen(self::RAMP) - 1));
            $glyph = self::RAMP[$idx] ?? ' ';
            return sprintf("\033[38;2;%d;%d;%dm%s", $r, $g, $b, $glyph);
        }
        // Solid block — full-cell colour fill via 24-bit truecolor escape.
        return sprintf("\033[48;2;%d;%d;%dm ", $r, $g, $b);
    }
}

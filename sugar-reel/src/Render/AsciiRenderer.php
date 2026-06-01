<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Render;

use SugarCraft\Palette\Color;
use SugarCraft\Reel\Decode\RgbFrame;

/**
 * ASCII / ANSI renderer — maps each pixel to a grayscale character
 * optionally colored via SGR escape sequences.
 *
 * For TrueColor mode: grayscale char + 38;2;R;G;B foreground.
 * For Ansi256 mode:   grayscale char + 38;5;N foreground (Color::toAnsi256Index).
 * For Ascii mode:     grayscale char only, no color.
 *
 * Luminance formula: BT.709 Y = (77*R + 150*G + 29*B) >> 8
 *
 * Mirrors charmbracelet/sugar-reel Render.AsciiRenderer.
 */
final class AsciiRenderer implements FrameRenderer
{
    /**
     * @inheritDoc
     */
    public function render(RgbFrame $frame, Mode $mode): string
    {
        if ($frame->w <= 0 || $frame->h <= 0) {
            return '';
        }

        $bytes = $frame->bytes;
        $w = $frame->w;
        $h = $frame->h;
        $len = \strlen($bytes);

        $reset = "\x1b[0m";
        $lines = [];

        // Row-by-row rendering: each pixel → luma char (+ optional color).
        for ($y = 0; $y < $h; $y++) {
            $line = '';
            $rowOffset = $y * $w * 3;

            for ($x = 0; $x < $w; $x++) {
                $idx = $rowOffset + ($x * 3);

                // Guard against undersized byte buffer.
                if ($idx + 2 >= $len) {
                    $r = $g = $b = 0;
                } else {
                    $r = \ord($bytes[$idx]);
                    $g = \ord($bytes[$idx + 1]);
                    $b = \ord($bytes[$idx + 2]);
                }

                // BT.709 luminance: integer approximation (77*R + 150*G + 29*B) >> 8.
                $luma = (($r * 77) + ($g * 150) + ($b * 29)) >> 8;
                $ch = LumaRamp::char((float)$luma);

                $line .= match ($mode) {
                    // TrueColor: grayscale char colored with 24-bit foreground.
                    Mode::TrueColor => Color::fromHex(self::rgbToHex($r, $g, $b))->toAnsiForeground()
                        . $ch
                        . $reset,

                    // Ansi256: grayscale char colored with 256-color foreground.
                    // Uses SugarCraft\Palette\Color::toAnsi256Index() (candy-palette Color.php:97).
                    Mode::Ansi256 => self::ansi256Fg($r, $g, $b) . $ch . $reset,

                    // Ascii: grayscale char only, no color.
                    Mode::Ascii => $ch,

                    default => $ch,
                };
            }

            $lines[] = $line;
        }

        return \implode("\r\n", $lines);
    }

    /**
     * @inheritDoc
     */
    public function cellDimensions(Mode $mode): array
    {
        // All ASCII modes render one source pixel per terminal cell.
        return ['w' => 1, 'h' => 1];
    }

    /**
     * Build a 38;5;N ANSI 256-color foreground escape for RGB.
     *
     * Reuses SugarCraft\Palette\Color::toAnsi256Index() from candy-palette Color.php:97.
     */
    private function ansi256Fg(int $r, int $g, int $b): string
    {
        $color = new Color($r, $g, $b);
        $idx = $color->toAnsi256Index();

        return "\x1b[38;5;{$idx}m";
    }

    /**
     * Pack RGB ints into 0xRRGGBB hex integer.
     */
    private static function rgbToHex(int $r, int $g, int $b): int
    {
        return (($r & 0xff) << 16) | (($g & 0xff) << 8) | ($b & 0xff);
    }
}

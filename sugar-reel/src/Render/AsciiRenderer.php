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
 * Luminance formula: BT.601 (SMPTE-C) Y = (77*R + 150*G + 29*B) >> 8
 *
 * No single upstream — drawn from maxcurzi/tplay, seatedro/glyph, joelibaceta/video-to-ascii.
 */
final class AsciiRenderer implements FrameRenderer
{
    public function __construct(private readonly string $ramp = 'standard')
    {
    }

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

        for ($y = 0; $y < $h; $y++) {
            $line = '';
            $rowOffset = $y * $w * 3;
            $lastFg = null; // null = no active color

            for ($x = 0; $x < $w; $x++) {
                $idx = $rowOffset + ($x * 3);
                if ($idx + 2 >= $len) {
                    $r = $g = $b = 0;
                } else {
                    $r = \ord($bytes[$idx]);
                    $g = \ord($bytes[$idx + 1]);
                    $b = \ord($bytes[$idx + 2]);
                }
                $luma = (($r * 77) + ($g * 150) + ($b * 29)) >> 8;
                $ch = LumaRamp::char((float)$luma, $this->ramp);

                $line .= match ($mode) {
                    Mode::TrueColor => $this->emitColorCode($r, $g, $b, $lastFg) . $ch,
                    Mode::Ansi256 => $this->emitAnsi256Code($r, $g, $b, $lastFg) . $ch,
                    Mode::Ascii => $ch,
                    default => $ch,
                };
                // Update lastFg after emitting (even for Ascii which doesn't change it).
                if ($mode === Mode::TrueColor) {
                    $lastFg = ($r << 16) | ($g << 8) | $b;
                } elseif ($mode === Mode::Ansi256) {
                    $lastFg = (new Color($r, $g, $b))->toAnsi256Index();
                }
            }

            // End of line: reset SGR if a color was active.
            if ($lastFg !== null) {
                $line .= $reset;
            }
            $lines[] = $line;
        }

        return \implode("\r\n", $lines);
    }

    /**
     * Emit a TrueColor SGR only when the foreground color changes.
     * Returns '' when $fg === $lastFg (no change needed).
     */
    private function emitColorCode(int $r, int $g, int $b, mixed $lastFg): string
    {
        $fg = ($r << 16) | ($g << 8) | $b;
        if ($fg === $lastFg) {
            return '';
        }
        return "\x1b[38;2;{$r};{$g};{$b}m";
    }

    /**
     * Emit an ANSI 256 SGR only when the foreground color index changes.
     * Returns '' when $idx === $lastFg (no change needed).
     */
    private function emitAnsi256Code(int $r, int $g, int $b, mixed $lastFg): string
    {
        $idx = (new Color($r, $g, $b))->toAnsi256Index();
        if ($idx === $lastFg) {
            return '';
        }
        return "\x1b[38;5;{$idx}m";
    }

    /**
     * @inheritDoc
     */
    public function cellDimensions(Mode $mode): array
    {
        // All ASCII modes render one source pixel per terminal cell.
        return ['w' => 1, 'h' => 1];
    }

}

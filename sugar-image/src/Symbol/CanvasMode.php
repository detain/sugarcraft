<?php

declare(strict_types=1);

namespace SugarCraft\Image\Symbol;

use SugarCraft\ColorProfile\Color;
use SugarCraft\Core\Util\ColorUtil;
use SugarCraft\Image\Support\OutputOptimizer;

/**
 * Terminal canvas colour modes — the fixed-palette quantisation ladder.
 *
 * Mirrors chafa's ChafaCanvasMode (CHAFA_MODE_TRUECOLOR / _240 / _256 /
 * _16 / _8 / _FG_BG). PC-14: candidate scoring in the symbol engine must
 * use the SAME quantised pens it will emit — with a limited palette the
 * best glyph is not the best truecolor glyph. Adaptive palettes are
 * deliberately out of scope (pixel-side only, per S6.2 ruling).
 *
 *  - TrueColor: identity; pens emit as direct RGB SGR.
 *  - Ansi256:   pens snap to the xterm 256-slot cube + greyscale ramp.
 *  - Ansi16:    pens snap to the sixteen system colours (bright set
 *               included — chafa's MODE_16); emitted as 3x/4x codes.
 *  - Fgbg:      only the terminal's default foreground and background
 *               are available; scoring pens snap to the white/black
 *               reference pair and nothing is emitted (defaults ride).
 */
enum CanvasMode: string
{
    case TrueColor = 'truecolor';
    case Ansi256 = 'ansi256';
    case Ansi16 = 'ansi16';
    case Fgbg = 'fgbg';

    /**
     * Snap an RGB triple onto this mode's fixed palette.
     *
     * @param array{int,int,int} $rgb
     *
     * @return array{int,int,int}
     */
    public function quantizeRgb(array $rgb): array
    {
        [$r, $g, $b] = $rgb;

        return match ($this) {
            self::TrueColor => $rgb,
            self::Ansi256 => (static function (int $r, int $g, int $b): array {
                $color = Color::fromAnsi256Index((new Color($r, $g, $b))->toAnsi256Index());

                return [$color->r, $color->g, $color->b];
            })($r, $g, $b),
            self::Ansi16 => Color::ANSI16_RGB[(new Color($r, $g, $b))->toAnsi16Index()],
            self::Fgbg => ColorUtil::luma($r, $g, $b) >= 128 ? [255, 255, 255] : [0, 0, 0],
        };
    }

    /** Whether glyph-cell colour scoring must quantize its mean pens. */
    public function quantizes(): bool
    {
        return $this !== self::TrueColor;
    }

    /**
     * SGR parameter string for a foreground pen, or null to let the
     * terminal default through (Fgbg).
     *
     * @param ?array{int,int,int} $rgb
     */
    public function fgSgr(?array $rgb): ?string
    {
        if ($rgb === null || $this === self::Fgbg) {
            return null;
        }

        return match ($this) {
            self::TrueColor => OutputOptimizer::trueColor($rgb[0], $rgb[1], $rgb[2]),
            self::Ansi256 => OutputOptimizer::indexed256((new Color(...$rgb))->toAnsi256Index()),
            self::Ansi16 => (string) Color::ansi16Sgr((new Color(...$rgb))->toAnsi16Index(), false),
            self::Fgbg => null,
        };
    }

    /**
     * SGR parameter string for a background pen, or null (Fgbg).
     *
     * @param ?array{int,int,int} $rgb
     */
    public function bgSgr(?array $rgb): ?string
    {
        if ($rgb === null || $this === self::Fgbg) {
            return null;
        }

        return match ($this) {
            self::TrueColor => OutputOptimizer::trueColor($rgb[0], $rgb[1], $rgb[2]),
            self::Ansi256 => OutputOptimizer::indexed256((new Color(...$rgb))->toAnsi256Index()),
            self::Ansi16 => (string) Color::ansi16Sgr((new Color(...$rgb))->toAnsi16Index(), true),
            self::Fgbg => null,
        };
    }
}

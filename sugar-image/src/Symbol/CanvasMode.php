<?php

declare(strict_types=1);

namespace SugarCraft\Image\Symbol;

use SugarCraft\ColorProfile\Color;
use SugarCraft\Core\Util\ColorUtil;
use SugarCraft\Image\Support\OutputOptimizer;

/**
 * Terminal canvas colour modes — the fixed-palette quantisation ladder.
 *
 * Corresponds to FOUR of the eight real members of chafa's
 * ChafaCanvasMode enum (chafa/chafa-common.h:213-226; chafa's spellings
 * are CHAFA_CANVAS_MODE_*, not the shorthand CHAFA_MODE_*). The per-case
 * mapping, with chafa enum member lines cited:
 *
 *  - TrueColor: CHAFA_CANVAS_MODE_TRUECOLOR (chafa-common.h:215);
 *               identity; pens emit as direct RGB SGR.
 *  - Ansi256:   CHAFA_CANVAS_MODE_INDEXED_256 (:216); pens snap to the
 *               xterm 256-slot cube + greyscale ramp, lower 16 slots
 *               included (as chafa's mode does).
 *  - Ansi16:    CHAFA_CANVAS_MODE_INDEXED_16 (:218); pens snap to the
 *               sixteen system colours, bright set included — emitted
 *               3x/4x for the basics and aixterm 9x/10x for the brights
 *               (the same extension chafa's doc at :205 names).
 *  - Fgbg:      CHAFA_CANVAS_MODE_FGBG (:220), "Default foreground and
 *               background colors. No ANSI codes will be used" (:209);
 *               fgSgr() and bgSgr() both return null (defaults ride).
 *               Scoring pens snap to a white/black reference pair —
 *               that pairing is a SugarCraft choice: chafa instead
 *               treats the FGBG fg leg as the terminal default and
 *               rewires the canvas around it (disabling inversion
 *               consideration and colour extraction and forcing its
 *               fg-only path, chafa-canvas.c:556-563). Here the ladder
 *               stays orthogonal — callers pick the ascii path, not
 *               the mode.
 *
 * Vocabulary divergences from chafa: INDEXED_240 (:217, "avoid using
 * the lower 16 whose values vary between terminal environments") and
 * INDEXED_8 (:221, original ANSI X3.64 eight) exist as the
 * ColorProfile::Ansi240 / ColorProfile::Ansi8 rungs in sugar-colorprofile
 * (src/ColorProfile.php:57,63), NOT as CanvasMode cases; FGBG_BGFG (:219,
 * defaults plus inversion) and INDEXED_16_8 (:222, 16 FG with
 * bold/bright over 8 BG) have no counterpart anywhere in the monorepo.
 *
 * PC-14: candidate scoring in the symbol engine must use the SAME
 * quantised pens it will emit — with a limited palette the best glyph
 * is not the best truecolor glyph. Adaptive palettes are deliberately
 * out of scope (pixel-side only, per S6.2 ruling).
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

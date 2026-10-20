<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

use SugarCraft\Core\Util\ColorUtil;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Lang;
use SugarCraft\Image\PixelGrid;
use SugarCraft\Image\Support\OutputOptimizer;

/**
 * Quarter-block Unicode renderer using 16 glyphs (▘▝▖▗▀▄▌▐▙▟▛▜▞▚█).
 * Each terminal cell renders a 2×2 group of pixels; the glyph selection
 * encodes which quadrants are bright via foreground colour and which are
 * dim via background colour (both drawn from the same image pixel).
 *
 * Cell selection is an exhaustive minimum-error search over all 16 glyph
 * masks (A-03): each membership split's pens are the group averages and the
 * winner is the split minimising summed squared colour distance. Because
 * complement(mask) with swapped pens paints identical pixels, the search
 * evaluates the 8 representatives 1..7 and resolves each split's pen
 * assignment by group luma (brighter group rides the foreground).
 *
 * Transparency is per quad (A-05a): a fully-transparent cell renders blank
 * (terminal default on both pens), and a partly-transparent cell renders
 * foreground-only — the filled mask is pinned to the opaque quadrant set so
 * transparent quadrants never paint black. Semi-transparent pixels follow the
 * lib-wide binary-alpha rule (see {@see PixelGrid}).
 *
 * Higher visual fidelity than HalfBlockRenderer when Sixel/Kitty are
 * unavailable: 4 sub-pixel positions per cell vs 2.
 */
final class QuarterBlockRenderer implements Renderer
{
    use \SugarCraft\Image\Concerns\RenderValidationTrait;
    /**
     * 16 Unicode quadrant glyphs indexed by a 4-bit mask of which quadrants
     * belong to the FOREGROUND group: bit 0 = upper-left, bit 1 = upper-right,
     * bit 2 = lower-left, bit 3 = lower-right. Filled quadrants are drawn in the
     * foreground colour, the rest in the background colour — two colours per cell
     * across four sub-pixel positions (vs half-block's two).
     *
     * @var array<int, string>
     */
    private const GLYPH_MAP = [
        0  => ' ',
        1  => "\u{2598}", // ▘ UL
        2  => "\u{259D}", // ▝ UR
        3  => "\u{2580}", // ▀ UL+UR
        4  => "\u{2596}", // ▖ LL
        5  => "\u{258C}", // ▌ UL+LL
        6  => "\u{259E}", // ▞ UR+LL
        7  => "\u{259B}", // ▛ UL+UR+LL
        8  => "\u{2597}", // ▗ LR
        9  => "\u{259A}", // ▚ UL+LR
        10 => "\u{2590}", // ▐ UR+LR
        11 => "\u{259C}", // ▜ UL+UR+LR
        12 => "\u{2584}", // ▄ LL+LR
        13 => "\u{2599}", // ▙ UL+LL+LR
        14 => "\u{259F}", // ▟ UR+LL+LR
        15 => "\u{2588}", // █ all four
    ];

    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        $effectiveHeight = $this->prepareRender($image, $width, $height);

        $img = imagecreatefromstring($image->bytes);
        if ($img === false) {
            throw new \RuntimeException(Lang::t('renderer.gd_load_failed'));
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        try {
            $grid = PixelGrid::fromGdQuarter($img, $width, $effectiveHeight);
        } finally {
            imagedestroy($img);
        }

        $pen = new OutputOptimizer();
        $lines = [];
        foreach ($grid->cells as $row) {
            foreach ($row as $quads) {
                [$glyph, $fg, $bg] = self::cellPaint($quads);
                $pen->cell(
                    $glyph,
                    $fg === null ? null : OutputOptimizer::trueColor($fg[0], $fg[1], $fg[2]),
                    $bg === null ? null : OutputOptimizer::trueColor($bg[0], $bg[1], $bg[2]),
                );
            }
            $lines[] = $pen->finishRow();
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve one 2×2 cell to (glyph, foreground, background). All four
     * quadrants opaque: an exhaustive minimum-error search over the glyph
     * masks (A-03) picks the membership split whose group averages best
     * reproduce the quadrant colours — complement(mask) with swapped pens
     * paints identical pixels, so masks 1..7 cover every split, and each
     * split's brighter-average group rides the foreground. Flat cells keep
     * the solid shortcut. With at least one transparent quad (A-05a) the
     * search is skipped and the cell renders foreground-only: the filled
     * mask is pinned to the opaque quadrant set, transparent quads are
     * absent from the sample and from the group average, and the background
     * pen stays null (terminal default) — never a black paint. All four
     * transparent yields a blank. Pen colours are returned as RGB triples;
     * {@see render()} normalizes them to SGR parameters for OutputOptimizer.
     *
     * @param list<array{int,int,int,?int}> $quads ul, ur, ll, lr (alpha null = transparent)
     *
     * @return array{string, ?array{int,int,int}, ?array{int,int,int}} glyph, fg, bg
     */
    private static function cellPaint(array $quads): array
    {
        $opaque = [];
        foreach ($quads as $i => $quad) {
            if ($quad[3] !== null) {
                $opaque[$i] = [$quad[0], $quad[1], $quad[2]];
            }
        }

        if ($opaque === []) {
            return [' ', null, null];
        }

        if (count($opaque) < 4) {
            $mask = 0;
            foreach (array_keys($opaque) as $i) {
                $mask |= (1 << $i);
            }

            return [self::GLYPH_MAP[$mask], self::averageColor(array_values($opaque)), null];
        }

        // A flat cell — all four quadrants identical — is a solid block.
        if (self::isFlat($quads)) {
            $rgb = [$quads[0][0], $quads[0][1], $quads[0][2]];

            return [self::GLYPH_MAP[15], $rgb, $rgb];
        }

        [$mask, $fg, $bg] = self::bestCell(array_values($quads));

        return [self::GLYPH_MAP[$mask], $fg, $bg];
    }

    /**
     * True when all four quadrant colours are identical.
     *
     * @param list<array{int,int,int,?int}> $quads
     */
    private static function isFlat(array $quads): bool
    {
        foreach ([1, 2, 3] as $i) {
            if ($quads[0][0] !== $quads[$i][0]
                || $quads[0][1] !== $quads[$i][1]
                || $quads[0][2] !== $quads[$i][2]
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Exhaustive minimum-error search over the 16 quadrant membership masks,
     * halved to the 8 representatives 1..7 by complement symmetry (masks 0
     * and 15 are the solid/space duplicates the flat shortcut already owns).
     * Per split the pen colours are the group averages; the brighter-average
     * group is assigned the foreground, its complement mask the glyph, which
     * is exactly chafa's inverted-candidate trial folded into one pass
     * (chafa/internal/chafa-symbol-renderer.c:270-300 scores every symbol;
     * chafa-symbol-map.c:1189-1263 treats hd and 64-hd as one pair).
     *
     * @param list<array{int,int,int,?int}> $quads four opaque quadrants
     *
     * @return array{0:int, 1:array{int,int,int}, 2:array{int,int,int}} mask, fg, bg
     */
    private static function bestCell(array $quads): array
    {
        $bestMask = 15; // unreachable seed — the 1..7 loop always scores a split first
        $bestFg = $bestBg = [0, 0, 0];
        $bestError = PHP_INT_MAX;

        for ($mask = 1; $mask <= 7; $mask++) {
            $fgGroup = [];
            $bgGroup = [];
            for ($i = 0; $i < 4; $i++) {
                if ($mask & (1 << $i)) {
                    $fgGroup[] = [$quads[$i][0], $quads[$i][1], $quads[$i][2]];
                } else {
                    $bgGroup[] = [$quads[$i][0], $quads[$i][1], $quads[$i][2]];
                }
            }

            $fg = self::averageColor($fgGroup);
            $bg = self::averageColor($bgGroup);
            $error = self::cellError($quads, $mask, $fg, $bg);

            // Brighter average rides the foreground; equal luma keeps the
            // as-is assignment (mask bits = fg group) for determinism.
            // $resolved is separate from the loop variable on purpose.
            $resolved = $mask;
            if (ColorUtil::luma($bg[0], $bg[1], $bg[2]) > ColorUtil::luma($fg[0], $fg[1], $fg[2])) {
                [$resolved, $fg, $bg] = [15 & ~$mask, $bg, $fg];
            }

            if ($error < $bestError) {
                $bestError = $error;
                $bestMask = $resolved;
                $bestFg = $fg;
                $bestBg = $bg;
            }
        }

        return [$bestMask, $bestFg, $bestBg];
    }

    /**
     * Summed squared colour error of a membership split: each quadrant pays
     * the distance to the pen that paints it. Symmetric under the pen swap
     * of complement masks, which licenses the 8-mask search.
     *
     * @param list<array{int,int,int,?int}> $quads
     * @param int $mask foreground membership bits
     * @param array{int,int,int} $fg
     * @param array{int,int,int} $bg
     */
    private static function cellError(array $quads, int $mask, array $fg, array $bg): int
    {
        $error = 0;
        for ($i = 0; $i < 4; $i++) {
            $pen = ($mask & (1 << $i)) ? $fg : $bg;
            $error += ColorUtil::squaredDistance([$quads[$i][0], $quads[$i][1], $quads[$i][2]], $pen);
        }

        return $error;
    }

    /**
     * Integer average of a non-empty colour group.
     *
     * @param list<array{int,int,int}> $colors
     *
     * @return array{int,int,int}
     */
    private static function averageColor(array $colors): array
    {
        $sum = [0, 0, 0];
        foreach ($colors as $color) {
            $sum[0] += $color[0];
            $sum[1] += $color[1];
            $sum[2] += $color[2];
        }
        $n = count($colors);

        return [intdiv($sum[0], $n), intdiv($sum[1], $n), intdiv($sum[2], $n)];
    }

    public function name(): string
    {
        return 'quarterblock';
    }

    public function supportsAlpha(): bool
    {
        return false;
    }

    public function isInline(): bool
    {
        return true;
    }

    /**
     * Quarter-block rendering uses plain text SGR codes — no stored
     * image identity to delete. Returns the empty string.
     */
    public function delete(string $imageId): string
    {
        return '';
    }
}

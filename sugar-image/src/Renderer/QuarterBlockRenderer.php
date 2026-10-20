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
                $pen->cell($glyph, $fg, $bg);
            }
            $lines[] = $pen->finishRow();
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve one 2×2 cell to (glyph, foreground, background). The four
     * quadrant colours are split into two groups (a 1-step 2-means around the
     * most-distant pair); the glyph encodes which quadrants are the brighter
     * "foreground" group, drawn in that group's average colour over the other
     * group's average as the background. Pen values are normalized SGR
     * parameter strings for {@see OutputOptimizer} to diff; the mask is
     * provably >= 1 (the seed pair splits the quadrants), so a space glyph
     * never reaches this path.
     *
     * @param list<array{int,int,int}> $quads ul, ur, ll, lr
     *
     * @return array{string, string, string}
     */
    private static function cellPaint(array $quads): array
    {
        [$a, $b] = self::findSeedPair($quads);

        // A flat cell — all four quadrants identical — is a solid block.
        if (ColorUtil::squaredDistance($quads[$a], $quads[$b]) === 0) {
            [$r, $g, $bl] = $quads[0];
            $rgb = OutputOptimizer::trueColor($r, $g, $bl);

            return [self::GLYPH_MAP[15], $rgb, $rgb];
        }

        // Brighter seed is the foreground.
        [$fgSeed, $bgSeed] = ColorUtil::luma($quads[$a][0], $quads[$a][1], $quads[$a][2])
            >= ColorUtil::luma($quads[$b][0], $quads[$b][1], $quads[$b][2])
            ? [$quads[$a], $quads[$b]]
            : [$quads[$b], $quads[$a]];

        [$mask, $fgSum, $bgSum, $fgN, $bgN] = self::groupQuadrantsBySeed($quads, $fgSeed, $bgSeed);
        [$fg, $bg] = self::computeCellColors($fgSeed, $bgSeed, $fgSum, $fgN, $bgSum, $bgN);

        return [
            self::GLYPH_MAP[$mask],
            OutputOptimizer::trueColor($fg[0], $fg[1], $fg[2]),
            OutputOptimizer::trueColor($bg[0], $bg[1], $bg[2]),
        ];
    }

    /**
     * Find the indices of the two most-distant quadrants.
     *
     * @param list<array{int,int,int}> $quads
     * @return array{0:int, 1:int} Indices of the most-distant pair
     */
    private static function findSeedPair(array $quads): array
    {
        $maxD = -1;
        $a = 0;
        $b = 0;
        for ($i = 0; $i < 4; $i++) {
            for ($j = $i + 1; $j < 4; $j++) {
                $d = ColorUtil::squaredDistance($quads[$i], $quads[$j]);
                if ($d > $maxD) {
                    $maxD = $d;
                    $a = $i;
                    $b = $j;
                }
            }
        }
        return [$a, $b];
    }

    /**
     * Group quadrants by their nearest seed colour.
     *
     * @param list<array{int,int,int}> $quads
     * @param array{int,int,int} $fgSeed
     * @param array{int,int,int} $bgSeed
     * @return array{0:int, 1:array, 2:array, 3:int, 4:int} mask, fgSum, bgSum, fgN, bgN
     */
    private static function groupQuadrantsBySeed(array $quads, array $fgSeed, array $bgSeed): array
    {
        $mask = 0;
        $fgSum = [0, 0, 0];
        $bgSum = [0, 0, 0];
        $fgN = 0;
        $bgN = 0;
        for ($i = 0; $i < 4; $i++) {
            if (ColorUtil::squaredDistance($quads[$i], $fgSeed) <= ColorUtil::squaredDistance($quads[$i], $bgSeed)) {
                $mask |= (1 << $i);
                $fgSum[0] += $quads[$i][0];
                $fgSum[1] += $quads[$i][1];
                $fgSum[2] += $quads[$i][2];
                $fgN++;
            } else {
                $bgSum[0] += $quads[$i][0];
                $bgSum[1] += $quads[$i][1];
                $bgSum[2] += $quads[$i][2];
                $bgN++;
            }
        }
        return [$mask, $fgSum, $bgSum, $fgN, $bgN];
    }

    /**
     * Compute averaged foreground and background colours from group sums.
     *
     * @param array{int,int,int} $fgSeed Fallback fg if group is empty
     * @param array{int,int,int} $bgSeed Fallback bg if group is empty
     * @param array{int,int,int} $fgSum
     * @param int $fgN
     * @param array{int,int,int} $bgSum
     * @param int $bgN
     * @return array{0:array, 1:array} fg, bg
     */
    private static function computeCellColors(
        array $fgSeed,
        array $bgSeed,
        array $fgSum,
        int $fgN,
        array $bgSum,
        int $bgN,
    ): array {
        $fg = $fgN > 0
            ? [intdiv($fgSum[0], $fgN), intdiv($fgSum[1], $fgN), intdiv($fgSum[2], $fgN)]
            : $fgSeed;
        $bg = $bgN > 0
            ? [intdiv($bgSum[0], $bgN), intdiv($bgSum[1], $bgN), intdiv($bgSum[2], $bgN)]
            : $bgSeed;
        return [$fg, $bg];
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

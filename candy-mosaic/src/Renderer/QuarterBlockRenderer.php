<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Renderer;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Lang;
use SugarCraft\Mosaic\PixelGrid;

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
        if ($width <= 0) {
            throw new \InvalidArgumentException(Lang::t('renderer.invalid_width', ['width' => $width]));
        }

        if ($height !== null && $height <= 0) {
            throw new \InvalidArgumentException(Lang::t('renderer.invalid_height', ['height' => $height]));
        }

        $effectiveHeight = $height ?? (int) round($width / $image->aspectRatio());
        if ($effectiveHeight <= 0) {
            $effectiveHeight = 1;
        }

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

        $lines = [];
        foreach ($grid->cells as $row) {
            $line = '';
            foreach ($row as $quads) {
                $line .= self::renderCell($quads);
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Render one 2×2 cell. The four quadrant colours are split into two groups
     * (a 1-step 2-means around the most-distant pair); the glyph encodes which
     * quadrants are the brighter "foreground" group, drawn in that group's
     * average colour over the other group's average as the background.
     *
     * @param list<array{int,int,int}> $quads ul, ur, ll, lr
     */
    private static function renderCell(array $quads): string
    {
        // Most-distant pair seeds the two colour groups.
        $maxD = -1;
        $a = 0;
        $b = 0;
        for ($i = 0; $i < 4; $i++) {
            for ($j = $i + 1; $j < 4; $j++) {
                $d = self::dist($quads[$i], $quads[$j]);
                if ($d > $maxD) {
                    $maxD = $d;
                    $a = $i;
                    $b = $j;
                }
            }
        }

        // A flat cell — all four quadrants identical — is a solid block.
        if ($maxD === 0) {
            [$r, $g, $b0] = $quads[0];
            return Ansi::fgRgb($r, $g, $b0) . Ansi::bgRgb($r, $g, $b0) . self::GLYPH_MAP[15] . Ansi::reset();
        }

        // Brighter seed is the foreground.
        [$fgSeed, $bgSeed] = self::luma($quads[$a]) >= self::luma($quads[$b])
            ? [$quads[$a], $quads[$b]]
            : [$quads[$b], $quads[$a]];

        $mask = 0;
        $fgSum = [0, 0, 0];
        $bgSum = [0, 0, 0];
        $fgN = 0;
        $bgN = 0;
        for ($i = 0; $i < 4; $i++) {
            if (self::dist($quads[$i], $fgSeed) <= self::dist($quads[$i], $bgSeed)) {
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

        $fg = $fgN > 0 ? [intdiv($fgSum[0], $fgN), intdiv($fgSum[1], $fgN), intdiv($fgSum[2], $fgN)] : $fgSeed;
        $bg = $bgN > 0 ? [intdiv($bgSum[0], $bgN), intdiv($bgSum[1], $bgN), intdiv($bgSum[2], $bgN)] : $bgSeed;

        return Ansi::fgRgb($fg[0], $fg[1], $fg[2])
            . Ansi::bgRgb($bg[0], $bg[1], $bg[2])
            . self::GLYPH_MAP[$mask]
            . Ansi::reset();
    }

    /**
     * @param array{int,int,int} $x
     * @param array{int,int,int} $y
     */
    private static function dist(array $x, array $y): int
    {
        $dr = $x[0] - $y[0];
        $dg = $x[1] - $y[1];
        $db = $x[2] - $y[2];
        return $dr * $dr + $dg * $dg + $db * $db;
    }

    /** @param array{int,int,int} $rgb */
    private static function luma(array $rgb): int
    {
        return (($rgb[0] * 77) + ($rgb[1] * 150) + ($rgb[2] * 29)) >> 8;
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

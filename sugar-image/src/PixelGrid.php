<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * Internal: decode a GD image into a 2-D grid of RGB triples at a
 * given cell resolution. Consumers never construct this directly —
 * use a Renderer subclass's own downsampling logic or call
 * {@see fromGd()} directly for testing.
 *
 * @phpstan-type RgbCell array{0:int,1:int,2:int}
 * @phpstan-type RgbaCell array{0:int,1:int,2:int,3:?int}  // alpha=0 means fully transparent
 *
 * Semi-transparent pixel limitation: alpha values 1-126 (GD range where
 * 0=opaque, 127=fully transparent) are treated as fully opaque in the
 * PixelGrid cell tuple. Only alpha=127 maps to null (fully transparent)
 * and alpha=0 maps to 0 (fully opaque). This is a simplification — true
 * alpha blending would require compositor support which these renderers
 * do not implement. Semi-transparent pixels will display as opaque.
 *
 * B-10: every builder takes an optional `int $alphaThreshold = 127` —
 * the transparency test is `alpha >= threshold`, matching
 * {@see \SugarCraft\Image\Renderer\SixelRenderer::withAlphaThreshold()}.
 * At the default the `>= 127` test agrees with the historical `=== 127`
 * on every GD pixel, so symbol-renderer bytes stay frozen; a lowered
 * threshold promotes semi-transparent pixels into the null (hole) slot
 * for the cell renderers, the PixelGrid mirror of chafa's
 * `ch[3] < alpha_threshold` quantize skip.
 */
final class PixelGrid
{
    /**
     * @param list<list<RgbaCell>> $cells  rows top→bottom, cols left→right
     */
    private function __construct(
        public readonly array $cells,
        public readonly int $cellW,
        public readonly int $cellH,
    ) {}

    /**
     * Resize the source GD image to the target cell grid dimensions and
     * read back the pixel values.
     *
     * @param \GdImage $img    Source image (must be truecolor — call
     *                         imagepalettetotruecolor() first if needed)
     * @param int      $cellW  Number of cells wide
     * @param int      $cellH  Number of cells tall
     * @param int      $alphaThreshold
     *                         B-10: GD alpha at which (and above) a pixel
     *                         reads back as null; 127 (default) keeps the
     *                         historical exact-127 hole test byte-identical.
     * @return self            2-D grid of `[r, g, b, ?int]` triples; alpha
     *                         is null for fully-transparent pixels, 0 for
     *                         fully-opaque pixels, 1-126 for semi-transparent
     */
    public static function fromGd(\GdImage $img, int $cellW, int $cellH, int $alphaThreshold = 127): self
    {
        $srcW = imagesx($img);
        $srcH = imagesy($img);

        $scaled = imagecreatetruecolor($cellW, $cellH * 2);
        if ($scaled === false) {
            throw new \RuntimeException(Lang::t('pixel_grid.alloc_failed'));
        }

        // Initialize all pixels to fully-opaque black so un-resampled
        // pixels (if any) have a deterministic alpha=0 rather than garbage.
        $opaqueBlack = imagecolorallocatealpha($scaled, 0, 0, 0, 0);
        imagefill($scaled, 0, 0, $opaqueBlack);

        // Disable alpha blending so we get exact pixel values from imagecolorat.
        imagesavealpha($scaled, false);
        imagealphablending($scaled, false);

        imagecopyresampled(
            $scaled, $img,
            0, 0,       // dst x, y
            0, 0,       // src x, y
            $cellW, $cellH * 2,  // dst w, h  (double height for half-block pairs)
            $srcW, $srcH,        // src w, h
        );

        $rows = [];
        for ($cy = 0; $cy < $cellH; $cy++) {
            $row = [];
            for ($cx = 0; $cx < $cellW; $cx++) {
                // Top half of the cell pair (upper pixel).
                $topIdx = imagecolorat($scaled, $cx, $cy * 2);
                $topC   = imagecolorsforindex($scaled, $topIdx);
                // GD alpha: 0 = fully opaque, 127 = fully transparent.
                // B-10: alpha >= threshold → null (hole); below → opaque slot.
                $topA   = $topC['alpha'] >= $alphaThreshold ? null : $topC['alpha'];
                // Bottom half of the cell pair (lower pixel).
                $botIdx = imagecolorat($scaled, $cx, $cy * 2 + 1);
                $botC   = imagecolorsforindex($scaled, $botIdx);
                $botA   = $botC['alpha'] >= $alphaThreshold ? null : $botC['alpha'];
                $row[] = [
                    [$topC['red'], $topC['green'], $topC['blue'], $topA],
                    [$botC['red'], $botC['green'], $botC['blue'], $botA],
                ];
            }
            $rows[] = $row;
        }

        imagedestroy($scaled);

        return new self($rows, $cellW, $cellH);
    }

    /**
     * Build a grid from raw GD image bytes (imagecreatefromstring path).
     * Used for testing with fixed PNG fixtures. The `$alphaThreshold` is
     * B-10's hole cut-off, passed straight through to {@see fromGd()}.
     */
    public static function fromString(string $bytes, int $cellW, int $cellH, int $alphaThreshold = 127): self
    {
        $img = imagecreatefromstring($bytes);
        if ($img === false) {
            throw new \RuntimeException(Lang::t('pixel_grid.decode_failed'));
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        $grid = self::fromGd($img, $cellW, $cellH, $alphaThreshold);
        imagedestroy($img);
        return $grid;
    }

    /**
     * Resize the source GD image to cellW*2 × cellH*2 pixels and read
     * back four quadrant values per cell (ul, ur, ll, lr).  Used by
     * QuarterBlockRenderer for 2×2 sub-pixel rendering.
     *
     * Each quad carries the same threshold-alpha slot as {@see fromGd()}:
     * pixels at or above `$alphaThreshold` (GD alpha 127 at the default,
     * fully transparent) read back as null so downstream callers can
     * exclude the quad from colour sampling instead of mistaking its
     * stored RGB (often black) for image content (A-05a); alpha below the
     * threshold is treated as opaque.
     *
     * @param \GdImage $img    Source image (must be truecolor)
     * @param int      $cellW  Number of terminal cells wide
     * @param int      $cellH  Number of terminal cells tall
     * @param int      $alphaThreshold
     *                         B-10: hole cut-off, same semantics as
     *                         {@see fromGd()}'s; default 127 keeps the
     *                         historical exact-127 test byte-identical.
     * @return self            2-D grid of 4-element [ul, ur, ll, lr] quads,
     *                         each `[r, g, b, ?int]`
     */
    public static function fromGdQuarter(\GdImage $img, int $cellW, int $cellH, int $alphaThreshold = 127): self
    {
        $srcW = imagesx($img);
        $srcH = imagesy($img);

        $scaled = imagecreatetruecolor($cellW * 2, $cellH * 2);
        if ($scaled === false) {
            throw new \RuntimeException(Lang::t('pixel_grid.alloc_failed'));
        }

        imagesavealpha($scaled, false);
        imagealphablending($scaled, false);

        imagecopyresampled(
            $scaled, $img,
            0, 0,       // dst x, y
            0, 0,       // src x, y
            $cellW * 2, $cellH * 2,  // dst w, h  (2×2 quads per cell)
            $srcW, $srcH,            // src w, h
        );

        $rows = [];
        for ($cy = 0; $cy < $cellH; $cy++) {
            $row = [];
            for ($cx = 0; $cx < $cellW; $cx++) {
                $quads = [];
                // Read 4 quadrants: ul, ur, ll, lr
                $offsets = [
                    [$cx * 2,     $cy * 2],     // upper-left
                    [$cx * 2 + 1, $cy * 2],     // upper-right
                    [$cx * 2,     $cy * 2 + 1], // lower-left
                    [$cx * 2 + 1, $cy * 2 + 1], // lower-right
                ];
                foreach ($offsets as list($ox, $oy)) {
                    $color = imagecolorsforindex($scaled, imagecolorat($scaled, $ox, $oy));
                    // GD alpha: 0 = fully opaque, 127 = fully transparent.
                    // B-10: alpha >= threshold → null (hole), below = opaque slot.
                    $quads[] = [
                        $color['red'],
                        $color['green'],
                        $color['blue'],
                        $color['alpha'] >= $alphaThreshold ? null : $color['alpha'],
                    ];
                }
                $row[] = $quads;
            }
            $rows[] = $row;
        }

        imagedestroy($scaled);

        return new self($rows, $cellW, $cellH);
    }
}

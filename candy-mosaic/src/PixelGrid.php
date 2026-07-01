<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

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
     * @return self            2-D grid of `[r, g, b, ?int]` triples; alpha
     *                         is null for fully-transparent pixels, 0 for
     *                         fully-opaque pixels, 1-126 for semi-transparent
     */
    public static function fromGd(\GdImage $img, int $cellW, int $cellH): self
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
                // Map: alpha=127 → null (transparent), alpha=0 → non-null (opaque).
                $topA   = $topC['alpha'] === 127 ? null : $topC['alpha'];
                // Bottom half of the cell pair (lower pixel).
                $botIdx = imagecolorat($scaled, $cx, $cy * 2 + 1);
                $botC   = imagecolorsforindex($scaled, $botIdx);
                $botA   = $botC['alpha'] === 127 ? null : $botC['alpha'];
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
     * Used for testing with fixed PNG fixtures.
     */
    public static function fromString(string $bytes, int $cellW, int $cellH): self
    {
        $img = imagecreatefromstring($bytes);
        if ($img === false) {
            throw new \RuntimeException(Lang::t('pixel_grid.decode_failed'));
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        $grid = self::fromGd($img, $cellW, $cellH);
        imagedestroy($img);
        return $grid;
    }

    /**
     * Resize the source GD image to cellW*2 × cellH*2 pixels and read
     * back four quadrant values per cell (ul, ur, ll, lr).  Used by
     * QuarterBlockRenderer for 2×2 sub-pixel rendering.
     *
     * @param \GdImage $img    Source image (must be truecolor)
     * @param int      $cellW  Number of terminal cells wide
     * @param int      $cellH  Number of terminal cells tall
     * @return self            2-D grid of 4-element [ul, ur, ll, lr] RGB triples
     * @phpstan-return list<list<array{0:int,1:int,2:int}>>  // 4 entries per cell
     */
    public static function fromGdQuarter(\GdImage $img, int $cellW, int $cellH): self
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
                    $rgb = imagecolorat($scaled, $ox, $oy);
                    $r = ($rgb >> 16) & 0xff;
                    $g = ($rgb >>  8) & 0xff;
                    $b =  $rgb        & 0xff;
                    $quads[] = [$r, $g, $b];
                }
                $row[] = $quads;
            }
            $rows[] = $row;
        }

        imagedestroy($scaled);

        return new self($rows, $cellW, $cellH);
    }
}

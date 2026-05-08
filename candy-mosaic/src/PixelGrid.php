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
 */
final class PixelGrid
{
    /**
     * @param list<list<RgbCell>> $cells  rows top→bottom, cols left→right
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
     * @return self            2-D grid of `[r, g, b]` triples
     */
    public static function fromGd(\GdImage $img, int $cellW, int $cellH): self
    {
        $srcW = imagesx($img);
        $srcH = imagesy($img);

        $scaled = imagecreatetruecolor($cellW, $cellH * 2);
        if ($scaled === false) {
            throw new \RuntimeException(Lang::t('pixel_grid.alloc_failed'));
        }

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
                $topRgb = imagecolorat($scaled, $cx, $cy * 2);
                $topR = ($topRgb >> 16) & 0xff;
                $topG = ($topRgb >>  8) & 0xff;
                $topB =  $topRgb        & 0xff;
                // Bottom half of the cell pair (lower pixel).
                $botRgb = imagecolorat($scaled, $cx, $cy * 2 + 1);
                $botR = ($botRgb >> 16) & 0xff;
                $botG = ($botRgb >>  8) & 0xff;
                $botB =  $botRgb        & 0xff;
                $row[] = [
                    [$topR, $topG, $topB],
                    [$botR, $botG, $botB],
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
}

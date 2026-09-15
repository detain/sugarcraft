<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Graphics;

use RuntimeException;
use SugarCraft\Testing\Lang;

/**
 * An immutable RGB raster produced by a graphics decoder.
 *
 * Cells are stored row-major; a `null` cell means "no pixel painted here"
 * (transparent), which matters for Sixel bands that stop short of the full
 * raster height. Colour triples are `[r, g, b]` in 0..255.
 *
 * Mirrors the pixel buffer a candy-mosaic renderer rasterises before encoding.
 */
final class PixelGrid
{
    /**
     * @param list<list<array{int, int, int}|null>> $pixels row-major cells
     */
    private function __construct(
        private readonly int $width,
        private readonly int $height,
        private readonly array $pixels,
    ) {
    }

    /**
     * Build a grid from a fully-formed row-major array of RGB triples.
     *
     * @param list<list<array{int, int, int}|null>> $pixels
     */
    public static function fromRgbGrid(array $pixels): self
    {
        $height = count($pixels);
        $width = $height === 0 ? 0 : count($pixels[0]);

        return new self($width, $height, array_values($pixels));
    }

    /**
     * Build an empty (fully transparent) grid of the given dimensions.
     */
    public static function transparent(int $width, int $height): self
    {
        $blankRow = array_fill(0, max($width, 0), null);
        $pixels = array_fill(0, max($height, 0), $blankRow);

        return new self($width, $height, $pixels);
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    /**
     * The RGB triple at (x, y), or null when that cell was never painted.
     *
     * @return array{int, int, int}|null
     */
    public function pixel(int $x, int $y): ?array
    {
        return $this->pixels[$y][$x] ?? null;
    }

    /**
     * Every cell that was painted, as a flat list — handy for sampling palettes.
     *
     * @return list<array{int, int, int}>
     */
    public function paintedPixels(): array
    {
        $painted = [];
        foreach ($this->pixels as $row) {
            foreach ($row as $cell) {
                if ($cell !== null) {
                    $painted[] = $cell;
                }
            }
        }

        return $painted;
    }

    /**
     * True when the whole raster carries a single colour, returned as that triple.
     *
     * @return array{int, int, int}|null
     */
    public function uniformColor(): ?array
    {
        $first = null;
        foreach ($this->pixels as $row) {
            foreach ($row as $cell) {
                if ($cell === null) {
                    return null;
                }
                if ($first === null) {
                    $first = $cell;
                    continue;
                }
                if ($first !== $cell) {
                    return null;
                }
            }
        }

        return $first;
    }

    /**
     * Materialise the grid as a GD truecolour image (alpha for unpainted cells).
     *
     * @throws RuntimeException when ext-gd is unavailable in this runtime
     */
    public function toGdImage(): \GdImage
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException(Lang::t('graphics.gd.unavailable'));
        }

        $image = imagecreatetruecolor(max($this->width, 1), max($this->height, 1));
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);

        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $cell = $this->pixels[$y][$x] ?? null;
                $color = $cell === null
                    ? $transparent
                    : imagecolorallocate($image, $cell[0], $cell[1], $cell[2]);
                imagesetpixel($image, $x, $y, $color);
            }
        }

        return $image;
    }
}

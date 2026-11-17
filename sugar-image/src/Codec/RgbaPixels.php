<?php

declare(strict_types=1);

namespace SugarCraft\Image\Codec;

/**
 * A decoded image as straight-alpha RGBA8: `width × height × 4` bytes, one
 * R,G,B,A quad per pixel, rows top to bottom.
 *
 * The buffer is the same layout {@see RawRgba} validates for the Kitty f=32
 * path, so a decoded image can feed a raw-RGBA renderer without a second
 * conversion. Returned by {@see \SugarCraft\Image\PngPixels::decode()}.
 */
final readonly class RgbaPixels
{
    /**
     * @throws \InvalidArgumentException  when the buffer is not exactly width×height quads
     */
    public function __construct(
        public int $width,
        public int $height,
        public string $rgba,
    ) {
        RawRgba::validate($rgba, $width, $height);
    }
}

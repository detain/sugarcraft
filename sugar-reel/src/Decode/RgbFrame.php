<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Decode;

/**
 * A readonly value object holding one raw RGB frame.
 *
 * The frame stores raw rgb24 bytes where each pixel is 3 bytes (R, G, B).
 * Luminance (BT.601) is computed by callers via LumaRamp::compute().
 *
 * @see video_plan.md lines 79-82
 */
final readonly class RgbFrame
{
    /**
     * @param string $bytes Raw rgb24 bytes (exact length w * h * 3)
     * @param int $w Frame width in pixels
     * @param int $h Frame height in pixels
     */
    public function __construct(
        public string $bytes,
        public int $w,
        public int $h,
    ) {
    }

    /**
     * Convert the rgb24 bytes to a GD image.
     *
     * Uses imagecreatetruecolor + setpixel loop to build the image.
     * Pixel format is RGB (not BGR) — imagecolorat() returns RGB
     * for truecolor images on little-endian systems.
     */
    public function toGd(): \GdImage
    {
        $img = \imagecreatetruecolor($this->w, $this->h);
        if ($img === false) {
            throw new \RuntimeException('Failed to create GD image');
        }

        $offset = 0;
        $byteLen = strlen($this->bytes);
        for ($y = 0; $y < $this->h; $y++) {
            for ($x = 0; $x < $this->w; $x++) {
                // Guard against undersized byte buffers (caller's responsibility
                // to provide w*h*3 bytes; clamp missing bytes to black).
                if ($offset + 2 >= $byteLen) {
                    $r = $g = $b = 0;
                } else {
                    $r = ord($this->bytes[$offset++]);
                    $g = ord($this->bytes[$offset++]);
                    $b = ord($this->bytes[$offset++]);
                }
                \imagesetpixel($img, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }

        return $img;
    }
}

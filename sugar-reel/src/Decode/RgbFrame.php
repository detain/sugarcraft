<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Decode;

use SugarCraft\Reel\Render\Color;

/**
 * A readonly value object holding one decoded video frame.
 *
 * Two interchangeable payloads, depending on the render path that asked for it:
 *
 *  - **Raw rgb24** (`$bytes`, the default): one pixel = 3 bytes (R, G, B), exact
 *    length `w*h*3`. This is what the text/cell renderers (ASCII, ANSI, half- and
 *    quarter-block) read directly via {@see Player::pixelRgb()}.
 *  - **Pre-encoded PNG** (`$png`, optional): a self-contained PNG blob, already at
 *    full pixel resolution. The graphics protocols (Sixel/Kitty/iTerm2) consume
 *    this verbatim — no per-pixel PHP work. When a graphics-mode decoder fills
 *    `$png`, it leaves `$bytes` empty: the raw grid is never needed on that path,
 *    and synthesising it would just throw away the resolution we decoded at.
 *
 * Luminance (BT.601) is computed by callers via LumaRamp::compute().
 *
 * @see video_plan.md lines 79-82
 */
final readonly class RgbFrame
{
    /**
     * @param string      $bytes Raw rgb24 bytes (exact length w * h * 3), or '' for a PNG frame
     * @param int         $w     Frame width in pixels
     * @param int         $h     Frame height in pixels
     * @param string|null $png   Pre-encoded full-resolution PNG bytes for the graphics
     *                           protocols, or null for a raw rgb24 frame
     */
    public function __construct(
        public string $bytes,
        public int $w,
        public int $h,
        public ?string $png = null,
    ) {
    }

    /**
     * Convert the frame to a GD image.
     *
     * A PNG frame ({@see $png}) is decoded with imagecreatefromstring (C-speed).
     * A raw rgb24 frame is built with imagecreatetruecolor + a setpixel loop;
     * pixel format is RGB (not BGR) — imagecolorat() returns RGB for truecolor
     * images on little-endian systems.
     */
    public function toGd(): \GdImage
    {
        if ($this->png !== null) {
            $img = \imagecreatefromstring($this->png);
            if ($img === false) {
                throw new \RuntimeException('Failed to decode PNG frame');
            }
            return $img;
        }

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
                \imagesetpixel($img, $x, $y, Color::pack($r, $g, $b));
            }
        }

        return $img;
    }
}

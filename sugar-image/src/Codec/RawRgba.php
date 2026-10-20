<?php

declare(strict_types=1);

namespace SugarCraft\Image\Codec;

use SugarCraft\Image\Lang;

/**
 * Kitty graphics protocol `f=32` raw RGBA encoder — GD-free by design.
 *
 * F-13: chafa transmits Kitty payloads as raw RGBA8 quads
 * (chafa/internal/chafa-kitty-renderer.c, term-db `f=%1` with the
 * transmissive path), never touching an image library. The on-the-wire
 * form is a flat byte string, one 4-byte R,G,B,A quad per pixel, rows
 * top-to-bottom.
 *
 * Endianness: the quads are packed with `pack('V*')` — UNSIGNED
 * LITTLE-ENDIAN 32-bit. That is what makes the byte order correct: a
 * pixel value composed as `R | (G << 8) | (B << 16) | (A << 24)`
 * serialises to the bytes R,G,B,A. (Kitty's spec describes f=32 as a
 * 4 x 8-bit RGBA byte stream, so the little-endian pack is the vehicle,
 * not a platform assumption: `V` is fixed-order, identical on big-endian
 * hosts.) This is the same byte order chafa's RGBA8_UNASSOCIATED pixel
 * buffer already has in memory.
 *
 * Alpha is UNASSOCIATED (straight), matching the kitty spec and chafa's
 * SMOL_PIXEL_RGBA8_UNASSOCIATED destination format.
 */
final class RawRgba
{
    /** Pixels per `pack('V*')` call — bounded so huge frames never spread past the arg limit. */
    private const PACK_BATCH_PIXELS = 8192;

    private function __construct() {}

    /**
     * Serialise a pixel list (each `R | G<<8 | B<<16 | A<<24`) to wire bytes.
     *
     * @param list<int> $pixels
     */
    public static function fromPixels(array $pixels): string
    {
        if ($pixels === []) {
            return '';
        }

        $out = '';
        foreach (array_chunk($pixels, self::PACK_BATCH_PIXELS) as $batch) {
            $out .= pack('V*', ...$batch);
        }

        return $out;
    }

    /**
     * Read a truecolor GD image into wire-order RGBA bytes.
     *
     * GD alpha is 0..127 with 0 OPAQUE; the wire byte is 0..255 with 255
     * opaque, so alpha maps through `A = 255 - intdiv(gdA * 255, 127)`
     * (0 → 255, 127 → 0; the inverse of ImageSource::fromRgb's
     * `intdiv((255 - a) * 127, 255)` floor-scaling).
     *
     * @param \GdImage $img  truecolor image (call imagepalettetotruecolor() first)
     */
    public static function fromGdImage(\GdImage $img): string
    {
        $w = imagesx($img);
        $h = imagesy($img);

        $pixels = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorsforindex($img, (int) imagecolorat($img, $x, $y));
                $a = 255 - intdiv(((int) $c['alpha']) * 255, 127);
                $pixels[] = ((int) $c['red']) | ((int) $c['green']) << 8
                          | ((int) $c['blue']) << 16 | $a << 24;
            }
        }

        return self::fromPixels($pixels);
    }

    /**
     * Fail fast when a raw payload does not describe exactly w×h quads.
     *
     * @throws \InvalidArgumentException  on non-positive dimensions or a short/long buffer
     */
    public static function validate(string $rgba, int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException(Lang::t('codec.raw_rgba_bad_dimensions', [
                'width'  => $width,
                'height' => $height,
            ]));
        }

        $expected = $width * $height * 4;
        if (strlen($rgba) !== $expected) {
            throw new \InvalidArgumentException(Lang::t('codec.raw_rgba_size_mismatch', [
                'expected' => $expected,
                'actual'   => strlen($rgba),
                'width'    => $width,
                'height'   => $height,
            ]));
        }

        return $rgba;
    }
}

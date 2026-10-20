<?php

declare(strict_types=1);

namespace SugarCraft\Image\Codec;

use SugarCraft\Image\Lang;

/**
 * Minimal uncompressed-TIFF encoder — the GD-free iTerm2 payload.
 *
 * F-13, mirroring chafa's iTerm2 container
 * (chafa/internal/chafa-iterm2-renderer.c build_ansi, :280-330): little-
 * endian classic TIFF, a single strip of RGBA8_UNASSOCIATED pixels at a
 * fixed offset, and a fixed 11-entry IFD. No compression, no predictor,
 * no EXIF — iTerm2 only needs enough TIFF to be a valid container, and
 * chafa ships nothing more.
 *
 * Layout (all offsets little-endian u32, all tags `pack('vvVV')`):
 *
 *   0   header   'II' + 42           = pack('V', 0x002a4949)
 *   4   IFD offset                  = 8 + image_size
 *   8   strip: w*h*4 RGBA bytes
 *       IFD: count(11), 11 tags ascending, next-IFD u32 = 0
 *       BitsPerSample external: four SHORT 8s
 *
 * The BitsPerSample tag (count 4 × SHORT = 8 bytes) exceeds the 4-byte
 * in-line value field, so its data field carries the file offset of the
 * external quad — the one non-trivial address in the layout, and the
 * exact spot a tag-order mutation must NOT survive (the golden pins it).
 *
 * Size ceiling: the TIFF offsets are u32, so a payload within 4096 bytes
 * of 2^32 is refused (the same guard chafa applies before allocating —
 * chafa-iterm2-renderer.c:129-133).
 */
final class TiffUncompressed
{
    /** TIFF field types used by this subset. */
    private const TYPE_SHORT = 3;
    private const TYPE_LONG  = 4;

    /** Photometric RGB, top-left orientation, contiguous planars, unassociated alpha. */
    private const PHOTOMETRIC_RGB    = 2;
    private const ORIENTATION_TOPLEFT = 1;
    private const PLANAR_CONTIGUOUS  = 1;
    private const EXTRA_SAMPLE_UNASSOC_ALPHA = 2;

    /** Fixed offsets: 4-byte magic + 4-byte IFD pointer, then the strip. */
    private const HEADER_BYTES    = 8;
    private const TAG_COUNT       = 11;
    private const TAG_BYTES       = 12;
    private const NEXT_IFD_BYTES  = 4;
    private const BITS_DATA_BYTES = 8;

    /** TIFF is u32-addressed; reject payloads chafa's ceiling also rejects. */
    private const OFFSET_CEILING = 4294967295; // PHP_INT_MAX-safe below
    private const OFFSET_RESERVE = 4096;

    private function __construct() {}

    /**
     * Wrap an RGBA byte buffer (w×h×4, row-major, unassociated alpha) in
     * a single-strip uncompressed TIFF.
     *
     * @throws \InvalidArgumentException  on non-positive dimensions or a short/long buffer
     * @throws \RuntimeException          when the u32 offset ceiling would be crossed
     */
    public static function encode(string $rgba, int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException(Lang::t('codec.raw_rgba_bad_dimensions', [
                'width'  => $width,
                'height' => $height,
            ]));
        }

        $imageSize = $width * $height * 4;
        if ($imageSize > self::OFFSET_CEILING - self::HEADER_BYTES - self::OFFSET_RESERVE) {
            throw new \RuntimeException(Lang::t('codec.tiff_too_large', ['bytes' => $imageSize]));
        }

        RawRgba::validate($rgba, $width, $height);

        $stripOffset = self::HEADER_BYTES;
        $ifdOffset   = $stripOffset + $imageSize;
        // BitsPerSample rides after IFD + next-IFD terminator.
        $bitsOffset  = $ifdOffset + 2 + (self::TAG_COUNT * self::TAG_BYTES) + self::NEXT_IFD_BYTES;

        $ifd  = pack('v', self::TAG_COUNT);
        $ifd .= self::tag(256, self::TYPE_LONG,  1, $width);                  // ImageWidth
        $ifd .= self::tag(257, self::TYPE_LONG,  1, $height);                 // ImageLength
        $ifd .= self::tag(258, self::TYPE_SHORT, 4, $bitsOffset);             // BitsPerSample → external
        $ifd .= self::tag(262, self::TYPE_SHORT, 1, self::PHOTOMETRIC_RGB);   // PhotometricInterpretation
        $ifd .= self::tag(273, self::TYPE_LONG,  1, $stripOffset);            // StripOffsets
        $ifd .= self::tag(274, self::TYPE_SHORT, 1, self::ORIENTATION_TOPLEFT); // Orientation
        $ifd .= self::tag(277, self::TYPE_SHORT, 1, 4);                       // SamplesPerPixel
        $ifd .= self::tag(278, self::TYPE_LONG,  1, $height);                 // RowsPerStrip
        $ifd .= self::tag(279, self::TYPE_LONG,  1, $imageSize);              // StripByteCounts
        $ifd .= self::tag(284, self::TYPE_SHORT, 1, self::PLANAR_CONTIGUOUS); // PlanarConfiguration
        $ifd .= self::tag(338, self::TYPE_SHORT, 1, self::EXTRA_SAMPLE_UNASSOC_ALPHA); // ExtraSamples

        return
              pack('V', 0x002a4949)   // 'II' 0x2A 0x00 — little-endian magic + version 42
            . pack('V', $ifdOffset)
            . $rgba
            . $ifd
            . pack('V', 0)            // no next IFD
            . pack('vvvv', 8, 8, 8, 8); // BitsPerSample external: R8 G8 B8 A8
    }

    /**
     * One IFD entry. `data` is the in-line value (≤ 4 bytes of content)
     * or a file offset, per the count/type pair — mirroring chafa's
     * generate_tag().
     */
    private static function tag(int $id, int $type, int $count, int $data): string
    {
        return pack('vvVV', $id, $type, $count, $data);
    }
}

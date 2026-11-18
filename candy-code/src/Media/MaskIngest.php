<?php

declare(strict_types=1);

namespace SugarCraft\Code\Media;

use SugarCraft\Image\Codec\RawRgba;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\PngPixels;

/**
 * One mask image admitted into a generation request (crush-media W4.3).
 *
 * The mask half of the §2.7 upload conventions: a plain black-and-white PNG
 * where WHITE = inpaint (`processing.py:344` converts PIL "L"/255, bright
 * areas mask) OR a transparent PNG where ANY even slightly transparent area
 * becomes mask (mystage §2.4 Inpaint-upload row). The bytes travel raw —
 * base64 of the exact file, the same no-re-encode law as
 * {@see ImageIngress} — and the classification below is therefore an
 * ADVISORY label, never a transform: it tells the user which convention the
 * server will read their file by, and warns when the mask covers nothing or
 * everything (both extremes legal server-side — advisory, NOT refusal, per
 * the W4.3 microstep-1 ruling).
 *
 * Loud refusal when the file matches NEITHER convention: opaque and
 * chromatic — the server would read a meaningless luminance mask out of it.
 *
 * Classification reads the PNG samples through {@see PngPixels}, the one PNG reader
 * in the stack, which keeps the stored 8-bit alpha. PHP's GD quantises 8-bit alpha
 * down to 7 bits on LOAD: a pixel at alpha 252..254 reads back fully opaque through
 * `imagecolorat()`, and the plan's law is that a SINGLE 1-step-transparent pixel makes
 * the whole file an alpha mask. Everything PngPixels declines (16-bit, interlaced,
 * malformed or CRC-failing streams) falls through to a pixel walk over the GD-decoded
 * image, where the 7-bit blind band is disclosed in the fallback's own docblock.
 *
 * Fail-fast mirrors ImageIngress: every refusal is an
 * \InvalidArgumentException naming what was refused; a file that cannot be
 * decoded at all surfaces ImageIngress's own exceptions unchanged.
 */
final readonly class MaskIngest
{
    /** Transparency present: the alpha channel (or tRNS key) defines the mask. */
    public const MODE_ALPHA = 'alpha';

    /** Opaque grayscale: brightness defines the mask (white = inpaint). */
    public const MODE_LUMINANCE = 'luminance';

    /** Uniform-mask advisory codes — machine values, rendered by the form (§2.7 rows). */
    public const ADVISORY_COVERS_EVERYTHING = 'covers-everything';
    public const ADVISORY_COVERS_NOTHING = 'covers-nothing';
    public const ADVISORY_UNIFORM_LEVEL = 'uniform-level';

    /**
     * The one refusal sentence for the neither-convention file. Literal
     * English per the ImageIngress::URL_REFUSAL precedent: it names a
     * ruling and must read identically in every log.
     */
    public const NEITHER_REFUSAL = 'mask must be black-and-white (white = inpaint) or carry transparency — an opaque colour image is not a mask (crush-media W4.3)';

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * Classifier verdict marker for "decided: neither convention" — distinct from
     * null, which means "not this reader's shape, try the next one".
     */
    private const VERDICT_NEITHER = ['neither', null];

    public function __construct(
        /** The validated image ingress behind the label — bytes/b64/geometry live there. */
        public readonly ImageIngress $source,
        /** One of the MODE_* constants. */
        public readonly string $mode,
        /** One of the ADVISORY_* constants, or null when the mask is varied. */
        public readonly ?string $advisory = null,
    ) {
    }

    /**
     * Admit a local mask file. Validation of path shape, size and decodability
     * is ImageIngress's door; classification is added here.
     *
     * @throws \InvalidArgumentException on a URL/data-URI shape, an over-cap
     *         file, an undecodable image, or a NEITHER-convention image
     * @throws \RuntimeException when ext-gd is unavailable or pixel data fails
     */
    public static function fromPath(string $path, int $maxBytes = ImageSource::MAX_BYTES): self
    {
        return self::classify(ImageIngress::fromPath($path, $maxBytes));
    }

    /** Admit bytes already in memory (clipboard / future editor round-trip). */
    public static function fromBytes(string $bytes, string $origin, int $maxBytes = ImageSource::MAX_BYTES): self
    {
        return self::classify(ImageIngress::fromBytes($bytes, $origin, $maxBytes));
    }

    /** Raw base64 of the exact mask bytes — the wire `mask` entry, never a data: URI. */
    public function base64(): string
    {
        return $this->source->base64;
    }

    public function name(): string
    {
        return $this->source->name();
    }

    public function dimensions(): string
    {
        return $this->source->dimensions();
    }

    // ---- classification ------------------------------------------------------------

    /**
     * §2.7 mode + advisory for an admitted image, refusing the shape that is
     * neither convention.
     *
     * @throws \InvalidArgumentException on NEITHER_REFUSAL or an unclassifiable file
     */
    private static function classify(ImageIngress $source): self
    {
        // PngPixels is the one PNG reader. It returns null, never throws, for every
        // shape and damaged stream it does not read exactly, and that null hands the
        // file to the GD pixel walk below.
        $pixels = str_starts_with($source->bytes, self::PNG_SIGNATURE)
            ? PngPixels::decode($source->bytes)
            : null;

        $verdict = $pixels !== null ? self::decideRgba($pixels) : null;
        $verdict ??= self::walkDecoded($source->bytes);

        if ($verdict === self::VERDICT_NEITHER) {
            throw new \InvalidArgumentException(self::NEITHER_REFUSAL);
        }

        if ($verdict === null) {
            // ImageIngress decoded this file, so a total walk failure is an
            // exotic shape we refuse to GUESS about — loud, not guessed.
            throw new \InvalidArgumentException(
                'mask ' . $source->name() . ' could not be classified as a black-and-white or transparent image',
            );
        }

        return new self($source, $verdict[0], $verdict[1]);
    }

    /**
     * The §2.7 convention decision over PngPixels' straight-alpha RGBA8.
     *
     * PngPixels has already applied any tRNS key (an exact-match pixel comes back
     * alpha 0), so "carries transparency" is alpha below 255 anywhere, and uniform
     * alpha across the mask is the covers-everything advisory. Opaque and chromatic
     * is VERDICT_NEITHER (the caller refuses); opaque and achromatic is luminance.
     *
     * @return array{0: string, 1: ?string} mode/advisory, or VERDICT_NEITHER
     */
    private static function decideRgba(RawRgba $image): array
    {
        $rgba = $image->rgba;
        $count = $image->width * $image->height;

        $alphaMin = 255;
        $alphaMax = 0;
        $grayMin = 255;
        $grayMax = 0;
        $chromatic = false;

        for ($i = 0, $o = 0; $i < $count; $i++, $o += 4) {
            $r = \ord($rgba[$o]);
            $g = \ord($rgba[$o + 1]);
            $b = \ord($rgba[$o + 2]);
            $a = \ord($rgba[$o + 3]);

            $alphaMin = min($alphaMin, $a);
            $alphaMax = max($alphaMax, $a);
            $grayMin = min($grayMin, $r);
            $grayMax = max($grayMax, $r);
            $chromatic = $chromatic || $r !== $g || $g !== $b;
        }

        if ($alphaMin < 255) {
            return [self::MODE_ALPHA, $alphaMin === $alphaMax ? self::ADVISORY_COVERS_EVERYTHING : null];
        }

        if ($chromatic) {
            return self::VERDICT_NEITHER;
        }

        return [self::MODE_LUMINANCE, self::uniformAdvisory($grayMin, $grayMax)];
    }

    /** Flat-gray masks get an advisory; varied ones never do. */
    private static function uniformAdvisory(int $grayMin, int $grayMax): ?string
    {
        if ($grayMin !== $grayMax) {
            return null;
        }

        return match (true) {
            $grayMax === 255 => self::ADVISORY_COVERS_EVERYTHING,
            $grayMax === 0 => self::ADVISORY_COVERS_NOTHING,
            default => self::ADVISORY_UNIFORM_LEVEL,
        };
    }

    /**
     * GD fallback for every shape PngPixels declines: 16-bit and interlaced PNGs,
     * malformed or CRC-failing streams, JPEG and GIF. Disclosed
     * limitation: GD has already quantised 8-bit alpha to 7 bits by this
     * point, so a declined-shape file whose alphas sit ONLY in the 252..254
     * band would read opaque here — a quarter-percent band on shapes no mask
     * tool emits, and the classification only ever rides the row label: the
     * wire bytes are the untouched file either way.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private static function walkDecoded(string $bytes): ?array
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        try {
            if (imageistruecolor($image) === false && imagepalettetotruecolor($image) === false) {
                return null;
            }

            $width = imagesx($image);
            $height = imagesy($image);
            $alphaMin = 127;
            $alphaMax = 0;
            $grayMin = 255;
            $grayMax = 0;
            $chromatic = false;

            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $rgba = imagecolorat($image, $x, $y);
                    $a = ($rgba >> 24) & 0x7F;
                    $r = ($rgba >> 16) & 0xFF;
                    $g = ($rgba >> 8) & 0xFF;
                    $b = $rgba & 0xFF;

                    $alphaMin = min($alphaMin, $a);
                    $alphaMax = max($alphaMax, $a);
                    $grayMin = min($grayMin, $r);
                    $grayMax = max($grayMax, $r);
                    $chromatic = $chromatic || ($r !== $g || $g !== $b);
                }
            }

            if ($alphaMax > 0) {
                return [self::MODE_ALPHA, $alphaMin === $alphaMax ? self::ADVISORY_COVERS_EVERYTHING : null];
            }

            if ($chromatic) {
                return self::VERDICT_NEITHER;
            }

            return [self::MODE_LUMINANCE, self::uniformAdvisory($grayMin, $grayMax)];
        } finally {
            imagedestroy($image);
        }
    }
}

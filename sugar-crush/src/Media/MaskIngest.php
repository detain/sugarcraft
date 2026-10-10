<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media;

use SugarCraft\Mosaic\ImageSource;

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
 * Classification reads the PNG bytes directly for the common shape (8-bit,
 * non-interlaced, colour types 0/2/4/6) because PHP's GD quantises 8-bit
 * alpha down to 7 bits on LOAD: a pixel at alpha 252..254 reads back fully
 * opaque through `imagecolorat()`, and the plan's law is that a SINGLE
 * 1-step-transparent pixel makes the whole file an alpha mask. Everything
 * the byte walk declines (palettes, 16-bit, interlaced, JPEG/GIF) falls
 * through to a pixel walk over the decoded image, where the 7-bit blind band
 * is disclosed in the fallback's own docblock.
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
     * Walk verdict marker for "decided: neither convention" — distinct from
     * null, which means "not this walk's shape, try the next one".
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
        $verdict = str_starts_with($source->bytes, self::PNG_SIGNATURE)
            ? self::walkPngBytes($source->bytes)
            : null;

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
     * Byte-exact walk of the common PNG shape: 8-bit, non-interlaced, colour
     * types 0 (gray), 2 (RGB), 4 (gray+alpha), 6 (RGBA). Returns null for any
     * other shape (or malformed stream) so the caller falls through to GD.
     *
     * @return array{0: string, 1: ?string}|null mode/advisory, VERDICT_NEITHER,
     *         or null = not my shape (fall through)
     */
    private static function walkPngBytes(string $bytes): ?array
    {
        $position = 8;
        $length = strlen($bytes);
        $idat = '';
        $width = 0;
        $height = 0;
        $bitDepth = 0;
        $colorType = -1;
        $interlace = 1;
        $trns = null;

        while ($position + 8 <= $length) {
            $chunk = unpack('Nlen', substr($bytes, $position, 4));
            if ($chunk === false) {
                return null;
            }
            $type = substr($bytes, $position + 4, 4);
            $size = (int) $chunk['len'];
            $dataStart = $position + 8;
            if ($size > $length - $dataStart) {
                return null;
            }
            $data = substr($bytes, $dataStart, $size);
            $position = $dataStart + $size + 4;

            if ($type === 'IHDR') {
                $header = unpack('Nw/Nh/Cdepth/Cctype/Ccomp/Cfilt/Cinter', $data);
                if ($header === false) {
                    return null;
                }
                $width = (int) $header['w'];
                $height = (int) $header['h'];
                $bitDepth = (int) $header['depth'];
                $colorType = (int) $header['ctype'];
                $interlace = (int) $header['inter'];
            } elseif ($type === 'tRNS') {
                $trns = $data;
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }
            // PLTE and everything else: irrelevant to the types this walk covers.
        }

        if ($interlace !== 0 || $bitDepth !== 8 || !isset([0 => 1, 2 => 1, 4 => 1, 6 => 1][$colorType])) {
            return null;
        }

        $channels = match ($colorType) { 0 => 1, 2 => 3, 4 => 2, default => 4 };
        $raw = @gzuncompress($idat);
        if ($raw === false || strlen($raw) !== $height * (1 + $width * $channels)) {
            return null;
        }

        $pixels = self::unfilter($raw, $width, $height, $channels);

        return $pixels === null ? null : self::decide($pixels, $width * $height, $colorType, $trns);
    }

    /**
     * Reconstruct raw 8-bit samples by reversing the per-scanline filters
     * (None/Sub/Up/Average/Paeth, ISO 15948 §7.2, byte-wise with bpp equal to
     * the channel count).
     *
     * @return list<int>|null flat sample list, null on an unknown filter byte
     */
    private static function unfilter(string $raw, int $width, int $height, int $channels): ?array
    {
        $rowLen = $width * $channels;
        $samples = [];
        $previous = array_fill(0, $rowLen, 0);
        $offset = 0;

        for ($y = 0; $y < $height; $y++) {
            $filter = ord($raw[$offset++]);
            if ($filter > 4) {
                return null;
            }
            $current = [];
            for ($x = 0; $x < $rowLen; $x++) {
                $byte = ord($raw[$offset + $x]);
                $left = $x >= $channels ? $current[$x - $channels] : 0;
                $up = $previous[$x];
                $upLeft = $x >= $channels ? $previous[$x - $channels] : 0;
                $current[$x] = match ($filter) {
                    0 => $byte,
                    1 => ($byte + $left) & 0xFF,
                    2 => ($byte + $up) & 0xFF,
                    3 => ($byte + intdiv($left + $up, 2)) & 0xFF,
                    default => ($byte + self::paeth($left, $up, $upLeft)) & 0xFF,
                } & 0xFF;
            }
            $offset += $rowLen;
            $samples = [...$samples, ...$current];
            $previous = $current;
        }

        return $samples;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
    }

    /**
     * The §2.7 convention decision over decoded samples: transparency present
     * anywhere → alpha mode; opaque and strictly achromatic → luminance mode;
     * opaque and chromatic → VERDICT_NEITHER (the caller refuses).
     *
     * tRNS (exact-value keyed transparency, colour types 0/2) counts as
     * "carries transparency" only when at least one pixel matches the key —
     * presence-only would mislabel a keyed-but-unused file.
     *
     * @param list<int> $pixels flat 8-bit samples
     *
     * @return array{0: string, 1: ?string}|null
     */
    private static function decide(array $pixels, int $count, int $colorType, ?string $trns): ?array
    {
        $hasChannelAlpha = $colorType === 4 || $colorType === 6;
        [$keyGray, $keyR, $keyG, $keyB] = self::trnsKey($colorType, $trns);

        $alphaMin = 255;
        $alphaMax = 0;
        $grayMin = 255;
        $grayMax = 0;
        $chromatic = false;
        $keyedTransparent = 0;

        for ($i = 0; $i < $count; $i++) {
            $r = null;
            $g = null;
            $b = null;
            $a = 255;

            if ($colorType === 0) {
                $gray = $pixels[$i];
                $isKey = $keyGray !== null && $gray === $keyGray;
            } elseif ($colorType === 2) {
                [$r, $g, $b] = [$pixels[$i * 3], $pixels[$i * 3 + 1], $pixels[$i * 3 + 2]];
                $gray = $r;
                $isKey = $keyR !== null && $r === $keyR && $g === $keyG && $b === $keyB;
            } elseif ($colorType === 4) {
                $gray = $pixels[$i * 2];
                $a = $pixels[$i * 2 + 1];
                $isKey = false;
            } else {
                [$r, $g, $b] = [$pixels[$i * 4], $pixels[$i * 4 + 1], $pixels[$i * 4 + 2]];
                $a = $pixels[$i * 4 + 3];
                $gray = $r;
                $isKey = false;
            }

            $alphaMin = min($alphaMin, $a);
            $alphaMax = max($alphaMax, $a);
            $grayMin = min($grayMin, $gray);
            $grayMax = max($grayMax, $gray);
            $chromatic = $chromatic || ($r !== null && ($r !== $g || $g !== $b));
            $keyedTransparent += $isKey ? 1 : 0;
        }

        $channelTransparent = $hasChannelAlpha && $alphaMin < 255;
        if ($channelTransparent || $keyedTransparent > 0) {
            $uniform = $channelTransparent
                ? $alphaMin === $alphaMax
                : $keyedTransparent === $count;

            return [self::MODE_ALPHA, $uniform ? self::ADVISORY_COVERS_EVERYTHING : null];
        }

        if ($chromatic) {
            return self::VERDICT_NEITHER;
        }

        return [self::MODE_LUMINANCE, self::uniformAdvisory($grayMin, $grayMax)];
    }

    /**
     * The decoded tRNS key for colour types 0/2 (8-bit files only reach the
     * caller that consumes this — high bytes are read but must be zero to
     * match any sample); null when no usable key chunk exists.
     *
     * @return array{0: ?int, 1: ?int, 2: ?int, 3: ?int}
     */
    private static function trnsKey(int $colorType, ?string $trns): array
    {
        if ($trns === null) {
            return [null, null, null, null];
        }

        if ($colorType === 0 && strlen($trns) >= 2 && $trns[0] === "\x00") {
            return [ord($trns[1]), null, null, null];
        }

        if ($colorType === 2 && strlen($trns) >= 6 && $trns[0] . $trns[2] . $trns[4] === "\x00\x00\x00") {
            return [null, ord($trns[1]), ord($trns[3]), ord($trns[5])];
        }

        return [null, null, null, null];
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
     * GD fallback for every shape the byte walk declines: palettes (incl.
     * tRNS-keyed), 16-bit and interlaced PNGs, JPEG and GIF. Disclosed
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

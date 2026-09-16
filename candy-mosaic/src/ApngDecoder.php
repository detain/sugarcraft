<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

/**
 * Pure-PHP Animated-PNG (APNG) frame decoder.
 *
 * PHP's bundled GD exposes no frame API — `imagecreatefromstring()` collapses an
 * APNG to its base (first) frame — so animation is decoded by walking the PNG
 * container by hand. The chunk framing makes this tractable: every chunk is
 * length-prefixed and CRC-guarded, the per-frame pixel data is ordinary
 * zlib-inflated, filtered PNG scanlines (`IDAT` for frame 0, `fdAT` for the
 * rest, each carrying a 4-byte sequence number), and `fcTL`/`acTL` give the
 * geometry, timing, and disposal bookkeeping.
 *
 * Unlike GIF (handled through candy-flip), APNG frame compositing is a genuine
 * state machine: each frame is painted onto a persistent canvas under a blend
 * op (`source` replaces the frame rect, `over` alpha-composites), and after it
 * is shown the canvas is disposed per the frame's dispose op — `none` (leave),
 * `background` (clear the rect to transparent), or `previous` (restore the
 * snapshot taken before this frame). Frames are handed back already composited
 * to the full logical-screen canvas, exactly as a viewer would show them.
 *
 * This decoder is deliberately scoped: bit depth 8, non-interlaced, colour types
 * 0/2/3/4/6 (grayscale, grayscale+alpha, RGB, palette, RGBA) — the set every
 * real terminal-oriented APNG uses. Anything outside it fails loud rather than
 * guessing.
 *
 * Mirrors the APNG spec (Mozilla, feature spec + pngspec ext-fcTL/ext-fdAT/ext-acTL).
 *
 * @internal Frame-decode plumbing for {@see ImageSource::fromAnimatedFile()}.
 */
final class ApngDecoder
{
    /** PNG signature — the fixed 8 bytes every PNG/APNG stream opens with. */
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** Dispose ops (ext-fcTL dispose_op). */
    private const DISPOSE_NONE      = 0;
    private const DISPOSE_BACKGROUND = 1;
    private const DISPOSE_PREVIOUS  = 2;

    /** Blend op: source replaces the frame rect; over alpha-composites onto it. */
    private const BLEND_SOURCE = 0;
    private const BLEND_OVER   = 1;

    /** Colour type → samples per pixel (valid only for bit depth 8, the sole depth accepted). */
    private const CHANNELS = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4];

    private function __construct() {}

    /**
     * Cheap header probe: is this byte stream an animated PNG?
     *
     * True only for a valid PNG signature carrying an `acTL` chunk — a plain
     * (non-animated) PNG returns false so callers can fall back to single-frame
     * loading instead of an error.
     */
    public static function isAnimatedPng(string $bytes): bool
    {
        if (strlen($bytes) < 8 || substr($bytes, 0, 8) !== self::SIGNATURE) {
            return false;
        }

        // A total predicate: this is a SNIFF, not a decoder. A PNG with a damaged
        // ancillary chunk or a truncated tail is "not provably animated" here and
        // falls back to single-frame loading; {@see decode()} stays the fail-loud
        // authority when we DO commit to an APNG path.
        try {
            foreach (self::walkChunks($bytes, 8) as $chunk) {
                if ($chunk['type'] === 'acTL') {
                    return true;
                }
                // acTL must precede the first IDAT; stop at the image data.
                if ($chunk['type'] === 'IDAT' || $chunk['type'] === 'IEND') {
                    break;
                }
            }
        } catch (\InvalidArgumentException) {
            return false;
        }

        return false;
    }

    /**
     * Decode every APNG frame to a composited RGBA buffer.
     *
     * Enforces the decompression-bomb ceiling on the FULL canvas up front
     * (per-frame = W×H) and in aggregate (W×H×frameCount) from the cheap
     * `IHDR`/`acTL` headers — BEFORE any pixel buffer is allocated or inflated —
     * so a small-per-frame but huge-in-total animation fails fast instead of
     * exhausting memory.
     *
     * @param string $bytes     Full PNG/APNG file bytes.
     * @param int    $maxPixels Per-frame AND aggregate pixel ceiling (≤ 0 disables).
     * @return list<array{rgba: string, width: int, height: int, delayMs: int}>
     *         Ordered, fully composited frames at the logical-screen size.
     * @throws \InvalidArgumentException  on a malformed/unsupported stream or a
     *                                    declared pixel count over the ceiling
     */
    public static function decode(string $bytes, int $maxPixels): array
    {
        $chunks = self::walkChunks($bytes, 8);
        if ($chunks === []) {
            throw new \InvalidArgumentException(Lang::t('apng.no_ihdr'));
        }
        if ($chunks[0]['type'] !== 'IHDR') {
            throw new \InvalidArgumentException(Lang::t('apng.no_ihdr'));
        }

        $ihdr = self::parseIhdr($chunks[0]['data']);
        [$width, $height, $channels, $colorType] = $ihdr;

        // Per-frame ceiling on the declared canvas: a single oversized frame is
        // refused before anything is allocated or inflated, using the honest IHDR
        // geometry.
        if ($maxPixels > 0 && $width > 0 && $height > 0 && $width * $height > $maxPixels) {
            throw new \InvalidArgumentException(Lang::t('image_source.too_large', [
                'width'  => $width,
                'height' => $height,
                'max'    => $maxPixels,
            ]));
        }

        $frameCount = self::parseAcTL($chunks);

        // Cheap, metadata-only frame walk: pair fcTL with IDAT/fdAT payload
        // pointers WITHOUT inflating or allocating any canvas. This is what the
        // aggregate budget is measured against — `acTL`'s declared count is an
        // attacker-supplied attestation, never a budget.
        $frames = self::collectFrames($chunks);
        if ($frames === []) {
            throw new \InvalidArgumentException(Lang::t('apng.no_frames', ['frames' => $frameCount]));
        }

        // Reconcile the header against the stream. A file that under-declares
        // acTL (e.g. claims 0 or 1 while shipping thousands) would otherwise slip
        // past an aggregate check computed from the declared count and then pay
        // one full-canvas composite per real frame.
        if ($frameCount !== count($frames)) {
            throw new \InvalidArgumentException(Lang::t('apng.frame_count_mismatch', [
                'declared'  => $frameCount,
                'collected' => count($frames),
            ]));
        }
        if (count($frames) > Animation::MAX_FRAMES) {
            throw new \InvalidArgumentException(Lang::t('animation.too_many_frames', [
                'max'   => Animation::MAX_FRAMES,
                'count' => count($frames),
            ]));
        }

        // Aggregate ceiling on the VERIFIED frame count, before compositing, so a
        // small-per-frame / huge-in-total animation bomb fails fast.
        $aggregate = $width * $height * count($frames);
        if ($maxPixels > 0 && $width > 0 && $height > 0 && $aggregate > $maxPixels) {
            throw new \InvalidArgumentException(Lang::t('animation.too_many_pixels', [
                'frames' => count($frames),
                'width'  => $width,
                'height' => $height,
                'total'  => $aggregate,
                'max'    => $maxPixels,
            ]));
        }

        $palette = self::parsePalette($chunks);
        $trns    = self::parseTrns($chunks);

        if ($colorType === 3 && $palette === []) {
            throw new \InvalidArgumentException(Lang::t('apng.no_plte'));
        }

        return self::composite($frames, $width, $height, $channels, $colorType, $palette, $trns);
    }

    // ─── Chunk framing ────────────────────────────────────────────────────────

    /**
     * Walk length-prefixed PNG chunks, verifying each CRC.
     *
     * @return list<array{type: string, data: string}>
     * @throws \InvalidArgumentException  on a truncated stream or a bad CRC
     */
    private static function walkChunks(string $bytes, int $offset): array
    {
        $len = strlen($bytes);
        $out = [];

        while ($offset < $len) {
            // Each chunk: 4-byte length, 4-byte type, `length` data, 4-byte CRC.
            if ($offset + 8 > $len) {
                throw new \InvalidArgumentException(Lang::t('apng.truncated'));
            }
            $un = unpack('N', substr($bytes, $offset, 4));
            $dataLen = $un === false ? 0 : $un[1];
            $type = substr($bytes, $offset + 4, 4);

            // A PNG chunk type is exactly four ASCII letters (ISO 15948 §5.3). The
            // CRC-failure message below interpolates the type, and this is a TUI
            // library whose product is terminal bytes — never let raw attacker
            // bytes reach an exception string. Reject anything else up front.
            if (preg_match('/^[A-Za-z]{4}$/', $type) !== 1) {
                throw new \InvalidArgumentException(Lang::t('apng.bad_chunk_type', ['type' => bin2hex($type)]));
            }

            $dataStart = $offset + 8;
            $crcStart  = $dataStart + $dataLen;
            if ($crcStart + 4 > $len) {
                throw new \InvalidArgumentException(Lang::t('apng.truncated'));
            }

            $data = substr($bytes, $dataStart, $dataLen);
            $crcUn = unpack('N', substr($bytes, $crcStart, 4));
            $storedCrc = $crcUn === false ? 0 : $crcUn[1];
            if ($storedCrc !== crc32($type . $data)) {
                throw new \InvalidArgumentException(Lang::t('apng.bad_chunk_crc', ['type' => $type]));
            }

            $out[] = ['type' => $type, 'data' => $data];

            if ($type === 'IEND') {
                break;
            }
            $offset = $crcStart + 4;
        }

        return $out;
    }

    /**
     * @param string $data IHDR chunk payload.
     * @return array{int,int,int,int}  [width, height, channels, colorType]
     */
    private static function parseIhdr(string $data): array
    {
        if (strlen($data) < 13) {
            throw new \InvalidArgumentException(Lang::t('apng.no_ihdr'));
        }
        $u = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccomp/Cfilter/Cinterlace', $data);
        if ($u === false) {
            throw new \InvalidArgumentException(Lang::t('apng.no_ihdr'));
        }

        if ($u['depth'] !== 8) {
            throw new \InvalidArgumentException(Lang::t('apng.unsupported_bit_depth', ['depth' => $u['depth']]));
        }
        if ($u['interlace'] !== 0) {
            throw new \InvalidArgumentException(Lang::t('apng.unsupported_interlace'));
        }
        // Only deflate/inflate compression (0) and adaptive filtering (0) exist;
        // any other value is a future/invalid revision we must not guess at.
        if ($u['comp'] !== 0 || $u['filter'] !== 0) {
            throw new \InvalidArgumentException(Lang::t('apng.unsupported_method'));
        }
        if (!isset(self::CHANNELS[$u['color']])) {
            throw new \InvalidArgumentException(Lang::t('apng.unsupported_color_type', ['type' => $u['color']]));
        }

        return [(int) $u['width'], (int) $u['height'], self::CHANNELS[$u['color']], (int) $u['color']];
    }

    /**
     * @param list<array{type: string, data: string}> $chunks
     */
    private static function parseAcTL(array $chunks): int
    {
        foreach ($chunks as $c) {
            if ($c['type'] === 'acTL') {
                if (strlen($c['data']) < 8) {
                    throw new \InvalidArgumentException(Lang::t('apng.bad_acTL'));
                }
                $u = unpack('Nframes/Nplays', $c['data']);

                return $u === false ? 0 : (int) $u['frames'];
            }
        }

        return 0;
    }

    /**
     * @param list<array{type: string, data: string}> $chunks
     * @return list<int>  flat RGB triples (0..255) from PLTE
     */
    private static function parsePalette(array $chunks): array
    {
        foreach ($chunks as $c) {
            if ($c['type'] === 'PLTE') {
                // PLTE is a sequence of whole RGB triples, 1..256 of them. A torn
                // length is corruption — refuse rather than read a split entry.
                if (strlen($c['data']) % 3 !== 0 || strlen($c['data']) > 768) {
                    throw new \InvalidArgumentException(Lang::t('apng.bad_plte', ['bytes' => strlen($c['data'])]));
                }

                return array_values(unpack('C*', $c['data']) ?: []);
            }
        }

        return [];
    }

    /**
     * @param list<array{type: string, data: string}> $chunks
     * @return list<int>  per-index alpha (0..255) from tRNS, empty when absent
     */
    private static function parseTrns(array $chunks): array
    {
        foreach ($chunks as $c) {
            if ($c['type'] === 'tRNS') {
                return array_values(unpack('C*', $c['data']) ?: []);
            }
        }

        return [];
    }

    // ─── Frame assembly ─────────────────────────────────────────────────────

    /**
     * Pair each `fcTL` with its image data: frame 0 draws from the following
     * `IDAT` chunks, every later frame from the `fdAT` chunks until the next
     * `fcTL`. Sequence numbers must increase monotonically — an inversion means
     * the stream is corrupt or hostile.
     *
     * @param list<array{type: string, data: string}> $chunks
     * @return list<array{fc: string, data: string}>  one entry per frame
     */
    private static function collectFrames(array $chunks): array
    {
        $frames = [];
        $pending = null;
        $lastSeq = -1;

        foreach ($chunks as $c) {
            switch ($c['type']) {
                case 'fcTL':
                    if (strlen($c['data']) < 26) {
                        throw new \InvalidArgumentException(Lang::t('apng.bad_fcTL'));
                    }
                    $u = unpack('Nseq/Nw/Nh/Nx/Ny/nnum/nden/Cdispose/Cblend', $c['data']);
                    if ($u === false) {
                        throw new \InvalidArgumentException(Lang::t('apng.bad_fcTL'));
                    }
                    $seq = (int) $u['seq'];
                    if ($seq <= $lastSeq) {
                        throw new \InvalidArgumentException(Lang::t('apng.sequence'));
                    }
                    $lastSeq = $seq;
                    if ($pending !== null) {
                        $frames[] = $pending;
                    }
                    $pending = ['fc' => $c['data'], 'data' => ''];
                    break;

                case 'IDAT':
                    // IDAT feeds only the first frame (its data sits between the
                    // first fcTL and the second); later frames use fdAT.
                    if ($pending !== null && $frames === []) {
                        $pending['data'] .= $c['data'];
                    }
                    break;

                case 'fdAT':
                    if ($pending === null) {
                        throw new \InvalidArgumentException(Lang::t('apng.sequence'));
                    }
                    if (strlen($c['data']) < 4) {
                        throw new \InvalidArgumentException(Lang::t('apng.truncated'));
                    }
                    $seq = unpack('N', substr($c['data'], 0, 4))[1];
                    if ($seq <= $lastSeq) {
                        throw new \InvalidArgumentException(Lang::t('apng.sequence'));
                    }
                    $lastSeq = $seq;
                    $pending['data'] .= substr($c['data'], 4);
                    break;
            }
        }

        if ($pending !== null) {
            $frames[] = $pending;
        }

        return $frames;
    }

    // ─── Compositing state machine ──────────────────────────────────────────

    /**
     * Inflate + unfilter each frame, then paint it onto a persistent canvas
     * honouring blend and dispose ops, emitting a fully composited snapshot per
     * frame.
     *
     * @param list<array{fc: string, data: string}> $frames
     * @param list<int> $palette
     * @param list<int> $trns
     * @return list<array{rgba: string, width: int, height: int, delayMs: int}>
     */
    private static function composite(array $frames, int $canvasW, int $canvasH, int $channels, int $colorType, array $palette, array $trns): array
    {
        // Canvas is a flat RGBA byte buffer (4 bytes/pixel), fully transparent.
        $canvas = str_repeat("\0", $canvasW * $canvasH * 4);

        $out = [];

        foreach ($frames as $frame) {
            $fc = unpack('Nseq/Nw/Nh/Nx/Ny/nnum/nden/Cdispose/Cblend', $frame['fc']);
            if ($fc === false) {
                throw new \InvalidArgumentException(Lang::t('apng.bad_fcTL'));
            }
            $fw = (int) $fc['w'];
            $fh = (int) $fc['h'];
            $fx = (int) $fc['x'];
            $fy = (int) $fc['y'];
            $dispose = (int) $fc['dispose'];
            $blend = (int) $fc['blend'];

            if ($fw <= 0 || $fh <= 0 || $fx < 0 || $fy < 0 || $fx + $fw > $canvasW || $fy + $fh > $canvasH) {
                throw new \InvalidArgumentException(Lang::t('apng.bad_fcTL'));
            }
            // dispose_op ∈ {0,1,2} and blend_op ∈ {0,1}; the reserved values must
            // not be silently coerced to a neighbouring op.
            if ($dispose < self::DISPOSE_NONE || $dispose > self::DISPOSE_PREVIOUS) {
                throw new \InvalidArgumentException(Lang::t('apng.unsupported_dispose', ['op' => $dispose]));
            }
            if ($blend !== self::BLEND_SOURCE && $blend !== self::BLEND_OVER) {
                throw new \InvalidArgumentException(Lang::t('apng.unsupported_blend', ['op' => $blend]));
            }

            // Snapshot BEFORE drawing, for DISPOSE_PREVIOUS.
            $before = $dispose === self::DISPOSE_PREVIOUS ? $canvas : null;

            $raw = self::inflateAndUnfilter($frame['data'], $fw, $fh, $channels, $colorType, $palette, $trns);
            $canvas = self::paint($canvas, $raw, $canvasW, $fx, $fy, $fw, $fh, $channels, $blend, $palette, $trns);

            $out[] = [
                'rgba'    => $canvas,
                'width'   => $canvasW,
                'height'  => $canvasH,
                'delayMs' => self::delayMs((int) $fc['num'], (int) $fc['den']),
            ];

            $canvas = self::dispose($canvas, $before, $dispose, $canvasW, $fx, $fy, $fw, $fh);
        }

        return $out;
    }

    /**
     * fcTL delay as milliseconds. A zero (or absent) denominator means 100 per
     * the APNG spec; a zero numerator is an honest 0 (caller/driver decides).
     */
    private static function delayMs(int $num, int $den): int
    {
        if ($den <= 0) {
            $den = 100;
        }

        return (int) round($num / $den * 1000);
    }

    /**
     * Paint one decoded frame onto the canvas within its offset rectangle.
     *
     * @param string $canvas  full-canvas RGBA (by value; returned mutated copy)
     * @param string $raw     frame RGBA buffer, fw×fh×4
     * @param list<int> $palette
     * @param list<int> $trns
     */
    private static function paint(
        string $canvas,
        string $raw,
        int $canvasW,
        int $fx,
        int $fy,
        int $fw,
        int $fh,
        int $channels,
        int $blend,
        array $palette,
        array $trns,
    ): string {
        $rowBytes = $fw * 4;
        for ($y = 0; $y < $fh; $y++) {
            $dstRow = (($fy + $y) * $canvasW + $fx) * 4;
            $srcRow = $y * $rowBytes;
            for ($x = 0; $x < $fw; $x++) {
                $s = $srcRow + $x * 4;
                $sr = ord($raw[$s]);
                $sg = ord($raw[$s + 1]);
                $sb = ord($raw[$s + 2]);
                $sa = ord($raw[$s + 3]);
                $d = $dstRow + $x * 4;

                if ($blend === self::BLEND_SOURCE || $sa === 255) {
                    // Source replaces (transparent frame pixels punch through to
                    // transparency); a fully-opaque over also lands verbatim.
                    $canvas[$d]     = chr($sr);
                    $canvas[$d + 1] = chr($sg);
                    $canvas[$d + 2] = chr($sb);
                    $canvas[$d + 3] = chr($sa);
                    continue;
                }

                if ($sa === 0) {
                    // Fully transparent under "over" leaves the canvas untouched.
                    continue;
                }

                [$dr, $dg, $db, $da] = [ord($canvas[$d]), ord($canvas[$d + 1]), ord($canvas[$d + 2]), ord($canvas[$d + 3])];
                // Porter-Duff "source over" on straight (unpremultiplied) bytes.
                $aS = $sa / 255.0;
                $aD = $da / 255.0;
                $aO = $aS + $aD * (1.0 - $aS);
                if ($aO <= 0.0) {
                    $canvas[$d] = "\x00";
                    $canvas[$d + 1] = "\x00";
                    $canvas[$d + 2] = "\x00";
                    $canvas[$d + 3] = "\x00";
                    continue;
                }
                $canvas[$d]     = chr((int) round(($sr * $aS + $dr * $aD * (1.0 - $aS)) / $aO));
                $canvas[$d + 1] = chr((int) round(($sg * $aS + $dg * $aD * (1.0 - $aS)) / $aO));
                $canvas[$d + 2] = chr((int) round(($sb * $aS + $db * $aD * (1.0 - $aS)) / $aO));
                $canvas[$d + 3] = chr((int) round($aO * 255.0));
            }
        }

        return $canvas;
    }

    /**
     * Apply a frame's disposal to the canvas for the NEXT frame.
     *
     * @param string|null $before canvas snapshot before this frame was drawn
     */
    private static function dispose(string $canvas, ?string $before, int $dispose, int $canvasW, int $fx, int $fy, int $fw, int $fh): string
    {
        if ($dispose === self::DISPOSE_PREVIOUS && $before !== null) {
            return $before;
        }
        if ($dispose === self::DISPOSE_BACKGROUND) {
            $clear = str_repeat("\0", $fw * 4);
            for ($y = 0; $y < $fh; $y++) {
                $start = (($fy + $y) * $canvasW + $fx) * 4;
                $canvas = substr_replace($canvas, $clear, $start, $fw * 4);
            }
        }

        return $canvas;
    }

    // ─── Scanline decoding ──────────────────────────────────────────────────

    /**
     * Inflate a frame's zlib stream and reverse PNG per-scanline filtering,
     * returning an RGBA buffer (fw×fh×4). Colour type 3 (palette) is expanded to
     * RGBA using PLTE + tRNS; every other supported type maps channels directly.
     *
     * @param list<int> $palette
     * @param list<int> $trns
     */
    private static function inflateAndUnfilter(string $zlibData, int $fw, int $fh, int $channels, int $colorType, array $palette, array $trns): string
    {
        if ($zlibData === '') {
            throw new \InvalidArgumentException(Lang::t('apng.no_frame_data'));
        }

        // Stride in bytes of the raw (pre-filter) scanline.
        $stride = $fw * $channels;
        $expected = $fh * ($stride + 1); // + filter byte per row

        // Bound the inflate ITSELF to the declared geometry before a single byte
        // materialises: PHP stops decompressing at $expected rather than building a
        // multi-megabyte buffer for a tiny frame (decompression bomb). A well-formed
        // stream inflates to EXACTLY $expected (ISO 15948 §6.3 — one filter byte plus
        // one stride per scanline), so anything larger is a bomb and anything smaller
        // is truncation; both are refused.
        $inflated = @gzuncompress($zlibData, $expected);
        if ($inflated === false) {
            // false means either a corrupt stream or one that would overflow the
            // ceiling — both fail loud, neither spends the memory.
            throw new \InvalidArgumentException(Lang::t('apng.inflate_failed'));
        }
        if (strlen($inflated) !== $expected) {
            throw new \InvalidArgumentException(Lang::t('apng.bad_frame_length', [
                'actual'   => strlen($inflated),
                'expected' => $expected,
            ]));
        }

        // bytes-per-pixel for the filter reference (>=1; whole pixels for depth 8).
        $bpp = max(1, $channels);
        $prev = str_repeat("\0", $stride);
        $rgba = '';

        for ($row = 0; $row < $fh; $row++) {
            $filterType = ord($inflated[$row * ($stride + 1)]);
            $line = substr($inflated, $row * ($stride + 1) + 1, $stride);
            $cur = self::unfilterLine($line, $prev, $filterType, $bpp);
            $rgba .= self::expandRowToRgba($cur, $channels, $colorType, $fw, $palette, $trns);
            $prev = $cur;
        }

        return $rgba;
    }

    /**
     * Reverse the five PNG scanline filters for one row.
     *
     * @param string $line raw filtered bytes for the row (length stride)
     * @param string $prev reconstructed bytes of the row above (length stride)
     * @return string reconstructed row bytes
     */
    private static function unfilterLine(string $line, string $prev, int $filterType, int $bpp): string
    {
        $stride = strlen($line);
        $cur = str_repeat("\0", $stride);

        for ($i = 0; $i < $stride; $i++) {
            $rawByte = ord($line[$i]);
            $left = $i >= $bpp ? ord($cur[$i - $bpp]) : 0;
            $up = ord($prev[$i]);
            $upLeft = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;

            $recon = match ($filterType) {
                0       => $rawByte,
                1       => $rawByte + $left,
                2       => $rawByte + $up,
                3       => $rawByte + intdiv($left + $up, 2),
                4       => $rawByte + self::paeth($left, $up, $upLeft),
                default => throw new \InvalidArgumentException(Lang::t('apng.unsupported_filter', ['type' => $filterType])),
            };
            $cur[$i] = chr($recon & 0xFF);
        }

        return $cur;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }

        return $pb <= $pc ? $b : $c;
    }

    /**
     * Colour-type-specific row expansion (palette vs non-palette), invoked from
     * {@see inflateAndUnfilter()} where the palette is in scope.
     *
     * @param list<int> $palette
     * @param list<int> $trns
     */
    private static function expandRowToRgba(string $row, int $channels, int $colorType, int $fw, array $palette, array $trns): string
    {
        if ($colorType === 3) {
            $size = intdiv(count($palette), 3);
            $out = '';
            for ($x = 0; $x < $fw; $x++) {
                $idx = ord($row[$x]);
                // An index past the palette is a fatal spec violation — fail loud
                // rather than paint opaque black and hide the corruption.
                if (!isset($palette[$idx * 3 + 2])) {
                    throw new \InvalidArgumentException(Lang::t('apng.palette_index_out_of_range', [
                        'index' => $idx,
                        'size'  => $size,
                    ]));
                }
                $r = $palette[$idx * 3];
                $g = $palette[$idx * 3 + 1];
                $b = $palette[$idx * 3 + 2];
                // A tRNS shorter than the palette is legal: absent entries are opaque.
                $a = $trns[$idx] ?? 255;
                $out .= chr($r) . chr($g) . chr($b) . chr($a);
            }

            return $out;
        }

        $out = '';
        for ($x = 0; $x < $fw; $x++) {
            $base = $x * $channels;
            $out = match ($channels) {
                4 => $out . substr($row, $base, 4),
                3 => $out . $row[$base] . $row[$base + 1] . $row[$base + 2] . "\xff",
                2 => $out . $row[$base] . $row[$base] . $row[$base] . $row[$base + 1],
                1 => $out . $row[$base] . $row[$base] . $row[$base] . "\xff",
                default => throw new \InvalidArgumentException(Lang::t('apng.unsupported_color_type', ['type' => $channels])),
            };
        }

        return $out;
    }
}

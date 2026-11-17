<?php

declare(strict_types=1);

namespace SugarCraft\Image;

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
 * Unlike GIF (handled through sugar-animation), APNG frame compositing is a genuine
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

    /**
     * Hard ceiling on each logical-screen dimension, independent of the pixel
     * budget. A PNG dimension is a uint32; two large ones multiplied together can
     * overflow past {@see PHP_INT_MAX} when the budget is disabled
     * (`$maxPixels <= 0`) and surface as a raw `TypeError` from `str_repeat()`
     * rather than a typed parse error. No TUI needs an image wider than this.
     */
    private const HARD_MAX_DIM = 16384;

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
            foreach (PngPixels::walkChunks($bytes, 8) as $chunk) {
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
        // `@internal` is a comment, not an access boundary — decode() is public and
        // callable directly (bypassing isAnimatedPng()), so verify the signature
        // here rather than letting an arbitrary 8-byte prefix decode to a
        // no_ihdr/bad_chunk_crc that blames the wrong thing.
        if (strlen($bytes) < 8 || substr($bytes, 0, 8) !== self::SIGNATURE) {
            throw new \InvalidArgumentException(Lang::t('apng.no_signature'));
        }
        $chunks = PngPixels::walkChunks($bytes, 8);
        if ($chunks === []) {
            throw new \InvalidArgumentException(Lang::t('apng.no_ihdr'));
        }
        if ($chunks[0]['type'] !== 'IHDR') {
            throw new \InvalidArgumentException(Lang::t('apng.no_ihdr'));
        }

        $ihdr = PngPixels::parseIhdr($chunks[0]['data']);
        [$width, $height, $channels, $colorType] = $ihdr;

        // Format sanity independent of the pixel budget: reject zero or
        // absurd geometry with a typed parse error so the str_repeat() canvas
        // allocation downstream can never see a float operand (which happens when
        // two huge uint32 dims multiply past PHP_INT_MAX with $maxPixels <= 0).
        if ($width <= 0 || $height <= 0 || $width > self::HARD_MAX_DIM || $height > self::HARD_MAX_DIM) {
            throw new \InvalidArgumentException(Lang::t('apng.unsupported_dimensions', [
                'width'  => $width,
                'height' => $height,
                'max'    => self::HARD_MAX_DIM,
            ]));
        }

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

        $palette = PngPixels::parsePalette($chunks);
        $trns    = PngPixels::parseTrns($chunks);

        if ($colorType === 3 && $palette === []) {
            throw new \InvalidArgumentException(Lang::t('apng.no_plte'));
        }

        return self::composite($frames, $width, $height, $channels, $colorType, $palette, $trns);
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

            $raw = PngPixels::inflateAndUnfilter($frame['data'], $fw, $fh, $channels, $colorType, $palette, $trns);
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
}

<?php

declare(strict_types=1);

namespace SugarCraft\Video\Decode;

use SugarCraft\Video\Lang;
use SugarCraft\Video\Render\Mode;

/**
 * E-05.2: animated WebP decoder — pure PHP + GD, no subprocess.
 *
 * WHY NOT FFMPEG (the plan's first candidate, refuted by measurement on this
 * tree's host): ffmpeg's webp demuxer AND its native webp decoder both skip
 * ANMF chunks outright — `ffmpeg -i anim.webp -f image2pipe -vcodec png -`
 * exits "image data not found" (or yields only a base canvas frame when the
 * file carries one) for every animated file tried. No released ffmpeg decodes
 * animated WebP frames. chafa solves this by linking libwebp's demux API
 * (chicle-webp-loader.c:151-212 WebPAnimDecoderGetInfo + per-timestamp
 * deltas); the same libwebp already sits behind THIS PHP's GD — so the
 * honest port is the container walk chicle-webp-loader.c also does, in PHP,
 * with GD decoding each frame's embedded still bitstream. No child process
 * is spawned at all: the bounded-reap/roster machinery of the ffmpeg
 * decoders is simply not needed here, and playback works on hosts without
 * ffmpeg — the refusal→decode flip E-05.2 asked for, one dependency richer
 * for nothing.
 *
 * HOW IT WORKS: the container is parsed once at open() — every ANMF chunk
 * carries a complete still-WebP chunk sequence (VP8/VP8L, optional ALPH) in
 * its payload after a 16-byte geometry header. Each still is decoded by GD
 * (imagecreatefromstring over a synthesized RIFF container) and composited
 * onto the canvas honoring the WebP animation spec: frame offsets/dimensions
 * (half-unit encoded), the blend bit (0 = alpha blend onto canvas, 1 = raw
 * copy) and the disposal bit (1 = restore the frame rectangle to the
 * background before the NEXT frame draws). Per-frame durations (24-bit ms)
 * and the loop count are exposed via frameDelaysMs()/loopCount() for
 * delay-aware consumers.
 *
 * LIMITS (documented, intentional):
 *  - LOCAL FILES ONLY. A network .webp is routed to FfmpegDecoder by
 *    {@see DecoderFactory}, exactly as before — over the wire it inherits
 *    ffmpeg's animation blindness (typically one base frame), because
 *    fetching-then-decoding a remote container is a transport feature, not
 *    a decoder one. isAnimated() is only consulted for local paths.
 *  - PLAYER PACING STAYS UNIFORM. The Decoder contract has no per-frame
 *    delay channel (RgbFrame carries pixels only) and Player ticks at
 *    1/fps, so playback paces variable-delay WebP at the caller's nominal
 *    fps — the same approximation the GifDecoder path already makes
 *    (GifDecoder::open() "uses the caller's nominal fps as an
 *    approximation"). frameDelaysMs() is the seam for a delay-aware driver
 *    without widening this interface today.
 *  - startSec is a container-truth skip (cumulative durations), exact for
 *    the forward-only pipe — better than GifDecoder's fps-rounded guess.
 *
 * @implements Decoder
 */
final class AnimatedWebpDecoder implements Decoder
{
    /** Ceiling on frames accepted from one container — a hostile/corrupt walk cannot balloon memory through the compositing loop. */
    public const MAX_FRAMES = 4096;

    /**
     * Byte ceiling for the pre-parse source read, mirroring the GifDecoder
     * fx61b capture gate on the WebP side: files above the ceiling are
     * refused BEFORE being slurped into memory (the stat leg), with a
     * cap+1 over-read and a strlen refusal as the backstop for sources
     * whose size stat cannot see. A file exactly AT the cap is accepted:
     * the gate is `size > cap`, not `>=`.
     */
    public const MAX_SOURCE_BYTES = 67_108_864; // 64 MiB

    /** Ceiling on the canvas area in pixels (2048×2048) — GD canvases are 4 bytes per pixel before PHP object overhead. */
    public const MAX_CANVAS_PIXELS = 4_194_304;

    /** Ceiling on frames × canvas area summed — the whole composited animation's storage bound (~128 MB at 4 B/px). */
    public const MAX_TOTAL_PIXELS = 33_554_432;

    /** Chunk-walk step ceiling in isAnimated() — bounded so a pathological header chain cannot spin. */
    private const MAX_CHUNK_STEPS = 10_000;

    /** ANMF payload header size: 3+3+3+3 offset/size + 3 duration + 1 blend/dispose flags. */
    private const ANMF_HEADER_BYTES = 16;

    /** ANMF flags bit 1 (bl): when SET, the frame is copied WITHOUT alpha blending. */
    private const ANMF_FLAG_NO_BLEND = 0x02;

    /** ANMF flags bit 2 (dp): when SET, the frame rectangle is restored to the background after display. */
    private const ANMF_FLAG_DISPOSE = 0x04;

    /** VP8X flags bit 1 (a2): animation. */
    private const VP8X_FLAG_ANIM = 0x02;

    /** Pixel width of one terminal cell — graphics modes emit full-resolution PNG frames (FfmpegDecoder/GifDecoder parity). */
    private readonly int $cellPxW;
    private readonly int $cellPxH;

    /** @var list<\GdImage> composited full-canvas snapshots, one per animation frame */
    private array $canvases = [];

    /** @var list<int> per-frame display durations in ms, container order. */
    private array $delaysMs = [];

    private int $loopCount = 0;
    private int $cursor = 0;

    /** Output box every frame is rescaled into (set by open()). */
    private int $frameW = 0;
    private int $frameH = 0;
    private bool $graphics = false;

    /** @var array<int, \GdImage> output-box frames keyed by source canvas object id (memo per open) */
    private array $boxes = [];

    /**
     * @param int $cellPxW terminal cell pixel width (graphics-mode box)
     * @param int $cellPxH terminal cell pixel height
     */
    public function __construct(int $cellPxW = 10, int $cellPxH = 20)
    {
        $this->cellPxW = max(1, $cellPxW);
        $this->cellPxH = max(1, $cellPxH);
    }

    /**
     * Whether this PHP's GD can decode WebP at all — the capability gate that
     * replaces the ffmpeg-presence probe of the original design. Public so
     * consumers (and the factory's callers) can answer "is animated WebP
     * playable here?" without opening a decoder.
     *
     * Test seam: {@see setWebpCapableForTesting()} overrides the live probe.
     */
    public static function supports(): bool
    {
        return self::$supportOverride ?? ((imagetypes() & IMG_WEBP) === IMG_WEBP);
    }

    /** @internal test seam only — null restores the live imagetypes() probe */
    private static ?bool $supportOverride = null;

    public static function setWebpCapableForTesting(?bool $capable): void
    {
        self::$supportOverride = $capable;
    }

    /**
     * Cheap container sniff: does this file claim WebP animation?
     *
     * Reads ONLY chunk headers (fseek-walks chunk bodies) and stops at the
     * first positive signal — a VP8X with the animation flag, or any ANMF
     * chunk (the sugar-image E-05.1 detector shape, kept equivalent so both
     * halves of the ecosystem agree on what "animated" means). Truncated or
     * non-WebP input answers false: the caller then routes as before and the
     * ordinary loud failure happens downstream. Never decodes anything.
     */
    public static function isAnimated(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }

        try {
            $head = (string) fread($fh, 12);
            if (strlen($head) !== 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WEBP') {
                return false;
            }

            for ($step = 0; $step < self::MAX_CHUNK_STEPS; $step++) {
                $meta = (string) fread($fh, 8);
                if (strlen($meta) !== 8) {
                    return false;
                }
                $fourcc = substr($meta, 0, 4);
                $size = unpack('V', substr($meta, 4, 4))[1];

                if ($fourcc === 'ANMF') {
                    return true;
                }
                if ($fourcc === 'VP8X') {
                    $flags = fread($fh, 1);
                    if ($flags !== false && (ord($flags) & self::VP8X_FLAG_ANIM) !== 0) {
                        return true;
                    }
                }

                // Chunk payloads are even-sized; the pad byte belongs to the chunk.
                if (@fseek($fh, $size + ($size & 1), SEEK_CUR) !== 0) {
                    return false;
                }
            }

            return false;
        } finally {
            fclose($fh);
        }
    }

    /**
     * @inheritDoc
     *
     * Parses the container, decodes every frame bitstream with GD, and
     * composites the canvas sequence up front (frame counts are bounded by
     * {@see MAX_FRAMES}/{@see MAX_TOTAL_PIXELS} — animated WebP is a sticker
     * format, not a movie format, and the ceilings fail closed).
     *
     * @param array<array-key, string> $headers meaningless for a local file;
     *        present and non-empty logs the same drop notice FfmpegDecoder
     *        gives before anything is decoded.
     */
    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        $this->close();

        if ($headers !== [] && !FfmpegDecoder::isNetworkSource($source)) {
            error_log(Lang::t('header.ignored_local_source', [
                'count' => count($headers),
                'source' => $source,
            ]));
        }

        if (!self::supports()) {
            throw new \RuntimeException(Lang::t('decoder.webp_gd_missing'));
        }

        if (!is_file($source)) {
            throw new \RuntimeException("video source not found: {$source}");
        }

        // fx61b sibling gate: refuse above-ceiling sources before the read.
        // The stat leg is the pre-read refusal; when a wrapper hides the
        // size (@stat false) the capped read + strlen leg below still fails
        // closed, so the file is never fully slurped either way.
        $size = @stat($source);
        if (is_array($size) && (int) $size['size'] > self::MAX_SOURCE_BYTES) {
            throw new \RuntimeException(sprintf(
                'animated WebP source is %d bytes, above the %d byte ceiling: %s',
                (int) $size['size'],
                self::MAX_SOURCE_BYTES,
                $source,
            ));
        }

        $bytes = @file_get_contents($source, false, null, 0, self::MAX_SOURCE_BYTES + 1);
        if ($bytes === false) {
            throw new \RuntimeException("could not read animated WebP source: {$source}");
        }
        if (strlen($bytes) > self::MAX_SOURCE_BYTES) {
            throw new \RuntimeException(sprintf(
                'animated WebP source is %d bytes, above the %d byte ceiling: %s',
                strlen($bytes),
                self::MAX_SOURCE_BYTES,
                $source,
            ));
        }

        $frames = self::parseContainer($bytes, $canvasW, $canvasH, $this->loopCount);
        if ($frames === []) {
            throw new \RuntimeException("not an animated WebP (no ANMF frames): {$source}");
        }
        if (count($frames) > self::MAX_FRAMES) {
            throw new \RuntimeException(sprintf(
                'animated WebP exceeds the %d frame ceiling: %s',
                self::MAX_FRAMES,
                $source,
            ));
        }
        if ($canvasW * $canvasH > self::MAX_CANVAS_PIXELS || count($frames) * $canvasW * $canvasH > self::MAX_TOTAL_PIXELS) {
            throw new \RuntimeException(sprintf(
                'animated WebP exceeds the canvas/pixel ceilings (%dx%d, %d frames): %s',
                $canvasW,
                $canvasH,
                count($frames),
                $source,
            ));
        }

        [$this->frameW, $this->frameH] = self::outputBox($cellsW, $cellsH, $mode, $this->cellPxW, $this->cellPxH);
        $this->graphics = $mode?->isGraphics() ?? false;

        $this->compositeAll($frames, $canvasW, $canvasH);

        // Container-truth seek: walk cumulative durations to the frame that
        // owns $startSec; a seek past the end lands on the LAST frame
        // (GifDecoder E-11 polarity — jumping to START on a late seek is the
        // visible bug the symmetric clamp avoids).
        if ($startSec > 0.0 && $this->delaysMs !== []) {
            $targetMs = $startSec * 1000.0;
            $acc = 0.0;
            $index = count($this->delaysMs) - 1;
            foreach ($this->delaysMs as $i => $delay) {
                $acc += $delay;
                if ($acc > $targetMs) {
                    $index = $i;
                    break;
                }
            }
            $this->cursor = $index;
        }
    }

    /**
     * @inheritDoc
     *
     * Emits one RgbFrame per animation frame; null is terminal (single play
     * through — the Player loop re-opens via the factory exactly as it does
     * for GifDecoder).
     */
    public function next(): ?RgbFrame
    {
        if ($this->cursor >= count($this->canvases)) {
            return null;
        }

        $canvas = $this->canvases[$this->cursor++];
        $box = $this->boxFor($canvas);

        if ($this->graphics) {
            return new RgbFrame('', $this->frameW, $this->frameH, self::pngOf($box));
        }

        return new RgbFrame(self::rgb24Of($box), $this->frameW, $this->frameH);
    }

    /**
     * Per-frame display durations in milliseconds, container order.
     *
     * The Decoder contract paces uniformly (class-limit note above); this is
     * the container truth for delay-aware drivers. Empty before open().
     *
     * @return list<int>
     */
    public function frameDelaysMs(): array
    {
        return $this->delaysMs;
    }

    /**
     * ANIM chunk loop count: 0 means "repeat forever", N means the sequence
     * plays N+1 times (libwebp semantics). Exposed for hosts that honor it;
     * this decoder itself plays the sequence once per open().
     */
    public function loopCount(): int
    {
        return $this->loopCount;
    }

    /**
     * @inheritDoc
     */
    public function getIterator(): \Generator
    {
        while (($frame = $this->next()) !== null) {
            yield $frame;
        }
    }

    /**
     * @inheritDoc — nothing is held across calls but the composited frames;
     * dropping them releases every GD canvas, so there is no child and no
     * descriptor to reap (contrast FfmpegDecoder::close()).
     */
    public function close(): void
    {
        $this->canvases = [];
        $this->boxes = [];
        $this->delaysMs = [];
        $this->loopCount = 0;
        $this->cursor = 0;
    }

    /**
     * @inheritDoc
     */
    public function reopen(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        $this->close();
        $this->open($source, $cellsW, $cellsH, $fps, $mode, $startSec, $headers);
    }

    /**
     * A fresh open() re-reads (and re-composites) the file, so a seek/resize
     * rebuild is a plain close()+open() — same answer FfmpegDecoder gives.
     */
    public function reopensInPlace(): bool
    {
        return false;
    }

    // -------------------------------------------------------------------------
    // Container
    // -------------------------------------------------------------------------

    /**
     * Walk the RIFF chunk list into frame descriptors.
     *
     * @param-out int $canvasW
     * @param-out int $canvasH
     * @return list<array{x:int,y:int,duration:int,noBlend:bool,dispose:bool,still:string}>
     */
    private static function parseContainer(string $bytes, &$canvasW, &$canvasH, &$loop): array
    {
        $loop = 0;
        $canvasW = 0;
        $canvasH = 0;
        $frames = [];

        if (strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            throw new \RuntimeException('animated WebP source is not a RIFF/WEBP container');
        }

        $i = 12;
        $len = strlen($bytes);
        while ($i + 8 <= $len) {
            $fourcc = substr($bytes, $i, 4);
            $size = unpack('V', substr($bytes, $i + 4, 4))[1];
            $payloadEnd = $i + 8 + $size;
            if ($size % 2 !== 0) {
                $payloadEnd++; // pad byte belongs to the chunk; may be the final missing one on a truncated tail
            }
            if ($payloadEnd > $len) {
                throw new \RuntimeException(sprintf('truncated WebP chunk %s at offset %d', $fourcc, $i));
            }
            $payload = substr($bytes, $i + 8, $size);

            if ($fourcc === 'VP8X') {
                // flags(1) reserved(3) canvasW-1(3) canvasH-1(3)
                $canvasW = self::le24($payload, 4) + 1;
                $canvasH = self::le24($payload, 7) + 1;
            } elseif ($fourcc === 'ANIM') {
                // reserved/background(4) loop_count(2)
                $loop = unpack('v', substr($payload, 4, 2))[1];
            } elseif ($fourcc === 'ANMF') {
                // x/y are stored in half-units per the container spec (stored*2).
                // Width/height fields are NOT trusted: the spec's half-unit
                // encoding is lossy for even dimensions (an exact 32 cannot be
                // spelled), and ffmpeg/libwebp writers spell full-canvas frames
                // differently anyway — the inner bitstream's DECODED size is
                // ground truth, so only x/y offsets are read from the header.
                $frames[] = [
                    'x'        => self::le24($payload, 0) * 2,
                    'y'        => self::le24($payload, 3) * 2,
                    'duration' => self::le24($payload, 12),
                    'noBlend'  => (ord($payload[15]) & self::ANMF_FLAG_NO_BLEND) !== 0,
                    'dispose'  => (ord($payload[15]) & self::ANMF_FLAG_DISPOSE) !== 0,
                    // Everything after the fixed header is a complete still
                    // chunk sequence (VP8X? ALPH? VP8/VP8L) — normalized into
                    // a RIFF container GD can actually read.
                    'still'    => self::stillForGd(substr($payload, self::ANMF_HEADER_BYTES)),
                ];
            }

            $i += 8 + $size + ($size & 1);
        }

        if ($canvasW === 0 || $canvasH === 0) {
            throw new \RuntimeException('animated WebP container has no VP8X canvas declaration');
        }

        return $frames;
    }

    /**
     * Wrap an ANMF payload's chunk sequence into a standalone RIFF/WEBP that
     * this GD's libwebp reader accepts.
     *
     * MEASURED on PHP 8.3 / bundled libwebp: imagecreatefromstring() reads a
     * full file (VP8X+ALPH+VP8) and a bare VP8, but FAILS ("cannot get webp
     * info") on a payload that LEADS with ALPH — the exact shape
     * WebPAnimEncoder puts inside every frame of a partial-rect animated
     * file. So a leading ALPH gets a synthesized VP8X prefix (alpha bit set,
     * canvas = the bitstream's own dimensions, parsed from the VP8 header
     * start code); anything else is wrapped verbatim.
     *
     * @throws \RuntimeException never — a bitstream GD rejects surfaces from
     *         the composite step that consumes the result.
     */
    private static function stillForGd(string $chunks): string
    {
        $wrap = static fn (string $body): string => 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;

        if (strlen($chunks) < 8 || !str_starts_with($chunks, 'ALPH')) {
            return $wrap($chunks);
        }

        $alphSize = unpack('V', substr($chunks, 4, 4))[1];
        $rest = substr($chunks, 8 + $alphSize + ($alphSize & 1));
        if (!str_starts_with($rest, 'VP8 ')) {
            return $wrap($chunks); // ALPH not followed by a lossy bitstream (VP8L never carries one): verbatim, GD says no loudly.
        }

        $vp8Size = unpack('V', substr($rest, 4, 4))[1];
        $dims = self::lossyDimensions(substr($rest, 8, min(10, strlen($rest))));
        if ($dims === null) {
            return $wrap($chunks);
        }
        [$w, $h] = $dims;

        return $wrap(
            'VP8X' . pack('V', 10) . chr(self::VP8X_FLAG_ALPHA) . "\x00\x00\x00"
            . substr(pack('V', $w - 1), 0, 3) . substr(pack('V', $h - 1), 0, 3)
            . $chunks,
        );
    }

    /** VP8X flags bit 5 (L): lossless — bit 4 (A) is alpha; ALPH-prefix repair sets alpha. */
    private const VP8X_FLAG_ALPHA = 0x10;

    /**
     * The (width, height) declared by a VP8 lossy bitstream, or null when the
     * 3-byte start code 0x9D 0x01 0x2A is absent (in which case the caller
     * stops guessing).
     *
     * @return ?array{0:int,1:int}
     */
    private static function lossyDimensions(string $bitstream): ?array
    {
        if (strlen($bitstream) < 10 || substr($bitstream, 3, 3) !== "\x9d\x01\x2a") {
            return null;
        }

        $w = unpack('v', substr($bitstream, 6, 2))[1] & 0x3FFF;
        $h = unpack('v', substr($bitstream, 8, 2))[1] & 0x3FFF;

        return $w > 0 && $h > 0 ? [$w, $h] : null;
    }

    /** @return int 24-bit little-endian field at $offset */
    private static function le24(string $bytes, int $offset): int
    {
        return ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8) | (ord($bytes[$offset + 2]) << 16);
    }

    // -------------------------------------------------------------------------
    // Compositing
    // -------------------------------------------------------------------------

    /**
     * Decode + composite every frame, snapshotting the canvas after each
     * (the snapshot IS the frame the viewer displays).
     *
     * @param list<array{x:int,y:int,duration:int,noBlend:bool,dispose:bool,still:string}> $frames
     */
    private function compositeAll(array $frames, int $canvasW, int $canvasH): void
    {
        $canvas = imagecreatetruecolor($canvasW, $canvasH);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $canvasW - 1, $canvasH - 1, $transparent);

        $previous = null;
        foreach ($frames as $frame) {
            // Manual per-pixel compositing below (and the raw dispose fill)
            // require GD's own blending OFF, or imagesetpixel would
            // double-composite the colors we just computed by hand.
            imagealphablending($canvas, false);

            if ($previous !== null && $previous['dispose']) {
                // Disposal 1: the PREVIOUS frame's rectangle reverts to the
                // background before this frame draws.
                imagefilledrectangle(
                    $canvas,
                    $previous['x'],
                    $previous['y'],
                    $previous['x'] + $previous['w'] - 1,
                    $previous['y'] + $previous['h'] - 1,
                    $transparent,
                );
            }

            $sub = imagecreatefromstring($frame['still']);
            if ($sub === false) {
                throw new \RuntimeException(sprintf(
                    'animated WebP frame %d bitstream failed to decode',
                    count($this->canvases),
                ));
            }

            self::drawOnto($canvas, $sub, $frame['x'], $frame['y'], $frame['noBlend']);
            // The dispose rect is the frame's ACTUAL painted box — the
            // bitstream's dimensions, not the lossy container field.
            $previous = ['x' => $frame['x'], 'y' => $frame['y'], 'w' => imagesx($sub), 'h' => imagesy($sub), 'dispose' => $frame['dispose']];
            imagedestroy($sub);

            $this->delaysMs[] = $frame['duration'];
            $this->canvases[] = self::snapshot($canvas);
        }

        imagedestroy($canvas);
    }

    /**
     * Copy $sub onto $canvas at (x,y), honoring the WebP blend bit.
     *
     * GD's imagecopy never per-pixel-blends source alpha, so both modes are
     * done explicitly: "no blend" copies color AND alpha raw; "alpha blend"
     * composites src-over against the canvas pixel. Clipped to both rects.
     */
    private static function drawOnto(\GdImage $canvas, \GdImage $sub, int $x, int $y, bool $noBlend): void
    {
        $cw = imagesx($canvas);
        $ch = imagesy($canvas);
        $sw = imagesx($sub);
        $sh = imagesy($sub);

        for ($py = 0; $py < $sh; $py++) {
            $ty = $y + $py;
            if ($ty < 0 || $ty >= $ch) {
                continue;
            }
            for ($px = 0; $px < $sw; $px++) {
                $tx = $x + $px;
                if ($tx < 0 || $tx >= $cw) {
                    continue;
                }
                $src = imagecolorat($sub, $px, $py);
                $srcA = ($src >> 24) & 0x7F; // GD alpha 0..127, 127 = transparent

                if ($noBlend) {
                    imagesetpixel($canvas, $tx, $ty, $src);
                    continue;
                }

                $dst = imagecolorat($canvas, $tx, $ty);
                $dstA = ($dst >> 24) & 0x7F;
                $aS = (127 - $srcA) / 127;
                $aD = (127 - $dstA) / 127;
                $outA = $aS + $aD * (1 - $aS);

                $lerp = static fn (int $shift): int => $outA <= 0.0
                    ? 0
                    : (int) round(((($src >> $shift) & 0xFF) * $aS * 1.0 + ((($dst >> $shift) & 0xFF) * $aD * (1 - $aS))) / $outA);

                imagesetpixel(
                    $canvas,
                    $tx,
                    $ty,
                    imagecolorallocatealpha($canvas, $lerp(16), $lerp(8), $lerp(0), (int) round((1 - $outA) * 127)),
                );
            }
        }
    }

    private static function snapshot(\GdImage $canvas): \GdImage
    {
        $copy = imagecreatetruecolor(imagesx($canvas), imagesy($canvas));
        imagesavealpha($copy, true);
        imagealphablending($copy, false);
        imagecopy($copy, $canvas, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas));

        return $copy;
    }

    // -------------------------------------------------------------------------
    // Output geometry
    // -------------------------------------------------------------------------

    /**
     * The exact pixel box every frame is rescaled into: graphics modes use
     * the terminal's full cell-pixel box, text modes the cell grid scaled by
     * the mode's pixels-per-cell packing (same math as FfmpegDecoder::open).
     *
     * @return array{0:int,1:int}
     */
    private static function outputBox(int $cellsW, int $cellsH, ?Mode $mode, int $cellPxW, int $cellPxH): array
    {
        if ($mode?->isGraphics() ?? false) {
            return [max(1, $cellsW * $cellPxW), max(1, $cellsH * $cellPxH)];
        }

        return [
            max(1, $cellsW * ($mode?->colsPerCell() ?? 1)),
            max(1, $cellsH * ($mode?->rowsPerCell() ?? 2)),
        ];
    }

    /**
     * Fit the canvas into the output box preserving aspect, centered on the
     * box background — the PHP equivalent of ffmpeg's
     * force_original_aspect_ratio=decrease + pad. Graphics boxes keep alpha
     * (letterbox bars transparent); text boxes are opaque black. Memoized per
     * source canvas so a repeated play-through does no second resample.
     */
    private function boxFor(\GdImage $canvas): \GdImage
    {
        $id = spl_object_id($canvas);
        if (isset($this->boxes[$id])) {
            return $this->boxes[$id];
        }

        $cw = imagesx($canvas);
        $ch = imagesy($canvas);
        $fit = min($this->frameW / $cw, $this->frameH / $ch);
        $fitW = max(1, (int) floor($cw * $fit));
        $fitH = max(1, (int) floor($ch * $fit));
        $scaled = imagescale($canvas, $fitW, $fitH);
        if ($scaled === false) {
            throw new \RuntimeException('animated WebP frame rescale failed');
        }

        $box = imagecreatetruecolor($this->frameW, $this->frameH);
        imagesavealpha($box, $this->graphics);
        if ($this->graphics) {
            imagealphablending($box, false);
            $bg = imagecolorallocatealpha($box, 0, 0, 0, 127);
        } else {
            imagealphablending($box, false);
            $bg = imagecolorallocate($box, 0, 0, 0);
        }
        imagefilledrectangle($box, 0, 0, $this->frameW - 1, $this->frameH - 1, $bg);
        imagealphablending($box, true);
        imagecopy($box, $scaled, intdiv($this->frameW - $fitW, 2), intdiv($this->frameH - $fitH, 2), 0, 0, $fitW, $fitH);
        imagedestroy($scaled);

        return $this->boxes[$id] = $box;
    }

    /** Flatten a box to raw rgb24 (alpha composited over the box background, which is black here). */
    private static function rgb24Of(\GdImage $box): string
    {
        $w = imagesx($box);
        $h = imagesy($box);
        $out = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($box, $x, $y);
                $a = ($c >> 24) & 0x7F; // GD 7-bit alpha (127 = transparent); bits 23..16 hold red
                $alpha = (127 - $a) / 127;
                $out .= chr((int) round((($c >> 16) & 0xFF) * $alpha))
                     . chr((int) round((($c >> 8) & 0xFF) * $alpha))
                     . chr((int) round(($c & 0xFF) * $alpha));
            }
        }

        return $out;
    }

    /**
     * Encode a box (alpha preserved) to PNG bytes for the graphics
     * protocols. imagepng with no filename writes into the output buffer —
     * buffered here so nothing can interleave.
     */
    private static function pngOf(\GdImage $box): string
    {
        ob_start();
        $ok = imagepng($box);
        $png = ob_get_clean();

        if ($ok === false || $png === false) {
            throw new \RuntimeException('animated WebP PNG encode failed');
        }

        return $png;
    }
}

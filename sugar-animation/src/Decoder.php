<?php

declare(strict_types=1);

namespace SugarCraft\Animation;

/**
 * Decode a GIF — from disk via {@see self::decode()} or in-memory via
 * {@see self::decodeBytes()} (E-07) — into {@see Frame}s, using ext-gd's
 * `imagecreatefromstring()` for in-memory single-frame extraction and
 * a hand-rolled GIF89a parser for per-frame timing.
 *
 * Parses the GIF Logical Screen Descriptor and Global Color Table (GCT)
 * from the header, then walks the frame stream to:
 *   1. Find each frame's Graphic Control Extension (GCE) delay, disposal,
 *      and transparent-index values.
 *   2. Extract the per-frame local color table when present.
 *   3. Build a single-frame GIF in memory compatible with
 *      `imagecreatefromstring()`.
 *   4. Downsample each frame using the area-average method.
 *
 * Disposal methods are tracked per-frame and passed to {@see Frame}
 * so callers can render correctly when animating.
 */
final class Decoder
{
    /**
     * Maximum allowed cell-grid product (cellsW * cellsH) to prevent
     * excessive memory allocation from untrusted input.
     *
     * Public so a caller can SIZE ITS REQUEST rather than discover the bound
     * by catching the exception: {@see sampleCanvas()} area-averages in PHP,
     * one `imagecolorat()` per source pixel per cell, so the grid is both the
     * memory and the time budget (MEASURED: a 6-frame GIF at 316×316 ≈ 100k
     * cells costs ~1.1s and ~288MB). sugar-video's graphics modes ask in
     * PIXELS, which for an ordinary 80×24 terminal is ~384k — six times over
     * this line, and a hard failure rather than a slow success.
     */
    public const MAX_CELLS = 100_000;

    /**
     * Maximum GIF logical-screen area in pixels (header width * height).
     *
     * {@see self::MAX_CELLS} bounds only the OUTPUT grid; the compositing canvas
     * and the DISPOSAL_PREVIOUS snapshot are allocated truecolor at the SOURCE
     * screen size, and GD decodes each frame before it is downsampled — so a
     * crafted 65535x65535 header would ask for ~17 GB before any cell cap ever
     * ran. 2,000,000 px (~1414x1414) keeps each canvas at ~8 MB and stays far
     * above any GIF worth playing in a terminal.
     */
    public const MAX_TOTAL_PIXELS = 2_000_000;

    /**
     * Maximum frames per animation so a pathological file cannot OOM the
     * walk or the composited list. The README documents this line as 256.
     */
    public const MAX_FRAMES = 256;

    /**
     * Decode a GIF file on disk into a list of {@see Frame}s.
     *
     * Path ingress is the legacy shape (E-07): the file is read once here and
     * every frame then arrives through {@see self::decodeBytes()}, so this
     * method and its bytes sibling share exactly one walk and the parity
     * between them is pinned by DecodeBytesParityTest. Sibling libraries that
     * already hold validated bytes (sugar-image's bounded GIF ingress) must
     * call {@see self::decodeBytes()} directly — staging a temp copy of the
     * bytes to satisfy this signature is the TOCTOU hazard class E-07 removes.
     *
     * @return list<Frame>
     */
    public static function decode(string $path, int $cellsW, int $cellsH): array
    {
        if (is_file($path) === false) {
            throw new \RuntimeException(Lang::t('decoder.no_file', ['path' => $path]));
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException(Lang::t('decoder.not_gif'));
        }

        return iterator_to_array(self::decodeBytes($bytes, $cellsW, $cellsH), preserve_keys: false);
    }

    /**
     * Decode in-memory GIF bytes into a stream of {@see Frame}s (E-07).
     *
     * The bytes-ingress twin of {@see self::decode()} — no filesystem is
     * touched, so a caller that read the container through its own bounded,
     * single-descriptor ingress (sugar-image's `readBoundedRegularFile`) can
     * hand the very bytes it audited straight in. That closes the cross-open
     * TOCTOU class at the root instead of papering over it with a private
     * temp copy: there is no second open of any attacker-reachable path.
     *
     * Yields lazily: each frame is composited, sampled and emitted before the
     * next is touched, so a consumer that stops early never pays for the rest
     * of the animation (chicle's per-frame `decode_next_frame` shape).
     *
     * @param bool $withCanvasRgba When true, each frame additionally carries
     *                             the pre-downsample composited canvas as
     *                             packed RGBA bytes ({@see Frame::rgbaBytes()})
     *                             — the representation-uniform frame chicle
     *                             loaders emit. Off by default: plain terminal
     *                             playback reads only `$cells`, and holding
     *                             N × W × H × 4 resident is the E-12 memory
     *                             model a player should not pay for.
     *
     * @return \Generator<int, Frame>
     */
    public static function decodeBytes(string $bytes, int $cellsW, int $cellsH, bool $withCanvasRgba = false): \Generator
    {
        if (extension_loaded('gd') === false) {
            throw new \RuntimeException(Lang::t('decoder.no_gd'));
        }
        // 13 = signature (6) + Logical Screen Descriptor (7). Anything shorter
        // has no dimensions to speak of — it is not a GIF, and parseHeader()
        // may read its first ten bytes unguarded only because of this gate.
        if (strlen($bytes) < 13
            || (substr($bytes, 0, 6) !== 'GIF87a' && substr($bytes, 0, 6) !== 'GIF89a')) {
            throw new \RuntimeException(Lang::t('decoder.not_gif'));
        }
        if ($cellsW <= 0 || $cellsH <= 0) {
            throw new \RuntimeException(Lang::t('decoder.grid_too_small'));
        }
        if ($cellsW * $cellsH > self::MAX_CELLS) {
            throw new \RuntimeException(Lang::t('decoder.grid_too_large', ['max' => (string) self::MAX_CELLS]));
        }
        $header = self::parseHeader($bytes);

        // Cap the SOURCE canvas as well as the output grid: the compositing
        // canvas and the DISPOSAL_PREVIOUS snapshot are sized from the header's
        // screen dimensions, so the pixel line must fire before them.  Per-frame
        // Image Descriptors do NOT ride on GD's goodwill either: parseHeader()
        // clips every declared rect to the logical screen (fully off-canvas
        // frames are dropped) and assembleFrameGif() re-emits the descriptor it
        // feeds GD with the clipped rect — so the screen gate below bounds every
        // allocation in the decode, whatever a sloppy or crafted file declares
        // per frame (E-01).
        if ($header['width'] * $header['height'] > self::MAX_TOTAL_PIXELS) {
            throw new \RuntimeException(Lang::t('decoder.screen_too_large', ['max' => (string) self::MAX_TOTAL_PIXELS]));
        }

        $screenW = $header['width'];
        $screenH = $header['height'];

        // A zero-dimension logical screen (ord-parsed header bytes make 0 the
        // only impossible-but-craftable value) cannot host a canvas:
        // imagecreatetruecolor() in the walk — and the static fallback renderer —
        // would raise a raw ValueError, breaking the RuntimeException
        // contract this method documents. Reject at the door.
        if ($screenW <= 0 || $screenH <= 0) {
            throw new \RuntimeException(Lang::t('decoder.screen_too_small'));
        }

        // Every gate above runs EAGERLY — at call time, not at first
        // iteration: a generator body would otherwise defer fail-loud input
        // rejection to whichever consumer happens to pull the first frame,
        // and a discarded (never-started) generator would discard the error
        // too. The walk itself stays lazy behind walkBytes().
        return self::walkBytes($bytes, $header, $cellsW, $cellsH, $withCanvasRgba);
    }

    /**
     * The compositing/sampling frame stream behind {@see self::decodeBytes()},
     * entered only after every input gate has passed.
     *
     * @param array{width: int, height: int, hasGct: bool, gctBytes: int, frameInfos: list<array<string, int|bool>>} $header
     *
     * @return \Generator<int, Frame>
     */
    private static function walkBytes(string $bytes, array $header, int $cellsW, int $cellsH, bool $withCanvasRgba): \Generator
    {
        $screenW = $header['width'];
        $screenH = $header['height'];

        // Static fallback — when no Image Descriptors were found.
        if ($header['frameInfos'] === []) {
            $info = [
                'offset' => 0,
                'delay' => 10,
                'disposal' => Frame::DISPOSAL_NONE,
                'transparent' => false,
                'transparentIndex' => -1,
                'hasLct' => false,
                'lctBytes' => 0,
                'left' => 0,
                'top' => 0,
                'frameW' => $screenW,
                'frameH' => $screenH,
            ];
            $frame = self::renderSingleFrame($bytes, $info, $header, $cellsW, $cellsH, $withCanvasRgba);
            if ($frame !== null) {
                yield $frame;
            }
            return;
        }

        // Create a truecolor canvas at logical screen size for compositing.
        $canvas = imagecreatetruecolor($screenW, $screenH);
        imagesavealpha($canvas, true);
        $transparentBg = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparentBg);

        // Snapshot for DISPOSAL_PREVIOUS.
        $snapshot = null;

        $prevInfo = null;

        try {
            foreach ($header['frameInfos'] as $info) {
                // Before painting frame N, apply frame N-1's disposal.
                if ($prevInfo !== null) {
                    $prevDisposal = $prevInfo['disposal'];
                    if ($prevDisposal === Frame::DISPOSAL_BACKGROUND) {
                        // Clear the previous frame's rect to transparent.
                        $prevLeft = $prevInfo['left'];
                        $prevTop = $prevInfo['top'];
                        $prevW = $prevInfo['frameW'];
                        $prevH = $prevInfo['frameH'];
                        imagefilledrectangle($canvas, $prevLeft, $prevTop, $prevLeft + $prevW - 1, $prevTop + $prevH - 1, $transparentBg);
                    } elseif ($prevDisposal === Frame::DISPOSAL_PREVIOUS && $snapshot !== null) {
                        // Restore to the snapshot taken before the previous frame.
                        imagecopy($canvas, $snapshot, 0, 0, 0, 0, $screenW, $screenH);
                    }
                }

                // Snapshot before painting this frame (for DISPOSAL_PREVIOUS of next frame).
                $snapshot = imagecreatetruecolor($screenW, $screenH);
                imagesavealpha($snapshot, true);
                imagefill($snapshot, 0, 0, $transparentBg);
                imagecopy($snapshot, $canvas, 0, 0, 0, 0, $screenW, $screenH);

                // Decode this frame's LZW data to a GD image.
                $frameImg = self::decodeFrameImage($bytes, $info, $header);
                if ($frameImg === null) {
                    $prevInfo = $info;
                    continue;
                }

                // Composite the frame onto the canvas at (left, top), honoring transparency.
                $left = $info['left'];
                $top = $info['top'];
                $frameImgW = imagesx($frameImg);
                $frameImgH = imagesy($frameImg);

                // Copy non-transparent pixels from frame to canvas.
                $srcTransparentIndex = imagecolortransparent($frameImg);
                for ($y = 0; $y < $frameImgH; $y++) {
                    for ($x = 0; $x < $frameImgW; $x++) {
                        $pixelIndex = imagecolorat($frameImg, $x, $y);
                        if ($srcTransparentIndex >= 0 && $pixelIndex === $srcTransparentIndex) {
                            continue; // Transparent pixel — leave canvas unchanged.
                        }
                        $rgb = imagecolorsforindex($frameImg, $pixelIndex);
                        imagesetpixel($canvas, $left + $x, $top + $y, imagecolorallocate($canvas, $rgb['red'], $rgb['green'], $rgb['blue']));
                    }
                }
                imagedestroy($frameImg);

                // Grab the composited canvas BEFORE downsampling (E-07): the
                // lossless pre-cell representation, chicle-style.
                $rgba = $withCanvasRgba ? self::grabCanvasRgba($canvas, $screenW, $screenH) : null;

                // Downsample the composited canvas to produce this frame.
                yield self::sampleCanvas($canvas, $cellsW, $cellsH, $info['delay'], $info['disposal'], $info['transparent'], $rgba, $screenW, $screenH);

                $prevInfo = $info;
            }
        } finally {
            // Runs on normal completion AND on abandonment: a generator that
            // is never fully consumed must not leak its canvases — GD frees
            // them with the generator's destruct, and the explicit destroy
            // keeps the resident peak per-yield bounded on long walks.
            imagedestroy($canvas);
            if ($snapshot !== null) {
                imagedestroy($snapshot);
            }
        }
    }

    /**
     * Pack a truecolor compositing canvas into RGBA bytes (row-major,
     * width*height*4). GD alpha 0 (opaque) maps to alpha byte 255 and
     * alpha 127 (fully transparent) to 0, linearly in between; the GIF
     * canvas only ever holds the two extremes, but the linear map keeps a
     * semi-transparent pixel honest if one is ever composited.
     */
    private static function grabCanvasRgba(\GdImage $canvas, int $screenW, int $screenH): string
    {
        $out = '';
        for ($y = 0; $y < $screenH; $y++) {
            for ($x = 0; $x < $screenW; $x++) {
                $pixel = imagecolorat($canvas, $x, $y);
                $a = ($pixel >> 24) & 0x7F;
                $out .= chr(($pixel >> 16) & 0xFF)
                    . chr(($pixel >> 8) & 0xFF)
                    . chr($pixel & 0xFF)
                    . chr(intdiv((127 - $a) * 255, 127));
            }
        }

        return $out;
    }

    /**
     * Pack a palette frame image into RGBA bytes — the static-fallback twin
     * of {@see self::grabCanvasRgba()}: the transparent index becomes
     * (0,0,0,0), every other pixel is its palette colour at alpha 255.
     */
    private static function grabPaletteRgba(\GdImage $img): string
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $transparentIndex = imagecolortransparent($img);
        $out = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $index = imagecolorat($img, $x, $y);
                if ($transparentIndex >= 0 && $index === $transparentIndex) {
                    $out .= "\x00\x00\x00\x00";
                    continue;
                }
                $rgb = imagecolorsforindex($img, $index);
                $out .= chr($rgb['red']) . chr($rgb['green']) . chr($rgb['blue']) . "\xff";
            }
        }

        return $out;
    }

    /**
     * Decode the LZW image data for a single frame into a GD image.
     * Returns a palette or truecolor GD image at the frame's declared dimensions.
     */
    private static function decodeFrameImage(string $bytes, array $info, array $header): ?\GdImage
    {
        $img = @imagecreatefromstring(self::assembleFrameGif($bytes, $info, $header));
        return $img === false ? null : $img;
    }

    /**
     * Rebuild a standalone single-frame GIF for one Image Descriptor:
     * header slice + GCT + rewritten GCE + descriptor + optional LCT +
     * LZW sub-blocks + trailer — the payload `imagecreatefromstring()` eats.
     *
     * One assembly line for both decode paths (the per-frame decode that
     * feeds the compositing canvas, and the static fallback for
     * descriptor-less files), which previously carried byte-identical
     * copies of this forty-line block.
     *
     * @param array{offset: int, delay: int, disposal: int, transparent: bool, transparentIndex: int, hasLct: bool, lctBytes: int, left: int, top: int, frameW: int, frameH: int} $info
     * @param array{hasGct: bool, gctBytes: int} $header
     */
    private static function assembleFrameGif(string $bytes, array $info, array $header): string
    {
        $offset = $info['offset'];
        $delay = $info['delay'];
        $disposal = $info['disposal'];
        $transparent = $info['transparent'];
        $transparentIndex = $info['transparentIndex'];
        $hasLct = $info['hasLct'];
        $lctBytes = $info['lctBytes'];

        $gifData = '';
        // Header slice (first 13 bytes).
        $gifData .= substr($bytes, 0, 13);
        // Global color table, when the Logical Screen Descriptor declares one.
        if ($header['hasGct']) {
            $gifData .= substr($bytes, 13, $header['gctBytes']);
        }
        // GCE block carrying this frame's parsed timing, disposal and index.
        $delayLo = $delay & 0xFF;
        $delayHi = ($delay >> 8) & 0xFF;
        $disposalByte = ($disposal & 0x07) << 2;
        $transparentByte = $transparent ? 0x01 : 0x00;
        $gifData .= "\x21\xF9\x04"
            . chr($disposalByte | $transparentByte)
            . chr($delayLo) . chr($delayHi)
            . chr($transparent && $transparentIndex >= 0 ? $transparentIndex : 0)
            . "\x00";
        // The Image Descriptor (10 bytes) comes first: its packed byte is what
        // tells the reader an LCT follows, so writing the LCT ahead of it puts
        // palette bytes where GD expects a block introducer: depending on
        // those bytes GD either rejects the frame or decodes it with garbage
        // colours (GIF89a §20-21: descriptor, then LCT, then data).
        if ($offset > 0 && ord($bytes[$offset]) === 0x2C) {
            // E-01: re-emit the descriptor with the CLIPPED rect parseHeader()
            // stored, not the declared bytes — copying them through is how a
            // 65535x65535 frame inside a 4x4 screen reached GD at its declared
            // size.  Unclipped frames rewrite byte-identically to the raw copy
            // (same left/top/w/h, and the packed byte — LCT and interlace
            // flags — is carried verbatim).  A frame whose LZW stride is wider
            // than the clipped rect shears: rows decode against the declared
            // width but fill the narrower canvas.  Real out-of-canvas frames
            // are encoder sloppiness, pixel-fidelity there would need a full
            // LZW re-encode, and provable allocation bounds win that trade.
            $gifData .= "\x2C" . pack('v', $info['left']) . pack('v', $info['top'])
                . pack('v', $info['frameW']) . pack('v', $info['frameH'])
                . $bytes[$offset + 9];
        } else {
            // The static-fallback pseudo-frame (offset 0, screen-sized) has no
            // descriptor of its own to rewrite; its raw head slice behaves as
            // it always has (GD rejects it, the frame list lands empty).
            $gifData .= substr($bytes, $offset, 10);
        }
        if ($hasLct) {
            $gifData .= substr($bytes, $offset + 10, $lctBytes);
        }
        // LZW image data: minimum-code-size byte + sub-blocks + 0x00 terminator.
        $lzwStart = $offset + 10 + ($hasLct ? $lctBytes : 0);
        $imgDataEnd = self::findImageDataEnd($bytes, $lzwStart);
        $gifData .= substr($bytes, $lzwStart, $imgDataEnd - $lzwStart + 1);
        // GIF trailer.
        $gifData .= "\x3B";

        return $gifData;
    }

    /**
     * Downsample a GD image (the composited canvas) to a cell grid.
     *
     * The optional `$rgba` rides through to {@see Frame} untouched (E-07):
     * it was grabbed from the same canvas before this sampling pass, so the
     * frame carries both the lossy cell view and the packed pixel view.
     */
    private static function sampleCanvas(\GdImage $canvas, int $cellsW, int $cellsH, int $delay, int $disposal, bool $transparent, ?string $rgba = null, int $rgbaWidth = 0, int $rgbaHeight = 0): Frame
    {
        $w = imagesx($canvas);
        $h = imagesy($canvas);

        $rows = [];
        for ($cy = 0; $cy < $cellsH; $cy++) {
            $row = [];
            for ($cx = 0; $cx < $cellsW; $cx++) {
                $x0 = (int) ($cx * $w / $cellsW);
                $x1 = (int) (($cx + 1) * $w / $cellsW) - 1;
                $y0 = (int) ($cy * $h / $cellsH);
                $y1 = (int) (($cy + 1) * $h / $cellsH) - 1;
                $x1 = max($x0, $x1);
                $y1 = max($y0, $y1);

                $sumR = 0;
                $sumG = 0;
                $sumB = 0;
                $count = 0;
                for ($sy = $y0; $sy <= $y1; $sy++) {
                    for ($sx = $x0; $sx <= $x1; $sx++) {
                        $pixel = imagecolorat($canvas, $sx, $sy);
                        // For truecolor with alpha, the pixel is packed ARGB.
                        // Alpha=0 means fully opaque, alpha=127 (0x7F) means fully transparent.
                        // In PHP's GD, imagesavealpha preserves the full 8-bit alpha.
                        $a = ($pixel >> 24) & 0xFF;
                        if ($a !== 0) {
                            continue; // Transparent or semi-transparent — skip in average.
                        }
                        $r = ($pixel >> 16) & 0xFF;
                        $g = ($pixel >> 8) & 0xFF;
                        $b = $pixel & 0xFF;
                        $sumR += $r;
                        $sumG += $g;
                        $sumB += $b;
                        $count++;
                    }
                }
                if ($count > 0) {
                    $row[] = [
                        (int) round($sumR / $count),
                        (int) round($sumG / $count),
                        (int) round($sumB / $count),
                    ];
                } else {
                    // No opaque pixel was sampled — the cell shows the
                    // terminal background.
                    $row[] = null;
                }
            }
            $rows[] = $row;
        }
        return new Frame($rows, $delay, $disposal, $transparent, $rgba, $rgbaWidth, $rgbaHeight);
    }

    /**
     * Parse the GIF header: logical screen dimensions, GCT flag + size,
     * and per-frame info (GCE delay + image descriptor offset + disposal
     * + transparency + image position/size, the rect clipped to the
     * screen — frames entirely outside it are dropped; see E-01 below).
     *
     * @throws \RuntimeException when the declared GCT or an Image Descriptor
     *                           reaches past EOF (`decoder.truncated`). A
     *                           truncated GCE or sub-block tail stays a
     *                           graceful walk-end, pinned by DecoderTest.
     * @return array{
     *   width: int,
     *   height: int,
     *   hasGct: bool,
     *   gctBytes: int,
     *   frameInfos: list<array{offset: int, delay: int, disposal: int, transparent: bool, transparentIndex: int, hasLct: bool, lctBytes: int, left: int, top: int, frameW: int, frameH: int}>
     * }
     */
    private static function parseHeader(string $bytes): array
    {
        $len = strlen($bytes); // ≥ 13: guaranteed by decode()'s header gate.
        $width  = ord($bytes[6]) | (ord($bytes[7]) << 8);
        $height = ord($bytes[8]) | (ord($bytes[9]) << 8);
        $packed = ord($bytes[10]);
        $hasGct = (bool) ($packed & 0x80);
        $gctSizeExp = $packed & 0x07;
        $gctEntryCount = $hasGct ? (1 << ($gctSizeExp + 1)) : 0;
        $gctBytes = $gctEntryCount * 3;

        // A declared GCT reaching past EOF is not a walkable stream: every
        // frame's palette would resolve against bytes that do not exist.
        if ($hasGct && 13 + $gctBytes > $len) {
            throw new \RuntimeException(Lang::t('decoder.truncated'));
        }

        $frameInfos = [];
        $lastDelay = 10; // Default 100ms (10 centiseconds) per GIF spec.
        $lastDisposal = Frame::DISPOSAL_NONE;
        $lastTransparent = false;
        $lastTransparentIndex = -1;

        // Walk the GIF byte stream block-by-block.
        $i = 13 + $gctBytes;
        while ($i < $len) {
            $blockType = ord($bytes[$i] ?? '');
            if ($blockType === 0x3B) {
                // GIF trailer — done.
                break;
            }
            if ($blockType === 0x21) {
                // Extension block. Check label at $i+1, skip block content, handle GCE delay.
                $label = ord($bytes[$i + 1] ?? '');
                if ($label === 0xF9) {
                    // GCE: 0x21 0xF9 0x04 <packed> <delayL> <delayH> <transparent> 0x00
                    $gcePacked = ord($bytes[$i + 3] ?? '');
                    $disposal = ($gcePacked >> 2) & 0x07;
                    $transparent = (bool) ($gcePacked & 0x01);
                    $transparentIndex = ord($bytes[$i + 6] ?? '');
                    // Guard every byte read uniformly: a GIF truncated mid-GCE
                    // must degrade gracefully, not emit "Uninitialized string
                    // offset" warnings (which fail the suite under failOnWarning).
                    $delay = ord($bytes[$i + 4] ?? '') | (ord($bytes[$i + 5] ?? '') << 8);
                    if ($delay > 0) {
                        $lastDelay = $delay;
                    }
                    $lastDisposal = $disposal;
                    $lastTransparent = $transparent;
                    $lastTransparentIndex = $transparentIndex;
                    $i += 8; // Fixed 8-byte GCE: skip to next block.
                } else {
                    // Skip extension block: read sub-block length bytes until 0x00 terminator.
                    $j = $i + 2;
                    while ($j < $len) {
                        $subLen = ord($bytes[$j]);
                        $j++;
                        if ($subLen === 0) {
                            break; // Block terminator.
                        }
                        if ($j + $subLen > $len) {
                            break; // Would overrun — treat truncated tail as end-of-data.
                        }
                        $j += $subLen; // Skip the sub-block data.
                    }
                    $i = $j;
                }
                continue;
            }
            if ($blockType === 0x2C) {
                // An Image Descriptor cut short by EOF cannot be parsed at
                // all — fail loud with a typed error instead of reading nine
                // bytes off the end of the string (PHP warnings, and under
                // failOnWarning a suite that cannot tell corruption from
                // coincidence).
                if ($i + 10 > $len) {
                    throw new \RuntimeException(Lang::t('decoder.truncated'));
                }
                // Image Descriptor — record its offset and the last-seen GCE values.
                $left   = ord($bytes[$i + 1]) | (ord($bytes[$i + 2]) << 8);
                $top    = ord($bytes[$i + 3]) | (ord($bytes[$i + 4]) << 8);
                $frameW = ord($bytes[$i + 5]) | (ord($bytes[$i + 6]) << 8);
                $frameH = ord($bytes[$i + 7]) | (ord($bytes[$i + 8]) << 8);
                $descPacked = ord($bytes[$i + 9]);
                $hasLct = (bool) ($descPacked & 0x80);
                $lctSizeExp = $descPacked & 0x07;
                $lctEntryCount = $hasLct ? (1 << ($lctSizeExp + 1)) : 0;
                $lctBytes = $lctEntryCount * 3;

                // E-01: a frame may not pretend to be bigger than the canvas it
                // composites onto.  Clip the declared rect to the logical screen
                // exactly as browsers do: the visible part of a partly-off-canvas
                // frame is stored clipped, a frame entirely outside the screen is
                // dropped from the list (its sub-blocks are still consumed below,
                // so the byte walk stays in sync).  Zero-extent declarations clip
                // to nothing and drop the same way.  The clipped dims are what
                // assembleFrameGif() re-emits, which is what makes every GD
                // allocation provably bounded by the MAX_TOTAL_PIXELS header gate
                // instead of merely bounded in practice by GD's own silent clamp.
                $visibleW = min($frameW, $width - $left);
                $visibleH = min($frameH, $height - $top);
                if ($visibleW > 0 && $visibleH > 0) {
                    $frameInfos[] = [
                        'offset' => $i,
                        'delay' => $lastDelay,
                        'disposal' => $lastDisposal,
                        'transparent' => $lastTransparent,
                        'transparentIndex' => $lastTransparentIndex,
                        'hasLct' => $hasLct,
                        'lctBytes' => $lctBytes,
                        'left' => $left,
                        'top' => $top,
                        'frameW' => $visibleW,
                        'frameH' => $visibleH,
                    ];
                }
                // Skip image data: the one-byte LZW minimum code size (and the
                // Local Color Table when the descriptor declared one), then the
                // length-prefixed sub-blocks until the block terminator (0x00).
                // Starting at +10 read the min-code byte as a block length and
                // desynchronised the walk on some frame counts (MAX_FRAMES pin
                // test caught it at n=8).
                $j = $i + 11 + $lctBytes;
                while ($j < $len) {
                    $subLen = ord($bytes[$j]);
                    $j++;
                    if ($subLen === 0) {
                        break; // Sub-block terminator.
                    }
                    if ($j + $subLen > $len) {
                        break; // Would overrun — treat truncated tail as end-of-data.
                    }
                    $j += $subLen; // Skip the sub-block data.
                }
                $i = $j;
                continue;
            }
            // Unexpected byte — step forward cautiously.
            $i++;
        }
        // Cap pathological GIFs at MAX_FRAMES so the decoder doesn't OOM.
        return [
            'width' => $width,
            'height' => $height,
            'hasGct' => $hasGct,
            'gctBytes' => $gctBytes,
            'frameInfos' => array_slice($frameInfos, 0, self::MAX_FRAMES),
        ];
    }

    /**
     * Build a single-frame GIF payload in memory and render it with
     * `imagecreatefromstring()` — no temp files needed.
     *
     * Passes transparent pixels through as null so downsampling can
     * skip them in area-average mode.
     */
    private static function renderSingleFrame(
        string $bytes,
        array $info,
        array $header,
        int $cellsW,
        int $cellsH,
        bool $withCanvasRgba = false,
    ): ?Frame {
        // Build a minimal single-frame GIF in memory:
        //   GIF header (13 bytes) + effective color table + one GCE block
        //   + one Image Descriptor + image data + trailer.
        $img = @imagecreatefromstring(self::assembleFrameGif($bytes, $info, $header));
        if ($img === false) {
            return null;
        }
        // sample() destroys $img — grab the pixels first (E-07).
        $rgba = $withCanvasRgba ? self::grabPaletteRgba($img) : null;
        $rgbaWidth = $withCanvasRgba ? imagesx($img) : 0;
        $rgbaHeight = $withCanvasRgba ? imagesy($img) : 0;
        // sample() destroys $img.
        return self::sample(
            $img,
            $cellsW,
            $cellsH,
            $info['delay'],
            $info['disposal'],
            $info['transparent'],
            $info['transparentIndex'],
            $rgba,
            $rgbaWidth,
            $rgbaHeight,
        );
    }

    /**
     * Area-average downsampling with transparent-pixel awareness.
     */
    private static function sample(
        \GdImage $img,
        int $cellsW,
        int $cellsH,
        int $delay,
        int $disposal,
        bool $transparent,
        int $transparentIndex,
        ?string $rgba = null,
        int $rgbaWidth = 0,
        int $rgbaHeight = 0,
    ): Frame {
        $w = imagesx($img);
        $h = imagesy($img);

        // If the frame has transparency, allocate the transparent color index
        // so we can test individual pixels for transparency.
        $transparentColor = null;
        if ($transparent && $transparentIndex >= 0) {
            $transparentColor = imagecolortransparent($img);
        }

        $rows = [];
        for ($cy = 0; $cy < $cellsH; $cy++) {
            $row = [];
            for ($cx = 0; $cx < $cellsW; $cx++) {
                $x0 = (int) ($cx * $w / $cellsW);
                $x1 = (int) (($cx + 1) * $w / $cellsW) - 1;
                $y0 = (int) ($cy * $h / $cellsH);
                $y1 = (int) (($cy + 1) * $h / $cellsH) - 1;
                $x1 = max($x0, $x1);
                $y1 = max($y0, $y1);

                $sumR = 0;
                $sumG = 0;
                $sumB = 0;
                $count = 0;
                for ($sy = $y0; $sy <= $y1; $sy++) {
                    for ($sx = $x0; $sx <= $x1; $sx++) {
                        // GIFs decode to a PALETTE image, so imagecolorat()
                        // returns the palette INDEX, not a packed RGB value —
                        // it must be resolved through the color table.
                        $index = imagecolorat($img, $sx, $sy);
                        // A pixel is transparent when it uses the transparent color index.
                        if ($transparent && $index === $transparentColor) {
                            continue; // Skip transparent pixel in average.
                        }
                        $rgb = imagecolorsforindex($img, $index);
                        $sumR += $rgb['red'];
                        $sumG += $rgb['green'];
                        $sumB += $rgb['blue'];
                        $count++;
                    }
                }
                if ($count > 0) {
                    $row[] = [
                        (int) round($sumR / $count),
                        (int) round($sumG / $count),
                        (int) round($sumB / $count),
                    ];
                } else {
                    // Every pixel was transparent (or none were sampled) —
                    // the cell shows the terminal background.
                    $row[] = null;
                }
            }
            $rows[] = $row;
        }
        imagedestroy($img);
        return new Frame($rows, $delay, $disposal, $transparent, $rgba, $rgbaWidth, $rgbaHeight);
    }

    /**
     * Walk the LZW image data starting at $start (the minimum-code-size
     * byte) and return the index of the 0x00 sub-block terminator.
     *
     * Sub-block lengths are full bytes (1–255) — only 0x00 ends the
     * chain. An earlier version also broke on any length ≥ 0x80, but
     * GIF encoders routinely emit 254-byte sub-blocks, so that truncated
     * the LZW stream and made `imagecreatefromstring()` reject every
     * real frame.
     */
    private static function findImageDataEnd(string $bytes, int $start): int
    {
        // Skip the leading LZW minimum-code-size byte before the sub-blocks.
        $j = $start + 1;
        $len = strlen($bytes);
        while ($j < $len) {
            $subLen = ord($bytes[$j]);
            $j++;
            if ($subLen === 0) {
                break;
            }
            if ($j + $subLen > $len) {
                break; // Would overrun — treat truncated tail as end-of-data.
            }
            $j += $subLen;
        }
        return $j - 1;
    }
}

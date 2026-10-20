<?php

declare(strict_types=1);

namespace SugarCraft\Video\Decode;

use SugarCraft\Animation\Decoder as AnimationDecoder;
use SugarCraft\Animation\Frame as AnimationFrame;
use SugarCraft\Video\Lang;
use SugarCraft\Video\Render\Mode;

/**
 * Decoder implementation that wraps sugar-animation's pure-PHP GIF decoder.
 *
 * Uses AnimationDecoder::decodeBytes() (E-07/E-11) to get a stream of
 * AnimationFrame objects over bytes captured ONCE at open, then converts each
 * frame's cell grid to an RgbFrame:
 *   - AnimationFrame::$cells is list<list<array{0:int,1:int,2:int}|null>>
 *   - null cells become black [0, 0, 0]
 *   - Each row is left-to-right, top-to-bottom scanning
 *   - The decode height is cellsH * $mode->rowsPerCell() so a GIF frame has the
 *     same pixel resolution as FfmpegDecoder for the given mode (HalfBlock packs
 *     2 source rows per cell → cellsH * 2; the 1-row modes → cellsH).
 *
 * @see video_plan.md lines 175-180
 * @implements Decoder
 */
final class GifDecoder implements Decoder
{
    /**
     * Byte ceiling for the open-time GIF capture, mirroring sugar-image's
     * `ImageSource::MAX_BYTES` (E-11): the whole point of holding the bytes
     * is that no LATER read can surprise us with more, so the ceiling must
     * fire at the first (and only) read.
     */
    public const MAX_SOURCE_BYTES = 67_108_864; // 64 MiB

    /**
     * `st_mode` masks used by {@see readBoundedBytes()} to prove the source
     * is a regular file at every check, mirroring the constants
     * sugar-image's round-4 hardened ingress uses (same S_IFmt/S_IFREG pair).
     */
    private const STAT_TYPE_MASK = 0170000;
    private const STAT_REGULAR_BIT = 0100000;

    /** @var list<AnimationFrame> */
    private array $frames = [];

    private int $frameIndex = 0;
    private int $cellsW = 0;
    private int $cellsH = 0;

    /**
     * The container bytes captured at open (E-11). Every decode this object
     * performs — open, re-hydrate, reopen — runs over THIS buffer, never over
     * the caller's path again: a file swapped beneath a live player used to
     * change frame counts and dimensions mid-playback, the exact cross-open
     * TOCTOU sugar-image hardened against in round 4. Survives close() by
     * design (bounded by MAX_SOURCE_BYTES, released with the object); a fresh
     * open of a DIFFERENT source replaces it.
     */
    private ?string $bytes = null;

    private string $bytesSource = '';

    /**
     * Graphics modes only: the pixel box the frame must be presented at,
     * which is the FULL cellsW·cellPxW × cellsH·cellPxH terminal resolution.
     * 0 in every text mode, where the decode grid is already the frame.
     *
     * It is tracked separately from the decode grid because the two stopped
     * being the same number — see {@see open()} — and
     * {@see \SugarCraft\Video\Render\GraphicsRenderer::render()} recovers the
     * cell footprint by dividing the frame's pixel size by the cell box, so a
     * frame handed over at the decode size would be drawn into a fraction of
     * the cells the player reserved for it.
     */
    private int $targetW = 0;
    private int $targetH = 0;

    /**
     * @param int $cellPxW Pixel width of a terminal cell — graphics modes decode at
     *                     cellsW·cellPxW pixels so the image protocols get full
     *                     resolution rather than one pixel per cell.
     * @param int $cellPxH Pixel height of a terminal cell.
     */
    public function __construct(
        private readonly int $cellPxW = 10,
        private readonly int $cellPxH = 20,
    ) {
    }

    /**
     * @inheritDoc
     *
     * Text modes decode at cellsW·colsPerCell × cellsH·rowsPerCell so GIF output
     * matches FfmpegDecoder per mode (HalfBlock packs 2 source rows per cell →
     * cellsH*2; the 1-row modes → cellsH; $mode === null defaults to HalfBlock).
     * Graphics modes decode at the terminal's full pixel resolution
     * (cellsW·cellPxW × cellsH·cellPxH) so the image protocols get real detail —
     * the raw frame is handed to {@see GraphicsRenderer}, which encodes it once.
     *
     * @see GifDecoder::reopen() for the E-11 bytes-ingress note on re-decodes.
     */
    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        // A GIF is decoded from a local file by sugar-animation, not fetched, so HTTP
        // request headers never apply here. They are accepted (interface parity with
        // the ffmpeg path) and dropped — but said so out loud, not silently, so a
        // caller who believed credentials were being sent for a GIF source learns the
        // truth from the log rather than debugging a phantom 200-OK-with-wrong-frames.
        if ($headers !== []) {
            error_log(Lang::t('header.ignored_local_source', [
                'count' => count($headers),
                'source' => $source,
            ]));
        }

        // E-11/E-07: the source is read EXACTLY ONCE, here, bounded by
        // MAX_SOURCE_BYTES. From this point on every decode in this object's
        // lifetime — including reopen() — runs over $this->bytes, never over
        // $source again, so no later mutation of the file can change what
        // plays. A refusal (missing, non-regular, oversized) is a fail-loud
        // RuntimeException before any grid is computed.
        $this->bytes = self::readBoundedBytes($source);
        $this->bytesSource = $source;

        $this->hydrate($cellsW, $cellsH, $fps, $mode, $startSec, $headers);
    }

    /**
     * Rebuild the frame list from the bytes already captured at open.
     *
     * Split out of {@see open()} so reopen() of the SAME source can refresh
     * the playback state without touching the filesystem again (E-11). The
     * header-drop notice belongs to the callers — they still have $source.
     */
    private function hydrate(int $cellsW, int $cellsH, float $fps, ?Mode $mode, float $startSec): void
    {
        $bytes = $this->bytes;
        if ($bytes === null) {
            throw new \RuntimeException('GIF hydrate called without a captured source — open() must precede it.');
        }

        $this->cellsW = $cellsW;
        $this->cellsH = $cellsH;
        $this->frameIndex = 0;

        // Decode the GIF using sugar-animation's pure-PHP decoder, at the resolution the
        // render mode needs: graphics modes want full pixel resolution; the text
        // modes scale the cell grid by their source-pixels-per-cell packing.
        // E-07: the decode runs over the captured bytes, not the path.
        if ($mode?->isGraphics() ?? false) {
            $this->targetW = max(1, $cellsW * $this->cellPxW);
            $this->targetH = max(1, $cellsH * $this->cellPxH);
            [$decodeW, $decodeH] = $this->graphicsDecodeGrid($bytes, $this->targetW, $this->targetH);
        } else {
            $this->targetW = 0;
            $this->targetH = 0;
            [$decodeW, $decodeH] = [$cellsW * ($mode?->colsPerCell() ?? 1), $cellsH * ($mode?->rowsPerCell() ?? 2)];
        }

        // A3b guard: sugar-animation documents RuntimeException, but an older vendored
        // copy (or a new crafted-header shape) could still leak a raw ValueError
        // from imagecreatetruecolor(). Reel's decoder contract is RuntimeException
        // (see FfmpegDecoder), so translate narrowly instead of letting the
        // engine-level error class escape.
        try {
            $this->frames = iterator_to_array(AnimationDecoder::decodeBytes($bytes, $decodeW, $decodeH), preserve_keys: false);
        } catch (\ValueError $e) {
            throw new \RuntimeException("GIF decode failed: {$e->getMessage()}", 0, $e);
        }

        // Best-effort time seek: all GIF frames are already in memory, so advance
        // the cursor to the frame at $startSec (clamped). GIF timing is per-frame,
        // so this uses the caller's nominal fps as an approximation.
        //
        // E-11: the clamp is symmetric. The old upper bound was count(), which put
        // any seek that reached or rounded past the last frame one slot BEYOND it —
        // next() hit EOF at once and the Player's loop wrapped to frame 0, so a
        // late-time seek visibly jumped to START. Landing on the LAST frame is the
        // honest semantics for a seek past the end, and max(0, …) keeps a negative
        // nominal index off the cursor even if the > 0.0 guard above ever moves.
        if ($startSec > 0.0 && $fps > 0.0) {
            $this->frameIndex = max(0, min(count($this->frames) - 1, (int) round($startSec * $fps)));
        }
    }

    /**
     * @inheritDoc
     */
    public function next(): ?RgbFrame
    {
        if ($this->frameIndex >= count($this->frames)) {
            return null;
        }

        $animationFrame = $this->frames[$this->frameIndex++];
        return $this->animationFrameToRgbFrame($animationFrame);
    }

    /**
     * The grid sugar-animation is actually asked for on the graphics path.
     *
     * THE BUG THIS CLOSES: `php examples/play.php synthetic sixel` died with
     * `sugar-animation: cell grid product exceeds maximum (100000)`. The graphics
     * branch asked for the terminal's full pixel box — 80×24 cells at the
     * default 10×20 cell geometry is 800×480, or 384,000 — and sugar-animation
     * caps a decode at {@see AnimationDecoder::MAX_CELLS}. That cap is not
     * arbitrary: sugar-animation area-averages in PHP, one `imagecolorat()` per
     * source pixel per cell, so 384k cells would have been a multi-second,
     * multi-gigabyte way to fail instead of an instant one.
     *
     * Two bounds, both of which shrink the request uniformly so the aspect
     * ratio survives:
     *
     *  1. THE SOURCE'S OWN SIZE. Asking a 120×60 GIF for 800×480 is 53× its
     *     real resolution — every one of those pixels is invented by the
     *     slowest resampler in the stack. The upscale to the terminal's box
     *     happens later, in C, in {@see upscaleToTarget()}.
     *  2. sugar-animation's cap, for the case where the source genuinely is huge.
     *
     * @return array{0:int,1:int}
     */
    private function graphicsDecodeGrid(string $bytes, int $targetW, int $targetH): array
    {
        $w = $targetW;
        $h = $targetH;

        // E-11: dimensions come from the captured bytes' logical screen
        // descriptor, not @getimagesize($path) — the path must stay dead after
        // the one open-time capture.
        [$srcW, $srcH] = self::gifScreenDims($bytes);
        if ($srcW > 0 && $srcH > 0 && ($srcW < $w || $srcH < $h)) {
            $scale = min($srcW / $w, $srcH / $h);
            $w = max(1, (int) round($w * $scale));
            $h = max(1, (int) round($h * $scale));
        }

        if ($w * $h > AnimationDecoder::MAX_CELLS) {
            $scale = sqrt(AnimationDecoder::MAX_CELLS / ($w * $h));
            $w = max(1, (int) floor($w * $scale));
            $h = max(1, (int) floor($h * $scale));
        }

        return [$w, $h];
    }

    /**
     * The GIF logical-screen size read straight from the container bytes.
     *
     * Bytes 0-5 are the signature, 6-9 the two little-endian uint16s of the
     * logical screen descriptor. Anything that is not a recognisable GIF87a /
     * GIF89a screen returns [0, 0] — the same "no size known" answer
     * `@getimagesize()` used to give on a non-image, so callers treat it as
     * "skip the source-size shrink" and let the decoder's own gates refuse.
     *
     * @return array{0:int,1:int}
     */
    private static function gifScreenDims(string $bytes): array
    {
        if (strlen($bytes) < 13) {
            return [0, 0];
        }

        $signature = substr($bytes, 0, 6);
        if ($signature !== 'GIF87a' && $signature !== 'GIF89a') {
            return [0, 0];
        }

        $lsd = unpack('vwidth/vheight', substr($bytes, 6, 4));
        if (!is_array($lsd) || !isset($lsd['width'], $lsd['height'])) {
            return [0, 0];
        }

        return [(int) $lsd['width'], (int) $lsd['height']];
    }

    /**
     * Read the whole file, once, under the same hard gates sugar-image's
     * round-4 hardening applies (E-11): the path is opened exactly one time,
     * the entry must be a regular file at BOTH the stat and the post-open
     * fstat (a swap between the two is a TOCTOU plant), and the capture is
     * bounded by {@see MAX_SOURCE_BYTES} with one byte of over-read so an
     * exactly-at-cap file still fails loud instead of silently truncating.
     *
     * @throws \RuntimeException on missing, non-regular, unreadable, or oversized source
     */
    private static function readBoundedBytes(string $source): string
    {
        $stat = @stat($source);
        if ($stat === false) {
            throw new \RuntimeException(Lang::t('gif.source_unreadable', [
                'path' => $source,
                'reason' => 'no such file or directory',
            ]));
        }

        if (($stat['mode'] & self::STAT_TYPE_MASK) !== self::STAT_REGULAR_BIT) {
            throw new \RuntimeException(Lang::t('gif.source_unreadable', [
                'path' => $source,
                'reason' => 'not a regular file',
            ]));
        }

        $handle = @fopen($source, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(Lang::t('gif.source_unreadable', [
                'path' => $source,
                'reason' => 'unreadable before open',
            ]));
        }

        try {
            $afterOpen = fstat($handle);
            if (!is_array($afterOpen) || (($afterOpen['mode'] & self::STAT_TYPE_MASK) !== self::STAT_REGULAR_BIT)) {
                throw new \RuntimeException(Lang::t('gif.source_unreadable', [
                    'path' => $source,
                    'reason' => 'not a regular file after open',
                ]));
            }

            if ((int) $afterOpen['size'] > self::MAX_SOURCE_BYTES) {
                throw new \RuntimeException(Lang::t('gif.source_too_large', [
                    'size' => (int) $afterOpen['size'],
                    'max' => self::MAX_SOURCE_BYTES,
                ]));
            }

            $bytes = stream_get_contents($handle, self::MAX_SOURCE_BYTES + 1);
            if ($bytes === false) {
                throw new \RuntimeException(Lang::t('gif.source_unreadable', [
                    'path' => $source,
                    'reason' => 'read failed',
                ]));
            }

            if (strlen($bytes) > self::MAX_SOURCE_BYTES) {
                throw new \RuntimeException(Lang::t('gif.source_too_large', [
                    'size' => strlen($bytes),
                    'max' => self::MAX_SOURCE_BYTES,
                ]));
            }

            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Present a decoded grid at the graphics path's full pixel box, as PNG.
     *
     * {@see graphicsDecodeGrid()} deliberately decodes below the terminal's
     * pixel box, so something has to make up the difference before
     * {@see \SugarCraft\Video\Render\GraphicsRenderer} divides the frame size
     * by the cell box to recover the cell footprint. GD does it: one
     * `imagecopyresampled()` plus one `imagepng()`, both in C.
     *
     * The result rides in `$png` rather than `$bytes` for the reason
     * {@see RgbFrame} gives — the graphics protocols consume a PNG verbatim,
     * and reading 384,000 upscaled pixels back out with `imagecolorat()` just
     * to rebuild an rgb24 buffer nothing on this path reads would reintroduce
     * the per-pixel PHP loop the resample exists to avoid. It is also exactly
     * what {@see FfmpegDecoder} already hands the same renderer.
     *
     * E-11 double-loss, stated plainly: the decode grid is cell-quantised
     * FIRST ({@see graphicsDecodeGrid()} shrinks to the source's own size and
     * sugar-animation's cell budget), then this resample rebuilds the
     * quantised grid into the full pixel box. `imagecopyresampled()` cannot
     * restore detail discarded at decode time, so the frame loses twice —
     * cell downsample, then smooth upscale — baking a softness chicle never
     * shows, because chafa composites full-canvas RGBA and sizes output only
     * at print time. E-07's RGBA bytes ingress has SINCE LANDED
     * ({@see AnimationFrame::rgbaBytes()}, S6.3), so the remaining work is
     * only the rework itself: resample from true-colour pixels and delete
     * this per-grid rebuild. It is deliberately NOT taken in S6.3 — this
     * step's contract is bytes ingress + the TOCTOU reopen cure, and the
     * render path keeps consuming cells so every digest stays put.
     */
    private function upscaleToTarget(string $bytes, int $w, int $h): ?RgbFrame
    {
        if (!\extension_loaded('gd')) {
            return null;
        }

        $src = @\imagecreatetruecolor($w, $h);
        if ($src === false) {
            return null;
        }

        try {
            $offset = 0;
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    \imagesetpixel($src, $x, $y, (\ord($bytes[$offset]) << 16) | (\ord($bytes[$offset + 1]) << 8) | \ord($bytes[$offset + 2]));
                    $offset += 3;
                }
            }

            $dst = @\imagecreatetruecolor($this->targetW, $this->targetH);
            if ($dst === false) {
                return null;
            }

            try {
                \imagecopyresampled($dst, $src, 0, 0, 0, 0, $this->targetW, $this->targetH, $w, $h);

                \ob_start();
                $ok = \imagepng($dst, null, 1);
                $png = (string) \ob_get_clean();
            } finally {
                \imagedestroy($dst);
            }
        } finally {
            \imagedestroy($src);
        }

        if ($ok === false || $png === '') {
            return null;
        }

        return new RgbFrame('', $this->targetW, $this->targetH, $png);
    }

    /**
     * Convert a AnimationFrame to an RgbFrame.
     *
     * @param AnimationFrame $animationFrame
     * @return RgbFrame
     */
    private function animationFrameToRgbFrame(AnimationFrame $animationFrame): RgbFrame
    {
        $cells = $animationFrame->cells;
        $h = count($cells);
        $w = $h > 0 ? count($cells[0]) : 0;

        // Build rgb24 bytes: row-by-row, left-to-right, top-to-bottom
        $bytes = '';
        for ($cy = 0; $cy < $h; $cy++) {
            $row = $cells[$cy] ?? [];
            for ($cx = 0; $cx < $w; $cx++) {
                $cell = $row[$cx] ?? null;
                if ($cell === null) {
                    // Transparent or black
                    $bytes .= "\x00\x00\x00";
                } else {
                    // cell is array{0:int,1:int,2:int} representing R, G, B
                    $bytes .= chr($cell[0]) . chr($cell[1]) . chr($cell[2]);
                }
            }
        }

        // Graphics modes: hand back the terminal's full pixel box, not the
        // (deliberately smaller) grid sugar-animation was asked for. A GD failure
        // degrades to the raw grid rather than killing playback — the image
        // lands small, which is visible and survivable, unlike a fatal.
        if ($this->targetW > 0 && ($w !== $this->targetW || $h !== $this->targetH) && $w > 0 && $h > 0) {
            $scaled = $this->upscaleToTarget($bytes, $w, $h);
            if ($scaled !== null) {
                return $scaled;
            }
        }

        return new RgbFrame($bytes, $w, $h);
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
     * @inheritDoc
     *
     * Drops the decoded frames and rewinds the cursor. The captured bytes are
     * KEPT on purpose (E-11): a reopen of the same source must be able to
     * re-hydrate without re-reading the path, and the buffer is already
     * bounded by {@see MAX_SOURCE_BYTES} — it is released when this object
     * dies, or replaced when a DIFFERENT source is opened.
     */
    public function close(): void
    {
        $this->frames = [];
        $this->frameIndex = 0;
    }

    /**
     * @inheritDoc
     *
     * Closes and re-hydrates the decoder with the given parameters.
     *
     * E-11 (IMPLEMENTED, S6.3): the historical bug was that open()/reopen()
     * re-read the PATH on every call, so a file mutated between open and
     * reopen was re-decoded with different frame counts or dimensions
     * mid-playback — the cross-open TOCTOU sugar-image hardened against in
     * round 4. It never landed here. It lands now: the bytes are captured
     * once in {@see open()} and a reopen of the SAME source re-hydrates from
     * that capture without touching the filesystem again. Reopening a
     * DIFFERENT source takes the fresh-capture path (a legit playlist hop).
     */
    public function reopen(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        $this->close();

        if ($this->bytes !== null && $source === $this->bytesSource) {
            if ($headers !== []) {
                error_log(Lang::t('header.ignored_local_source', [
                    'count' => count($headers),
                    'source' => $source,
                ]));
            }

            $this->hydrate($cellsW, $cellsH, $fps, $mode, $startSec, $headers);
            return;
        }

        $this->open($source, $cellsW, $cellsH, $fps, $mode, $startSec, $headers);
    }

    /**
     * A GIF decoder is factory-owned and re-decodes its file from scratch, so
     * Player rebuilds it via DecoderFactory rather than reopening in place —
     * matching the ffmpeg path. The distinction only matters for custom
     * in-memory decoders injected through Player::fromDecoder().
     */
    public function reopensInPlace(): bool
    {
        return false;
    }
}

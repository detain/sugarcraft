<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Decode;

use SugarCraft\Flip\Decoder as FlipDecoder;
use SugarCraft\Flip\Frame as FlipFrame;
use SugarCraft\Reel\Lang;
use SugarCraft\Reel\Render\Mode;

/**
 * Decoder implementation that wraps candy-flip's pure-PHP GIF decoder.
 *
 * Uses FlipDecoder::decode() to get a list of FlipFrame objects, then
 * converts each frame's cell grid to an RgbFrame:
 *   - FlipFrame::$cells is list<list<array{0:int,1:int,2:int}|null>>
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
    /** @var list<FlipFrame> */
    private array $frames = [];

    private int $frameIndex = 0;
    private int $cellsW = 0;
    private int $cellsH = 0;

    /**
     * Graphics modes only: the pixel box the frame must be presented at,
     * which is the FULL cellsW·cellPxW × cellsH·cellPxH terminal resolution.
     * 0 in every text mode, where the decode grid is already the frame.
     *
     * It is tracked separately from the decode grid because the two stopped
     * being the same number — see {@see open()} — and
     * {@see \SugarCraft\Reel\Render\GraphicsRenderer::render()} recovers the
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
     */
    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        // A GIF is decoded from a local file by candy-flip, not fetched, so HTTP
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
        $this->cellsW = $cellsW;
        $this->cellsH = $cellsH;
        $this->frameIndex = 0;

        // Decode the GIF using candy-flip's pure-PHP decoder, at the resolution the
        // render mode needs: graphics modes want full pixel resolution; the text
        // modes scale the cell grid by their source-pixels-per-cell packing.
        if ($mode?->isGraphics() ?? false) {
            $this->targetW = max(1, $cellsW * $this->cellPxW);
            $this->targetH = max(1, $cellsH * $this->cellPxH);
            [$decodeW, $decodeH] = $this->graphicsDecodeGrid($source, $this->targetW, $this->targetH);
        } else {
            $this->targetW = 0;
            $this->targetH = 0;
            [$decodeW, $decodeH] = [$cellsW * ($mode?->colsPerCell() ?? 1), $cellsH * ($mode?->rowsPerCell() ?? 2)];
        }

        $this->frames = FlipDecoder::decode($source, $decodeW, $decodeH);

        // Best-effort time seek: all GIF frames are already in memory, so advance
        // the cursor to the frame at $startSec (clamped). GIF timing is per-frame,
        // so this uses the caller's nominal fps as an approximation.
        if ($startSec > 0.0 && $fps > 0.0) {
            $this->frameIndex = min(count($this->frames), (int) round($startSec * $fps));
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

        $flipFrame = $this->frames[$this->frameIndex++];
        return $this->flipFrameToRgbFrame($flipFrame);
    }

    /**
     * The grid candy-flip is actually asked for on the graphics path.
     *
     * THE BUG THIS CLOSES: `php examples/play.php synthetic sixel` died with
     * `candy-flip: cell grid product exceeds maximum (100000)`. The graphics
     * branch asked for the terminal's full pixel box — 80×24 cells at the
     * default 10×20 cell geometry is 800×480, or 384,000 — and candy-flip
     * caps a decode at {@see FlipDecoder::MAX_CELLS}. That cap is not
     * arbitrary: candy-flip area-averages in PHP, one `imagecolorat()` per
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
     *  2. candy-flip's cap, for the case where the source genuinely is huge.
     *
     * @return array{0:int,1:int}
     */
    private function graphicsDecodeGrid(string $source, int $targetW, int $targetH): array
    {
        $w = $targetW;
        $h = $targetH;

        $size = @getimagesize($source);
        if (is_array($size) && $size[0] > 0 && $size[1] > 0 && ($size[0] < $w || $size[1] < $h)) {
            $scale = min($size[0] / $w, $size[1] / $h);
            $w = max(1, (int) round($w * $scale));
            $h = max(1, (int) round($h * $scale));
        }

        if ($w * $h > FlipDecoder::MAX_CELLS) {
            $scale = sqrt(FlipDecoder::MAX_CELLS / ($w * $h));
            $w = max(1, (int) floor($w * $scale));
            $h = max(1, (int) floor($h * $scale));
        }

        return [$w, $h];
    }

    /**
     * Present a decoded grid at the graphics path's full pixel box, as PNG.
     *
     * {@see graphicsDecodeGrid()} deliberately decodes below the terminal's
     * pixel box, so something has to make up the difference before
     * {@see \SugarCraft\Reel\Render\GraphicsRenderer} divides the frame size
     * by the cell box to recover the cell footprint. GD does it: one
     * `imagecopyresampled()` plus one `imagepng()`, both in C.
     *
     * The result rides in `$png` rather than `$bytes` for the reason
     * {@see RgbFrame} gives — the graphics protocols consume a PNG verbatim,
     * and reading 384,000 upscaled pixels back out with `imagecolorat()` just
     * to rebuild an rgb24 buffer nothing on this path reads would reintroduce
     * the per-pixel PHP loop the resample exists to avoid. It is also exactly
     * what {@see FfmpegDecoder} already hands the same renderer.
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
     * Convert a FlipFrame to an RgbFrame.
     *
     * @param FlipFrame $flipFrame
     * @return RgbFrame
     */
    private function flipFrameToRgbFrame(FlipFrame $flipFrame): RgbFrame
    {
        $cells = $flipFrame->cells;
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
        // (deliberately smaller) grid candy-flip was asked for. A GD failure
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
     */
    public function close(): void
    {
        $this->frames = [];
        $this->frameIndex = 0;
    }

    /**
     * @inheritDoc
     *
     * Closes and re-opens the decoder with the given parameters.
     */
    public function reopen(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        $this->close();
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

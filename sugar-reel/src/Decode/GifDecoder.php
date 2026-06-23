<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Decode;

use SugarCraft\Flip\Decoder as FlipDecoder;
use SugarCraft\Flip\Frame as FlipFrame;
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
     * @inheritDoc
     *
     * The decode height is cellsH * $mode->rowsPerCell() so GIF output matches
     * FfmpegDecoder per mode (HalfBlock packs 2 source rows per cell → cellsH*2;
     * the 1-row modes → cellsH). $mode === null defaults to 2 (HalfBlock),
     * matching DecoderFactory's documented null-default.
     */
    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0): void
    {
        $this->cellsW = $cellsW;
        $this->cellsH = $cellsH;
        $this->frameIndex = 0;

        // Decode the GIF using candy-flip's pure-PHP decoder. Scale the cell-grid
        // height by rowsPerCell so the frame pixel resolution matches the mode.
        $this->frames = FlipDecoder::decode($source, $cellsW, $cellsH * ($mode?->rowsPerCell() ?? 2));

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
}

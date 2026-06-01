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
 *   - RgbFrame stores cellsW x cellsH pixels (not cellsH * 2, since
 *     the 2x vertical packing is handled by the HalfBlockRenderer)
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
     * The $mode parameter is accepted for interface compatibility but ignored:
     * GIF decoding via candy-flip always outputs at cell resolution regardless
     * of the rendering mode.
     */
    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null): void
    {
        $this->cellsW = $cellsW;
        $this->cellsH = $cellsH;
        $this->frameIndex = 0;

        // Decode the GIF using candy-flip's pure-PHP decoder
        $this->frames = FlipDecoder::decode($source, $cellsW, $cellsH);
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

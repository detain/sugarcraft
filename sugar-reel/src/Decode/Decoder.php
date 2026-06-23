<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Decode;

use SugarCraft\Reel\Render\Mode;

/**
 * Interface for video decoders that produce RgbFrame objects.
 *
 * Implementations wrap external tools (ffmpeg) or pure-PHP libraries
 * (candy-flip for GIF) to decode video into a cell-grid of RGB pixels.
 */
interface Decoder
{
    /**
     * Open the decoder for a source file, targeting a grid of cellsW x cellsH
     * at approximately the given fps.
     *
     * @param string $source Path to the video source (mp4, avi, gif, etc.)
     * @param int $cellsW Target width in terminal cells
     * @param int $cellsH Target height in terminal cells
     * @param float $fps Target frames per second
     * @param Mode|null $mode Rendering mode (null = HalfBlock for backward compatibility)
     * @param float $startSec Seconds to seek into the source before the first frame
     *        (0 = from the start). Enables fast time-based seeking; a decoder without
     *        true seek support treats it as best-effort.
     */
    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0): void;

    /**
     * Yield the next RgbFrame, or null if there are no more frames.
     *
     * @return \Generator<int, RgbFrame, mixed, void>
     */
    public function getIterator(): \Generator;

    /**
     * Return the next RgbFrame, or null if no more frames are available.
     */
    public function next(): ?RgbFrame;

    /**
     * Close resources (pipes, processes) held by the decoder.
     */
    public function close(): void;
}

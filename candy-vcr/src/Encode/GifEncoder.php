<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Encode;

/**
 * Interface for GIF encoders.
 */
interface GifEncoder
{
    /**
     * Encode a sequence of frames into a GIF.
     *
     * @param \Iterator<int, \GdImage> $frames iterator of GD images
     * @param int $cols terminal columns
     * @param int $rows terminal rows
     * @param array<int, float> $frameHolds duration (seconds) to display each frame; same length as frames
     * @param string $outputPath destination .gif file path
     */
    public function encode(
        \Iterator $frames,
        int $cols,
        int $rows,
        array $frameHolds,
        string $outputPath,
    ): void;
}

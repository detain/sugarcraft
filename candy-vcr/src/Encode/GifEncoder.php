<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Encode;

/**
 * Interface for GIF encoders.
 *
 * Encodes a sequence of PNG images into an animated GIF.
 */
interface GifEncoder
{
    /**
     * Encode a sequence of frames into an animated GIF.
     *
     * @param list<non-empty-string> $pngPaths Absolute paths to PNG frame files
     * @param string $outputPath Absolute path for output GIF
     * @param int $fps Frames per second
     * @param list<int>|null $durations Per-frame hold durations in milliseconds (for variable frame rate)
     * @return bool True on success
     * @throws \RuntimeException on encoding failure
     */
    public function encode(
        array $pngPaths,
        string $outputPath,
        int $fps = 30,
        ?array $durations = null,
    ): bool;

    /**
     * Check if this encoder is available in the current environment.
     */
    public function isAvailable(): bool;

    /**
     * Encoder name for display and selection purposes.
     */
    public function name(): string;
}

<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Decode;

use SugarCraft\Reel\Source\Probe;

/**
 * Factory for creating the appropriate Decoder based on the source file.
 *
 * Decision is made at creation time (not open time):
 *   - If source ends with .gif (case-insensitive) → GifDecoder
 *   - Else if ffmpeg is available → FfmpegDecoder
 *   - Else → GifDecoder (fallback; will fail on non-GIF source)
 */
final class DecoderFactory
{
    /**
     * Create an appropriate decoder for the given source file.
     *
     * @param string $source Path to the video source
     * @param int $cellsW Target width in terminal cells
     * @param int $cellsH Target height in terminal cells
     * @param float $fps Target frames per second
     * @return Decoder
     */
    public static function create(string $source, int $cellsW, int $cellsH, float $fps): Decoder
    {
        $isGif = preg_match('/\.gif$/i', $source) === 1;

        if ($isGif) {
            $decoder = new GifDecoder();
        } elseif (Probe::hasFFmpeg()) {
            $decoder = new FfmpegDecoder();
        } else {
            // Fallback to GIF decoder — will fail gracefully on non-GIF sources
            $decoder = new GifDecoder();
        }

        $decoder->open($source, $cellsW, $cellsH, $fps);
        return $decoder;
    }
}

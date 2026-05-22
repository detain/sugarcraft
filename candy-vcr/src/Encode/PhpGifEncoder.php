<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Encode;

/**
 * Pure-PHP GIF encoder fallback.
 *
 * This is a stub implementation that throws RuntimeException.
 * Pure-PHP GIF encoding would require implementing GIF89a animation
 * with LZW compression per the spec. The approach would be:
 *
 * 1. Extract RGBA pixels from each GdImage via imagecolorat()
 * 2. Build GIF89a with:
 *    - Logical Screen Descriptor (width, height, GCT flag)
 *    - Global Color Table (256 colors max, optimized per frame or global)
 *    - Netscape Application Extension (loop count)
 *    - For each frame:
 *      - Graphic Control Extension (delay = frameHold * 100, disposal method)
 *      - Image Descriptor (local color table, LZW compressed pixel data)
 * 3. Write finalizer byte (0x3B)
 *
 * The core challenge is LZW compression in pure PHP — the encoding
 * is slow (5-10x slower than ffmpeg) and complex to implement correctly
 * per the GIF89a spec. Use FfmpegGifEncoder in production.
 */
final class PhpGifEncoder implements GifEncoder
{
    public function encode(
        \Iterator $frames,
        int $cols,
        int $rows,
        array $frameHolds,
        string $outputPath,
    ): void {
        throw new \RuntimeException(
            'Pure-PHP GIF encoder not yet implemented; use FfmpegGifEncoder',
        );
    }
}

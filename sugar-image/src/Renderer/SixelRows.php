<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Support\PushToPull;

/**
 * Row-at-a-time view over a {@see SixelRenderer} (D-05).
 *
 * Sixel is the protocol where incremental encoding pays: the encoder is
 * already band-lazy — {@see SixelRenderer::encodeBandStream()} pulls index
 * rows from a rolling-window generator and emits one chunk per 6-pixel band
 * (header, each band body, terminator), concatenating byte-for-byte to
 * {@see SixelRenderer::render()}. This class does not re-implement any of
 * that; it converts the existing push stream into the pull shape
 * {@see IncrementalRenderer} promises, via {@see PushToPull}, so a pacing
 * consumer can interleave the encode with other loop work.
 *
 * Wrapping rather than widening the renderer's own surface is deliberate:
 * SixelRenderer is a hot file for concurrent work (alpha threshold,
 * serpentine, quantizer), and the band stream is already pinned as the
 * one-shot path's twin — the laziness seam is the only new thing here.
 */
final class SixelRows implements IncrementalRenderer
{
    public function __construct(
        private readonly SixelRenderer $renderer,
    ) {}

    public function renderRows(ImageSource $image, int $width, ?int $height = null): \Generator
    {
        return PushToPull::chunks(
            function (callable $write) use ($image, $width, $height): void {
                $this->renderer->encodeBandStream($image, $width, $write, $height);
            },
        );
    }

    /** The wrapped one-shot renderer — parity reference for consumers. */
    public function renderer(): SixelRenderer
    {
        return $this->renderer;
    }
}

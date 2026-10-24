<?php

declare(strict_types=1);

namespace SugarCraft\Video\Render;

use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Dither;
use SugarCraft\Image\Renderer\Iterm2Renderer;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Image\Renderer\Renderer;
use SugarCraft\Image\Renderer\SixelRenderer;
use SugarCraft\Video\Decode\RgbFrame;

/**
 * Graphics renderer — emits Sixel / Kitty / iTerm2 protocol output.
 *
 * The frame arriving here is already at the terminal's FULL pixel resolution
 * (cells × cell-pixel-size — see {@see \SugarCraft\Video\Decode\FfmpegDecoder}),
 * usually as a ready-made PNG blob ({@see RgbFrame::$png}). That is the fix for
 * the old postage-stamp output: graphics frames used to be decoded at one pixel
 * per cell and then upscaled, throwing away all detail.
 *
 * Protocol choice per frame:
 *   - **iTerm2 / Kitty**: the PNG is base64'd straight into the protocol envelope
 *     — no per-pixel PHP work, the terminal does the scaling. Full resolution,
 *     essentially free per frame.
 *   - **Sixel**: the pure-PHP {@see SixelRenderer} — this path spawns no
 *     external binary (the former external-encoder preference was retired
 *     once `candy-image` shipped as the chafa frontend; see the README for
 *     the capability note).
 *
 * The cell footprint is recovered from the frame's pixel size and the cell pixel
 * geometry, so a renderer that wants cells (all of them) gets the right count.
 *
 * @see SixelRenderer  The pure-PHP sixel encoder behind the Sixel mode.
 */
final class GraphicsRenderer implements FrameRenderer
{
    /**
     * @param int $cellPxW Pixel width of one terminal cell (must match the decoder's,
     *                     so the recovered cell count and the sixel pixel canvas line up).
     * @param int $cellPxH Pixel height of one terminal cell.
     */
    public function __construct(
        private readonly Mode $mode,
        private readonly int $cellPxW = 10,
        private readonly int $cellPxH = 20,
    ) {}

    /**
     * @inheritDoc
     */
    public function render(RgbFrame $frame, Mode $mode): string
    {
        if ($frame->w <= 0 || $frame->h <= 0) {
            return '';
        }

        $source = $this->toImageSource($frame);

        // Recover the cell footprint from the frame's full pixel size. Frames are
        // decoded at cells·cellPx, so this round-trips to the exact cell count.
        $cellsW = max(1, (int) round($frame->w / $this->cellPxW));
        $cellsH = max(1, (int) round($frame->h / $this->cellPxH));

        return $this->pickRenderer()->render($source, $cellsW, $cellsH);
    }

    /**
     * Build an ImageSource from the frame without a needless re-encode: a PNG
     * frame wraps its bytes directly (we already know the dimensions); a raw
     * frame (GIF / no-ffmpeg fallback) is ingested straight from its RGB24
     * scanline buffer via sugar-image's {@see ImageSource::fromRgb()} — no
     * per-frame PNG round-trip. A malformed short buffer keeps the older
     * GD-clamping path rather than throwing mid-playback.
     */
    private function toImageSource(RgbFrame $frame): ImageSource
    {
        if ($frame->png !== null) {
            return new ImageSource($frame->png, 'image/png', $frame->w, $frame->h);
        }

        if (strlen($frame->bytes) === $frame->w * $frame->h * 3) {
            return ImageSource::fromRgb($frame->bytes, $frame->w, $frame->h);
        }

        $gd = $frame->toGd();
        try {
            return ImageSource::fromGd($gd, 'image/png');
        } finally {
            \imagedestroy($gd);
        }
    }

    /**
     * Pick the sugar-image renderer for the current mode. Sixel is encoded in
     * pure PHP by {@see SixelRenderer}, constructed with the real cell pixel
     * size so its canvas matches the frame exactly; no external binary is
     * spawned from this path.
     */
    private function pickRenderer(): Renderer
    {
        return match ($this->mode) {
            Mode::Iterm2 => new Iterm2Renderer(),
            Mode::Kitty  => new KittyRenderer(),
            Mode::Sixel  => new SixelRenderer(Dither::FloydSteinberg, 256, $this->cellPxW, $this->cellPxH),
            default => throw new \InvalidArgumentException(
                "GraphicsRenderer does not support mode {$this->mode->value}"
            ),
        };
    }

    /**
     * @inheritDoc
     *
     * Graphics protocols fill the terminal with the image — one "cell"
     * represents the entire rendered area.
     */
    public function cellDimensions(Mode $mode): array
    {
        return ['w' => 1, 'h' => 1];
    }
}

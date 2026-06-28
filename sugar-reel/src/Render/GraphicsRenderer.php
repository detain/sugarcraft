<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Render;

use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Dither;
use SugarCraft\Mosaic\Renderer\ChafaRenderer;
use SugarCraft\Mosaic\Renderer\Iterm2Renderer;
use SugarCraft\Mosaic\Renderer\KittyRenderer;
use SugarCraft\Mosaic\Renderer\Renderer;
use SugarCraft\Mosaic\Renderer\SixelRenderer;
use SugarCraft\Reel\Decode\RgbFrame;

/**
 * Graphics renderer — emits Sixel / Kitty / iTerm2 protocol output.
 *
 * The frame arriving here is already at the terminal's FULL pixel resolution
 * (cells × cell-pixel-size — see {@see \SugarCraft\Reel\Decode\FfmpegDecoder}),
 * usually as a ready-made PNG blob ({@see RgbFrame::$png}). That is the fix for
 * the old postage-stamp output: graphics frames used to be decoded at one pixel
 * per cell and then upscaled, throwing away all detail.
 *
 * Protocol choice per frame:
 *   - **iTerm2 / Kitty**: the PNG is base64'd straight into the protocol envelope
 *     — no per-pixel PHP work, the terminal does the scaling. Full resolution,
 *     essentially free per frame.
 *   - **Sixel**: prefer `chafa` (a C encoder that quantises + dithers + sizes to
 *     the cell box far faster than PHP can) when it is on PATH; otherwise fall
 *     back to the pure-PHP {@see SixelRenderer}, which is correct but slow.
 *
 * The cell footprint is recovered from the frame's pixel size and the cell pixel
 * geometry, so a renderer that wants cells (all of them) gets the right count.
 *
 * @see Mosaic::sixel()  For the pure-PHP sixel fallback.
 * @see ChafaRenderer    For the fast external sixel encoder.
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
     * frame (GIF / no-ffmpeg fallback) is encoded once via GD.
     */
    private function toImageSource(RgbFrame $frame): ImageSource
    {
        if ($frame->png !== null) {
            return new ImageSource($frame->png, 'image/png', $frame->w, $frame->h);
        }

        $gd = $frame->toGd();
        try {
            return ImageSource::fromGd($gd, 'image/png');
        } finally {
            \imagedestroy($gd);
        }
    }

    /**
     * Pick the candy-mosaic renderer for the current mode. Sixel prefers the
     * fast `chafa` encoder and falls back to the pure-PHP encoder (constructed
     * with the real cell pixel size so its canvas matches the frame exactly).
     */
    private function pickRenderer(): Renderer
    {
        return match ($this->mode) {
            Mode::Iterm2 => new Iterm2Renderer(),
            Mode::Kitty  => new KittyRenderer(),
            // `--polite on` inhibits chafa's cursor-hide / sixel-mode toggles so the
            // output is a bare sixel DCS blob — no per-frame flicker, and it embeds
            // cleanly inside the surrounding TEA frame.
            Mode::Sixel  => ChafaRenderer::available()
                ? new ChafaRenderer(['--polite', 'on'], 'sixels')
                : new SixelRenderer(Dither::FloydSteinberg, 256, $this->cellPxW, $this->cellPxH),
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

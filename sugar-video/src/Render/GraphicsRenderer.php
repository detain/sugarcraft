<?php

declare(strict_types=1);

namespace SugarCraft\Video\Render;

use SugarCraft\Image\GraphicsFrameSession;
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
 *     essentially free per frame. Kitty frames carry image ids and the previous
 *     frame is deleted once the new one is drawn (see {@see GraphicsFrameSession}).
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

        // Emitted once, ahead of the first frame, and only after a Player rebuild
        // (see carryingFrom()); the terminal processes it in the same write as the draw.
        $residue = $this->residue;
        $this->residue = '';

        $delegate = $this->delegate();
        if ($delegate->supportsIdDelete()) {
            // Kitty: draw each frame under an alternating image id and retire the
            // previous one, so playback does not leave one image in the terminal's
            // store per frame. The session lives as long as this renderer; the
            // Player hands the last id to the successor when it rebuilds.
            $this->session ??= GraphicsFrameSession::new($delegate);

            return $residue . $this->session->present($source, $cellsW, $cellsH);
        }

        return $residue . $delegate->render($source, $cellsW, $cellsH);
    }

    /**
     * Successor for a Player rebuild (resize, mode cycle): the same geometry,
     * with the outgoing renderer's last drawn Kitty frame retired ahead of this
     * one's first draw. Without it the new session starts at frame zero and never
     * deletes the frame the old session left in the terminal's image store.
     */
    public function carryingFrom(self $previous): self
    {
        $next = new self($this->mode, $this->cellPxW, $this->cellPxH);
        $next->residue = $previous->retirement();

        return $next;
    }

    /**
     * Escape bytes this renderer still owes the terminal: the delete of the frame it
     * drew last, or a predecessor's residue it has not yet written (a second rebuild
     * before any render). '' when neither exists or the backend cannot delete by id.
     */
    private function retirement(): string
    {
        if ($this->session === null || $this->session->frameCount() === 0) {
            return $this->residue;
        }

        $delegate = $this->delegate();
        if (!$delegate->supportsIdDelete()) {
            return '';
        }

        $last = $this->session->imageId + (($this->session->frameCount() - 1) % 2);

        return $delegate->delete((string) $last);
    }

    /**
     * Delete sequence owed to the terminal from a predecessor renderer; consumed by
     * the next render().
     */
    private string $residue = '';

    /**
     * Frame-to-frame id rotation for id-addressable backends (Kitty). Null for
     * the others, which never retire a frame.
     */
    private ?GraphicsFrameSession $session = null;

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
     * SV-H12/085: the sugar-image delegate is mode-constant for this
     * renderer's whole life (the mode is a readonly constructor property,
     * and the Sixel construction args — dither, palette size, cell pixels —
     * too), so the instance is armed once instead of re-`new`ed per frame.
     */
    private ?Renderer $delegate = null;

    /**
     * Pick the sugar-image renderer for the current mode. Sixel is encoded in
     * pure PHP by {@see SixelRenderer}, constructed with the real cell pixel
     * size so its canvas matches the frame exactly; no external binary is
     * spawned from this path.
     */
    private function delegate(): Renderer
    {
        return $this->delegate ??= match ($this->mode) {
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
     * Graphics protocols resolve in the renderer: Mode treats them as 1×1
     * cells (the presentation box comes from the cell-pixel geometry).
     */
    public function cellDimensions(Mode $mode): array
    {
        // SV-L51: Mode answers the packing; no per-renderer table.
        return ['w' => $mode->colsPerCell(), 'h' => $mode->rowsPerCell()];
    }
}

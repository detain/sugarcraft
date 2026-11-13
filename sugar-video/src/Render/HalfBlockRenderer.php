<?php

declare(strict_types=1);

namespace SugarCraft\Video\Render;

use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\Renderer\HalfBlockRenderer as ImageHalfBlockRenderer;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Lang;

/**
 * Half-block Unicode renderer — delegates to sugar-image's HalfBlockRenderer.
 *
 * Each terminal cell shows two vertically-stacked pixels via the ▀ (upper-half
 * block) glyph: the upper pixel's color as foreground, the lower pixel's color
 * as background, producing a 2× vertical density improvement.
 *
 * Renders as: 38;2;TR;TG;TB;48;2;BR;BG;BB ▀
 *
 * No single upstream — drawn from maxcurzi/tplay, seatedro/glyph, joelibaceta/video-to-ascii.
 */
final class HalfBlockRenderer implements FrameRenderer
{
    /**
     * @inheritDoc
     */
    public function render(RgbFrame $frame, Mode $mode): string
    {
        // SV-L72/072: this rung paints THROUGH GD (the bridge below calls
        // RgbFrame::toGd), yet the construction door only vets the graphics
        // protocols — a direct factory consumer on a gd-less build used to
        // fatal mid-render on an undefined function. Refuse typed, the same
        // way the graphics door does. Construction stays gd-free on purpose:
        // the Player runtime never reaches this class (it paints blocks inline
        // through frameToBuffer), so the Player's block rung must remain
        // available exactly as the support() surface reports it.
        if (!RendererFactory::graphicsRenderable()) {
            throw new \RuntimeException(Lang::t('decoder.gd_missing'));
        }

        if ($frame->w <= 0 || $frame->h <= 0) {
            return '';
        }

        // Bridge: convert RgbFrame (raw rgb24 bytes) → GD image → ImageSource.
        // sugar-image's HalfBlockRenderer expects ImageSource with PNG bytes.
        $gd = $frame->toGd();
        $imageSource = ImageSource::fromGd($gd, 'image/png');
        imagedestroy($gd);

        // Delegate to sugar-image's HalfBlockRenderer (HalfBlockRenderer.php:33).
        // NOTE: This renderer is the "Mosaic" path — it is NEVER used by Player::view()
        // at runtime (Player uses the inline Buffer path in frameToBuffer instead).
        // This class exists for direct RendererFactory::new(Mode::HalfBlock) callers.
        //
        // Parity with the runtime path is guarded per CELL — glyph, foreground and
        // background compared as decoded semantics, not bytes — by
        // `tests/HalfBlockParityTest` and PlayerTest::testHalfBlockInlineMatchesMosaicRenderer.
        // Byte equality is NOT achievable here: Buffer::toAnsi() coalesces the SGR
        // runs while mosaic terminates each cell with a reset, and this path resamples
        // through GD (so it needs ext-gd, which sugar-video does not require — the
        // entry guard above turns that need into a typed refusal, SV-L72/072). Do not
        // "simplify" view() onto this path without regenerating every HalfBlock golden.
        $renderer = new ImageHalfBlockRenderer();

        // Half-block uses full width in cells, double height density.
        $cellWidth = $frame->w;
        $cellHeight = (int)round($frame->h / 2);

        return $renderer->render($imageSource, $cellWidth, $cellHeight);
    }

    /**
     * @inheritDoc
     */
    public function cellDimensions(Mode $mode): array
    {
        // SV-L51: Mode answers the packing; no per-renderer table.
        return ['w' => $mode->colsPerCell(), 'h' => $mode->rowsPerCell()];
    }
}

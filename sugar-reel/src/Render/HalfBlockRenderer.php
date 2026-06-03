<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Render;

use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\Renderer\HalfBlockRenderer as MosaicHalfBlockRenderer;
use SugarCraft\Reel\Decode\RgbFrame;

/**
 * Half-block Unicode renderer — delegates to candy-mosaic's HalfBlockRenderer.
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
        if ($frame->w <= 0 || $frame->h <= 0) {
            return '';
        }

        // Bridge: convert RgbFrame (raw rgb24 bytes) → GD image → ImageSource.
        // candy-mosaic's HalfBlockRenderer expects ImageSource with PNG bytes.
        $gd = $frame->toGd();
        $imageSource = ImageSource::fromGd($gd, 'image/png');
        imagedestroy($gd);

        // Delegate to candy-mosaic's HalfBlockRenderer (HalfBlockRenderer.php:33).
        // NOTE: This renderer is the "Mosaic" path — it is NEVER used by Player::view()
        // at runtime (Player uses the inline Buffer path in frameToBuffer instead).
        // This class exists for direct RendererFactory::create(Mode::HalfBlock) callers
        // and is guarded by testHalfBlockInlineMatchesMosaicRenderer, which asserts
        // that both paths produce identical colored half-block cells.
        $renderer = new MosaicHalfBlockRenderer();

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
        // HalfBlock reads 2 source rows per terminal cell [1,2].
        return ['w' => 1, 'h' => 2];
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Video\Render;

use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\QuarterBlockRenderer as MosaicQuarterBlockRenderer;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Lang;

/**
 * Quarter-block Unicode renderer — delegates to sugar-image's
 * QuarterBlockRenderer.
 *
 * Each terminal cell shows a 2×2 group of pixels via a quadrant glyph
 * (▘▝▀▖▌▞▛▗▚▐▜▄▙▟█): the four quadrant colours are split into a foreground and a
 * background group, giving two colours across four sub-pixel positions — sharper
 * than half-block.
 *
 * Like {@see HalfBlockRenderer} this is the "Mosaic" path used by direct
 * {@see RendererFactory::new()} callers; the Player runtime renders
 * quarter-block inline via its Buffer path (Player::frameToBuffer).
 */
final class QuarterBlockRenderer implements FrameRenderer
{
    public function render(RgbFrame $frame, Mode $mode): string
    {
        // SV-L72/072: quarter-block rides the same GD bridge as its half-block
        // twin — refuse typed at entry instead of fataling on an undefined
        // function. Construction stays gd-free for the same reason (the Player
        // paints this rung inline, never through here).
        if (!RendererFactory::graphicsRenderable()) {
            throw new \RuntimeException(Lang::t('decoder.gd_missing'));
        }

        if ($frame->w <= 0 || $frame->h <= 0) {
            return '';
        }

        $gd = $frame->toGd();
        $imageSource = ImageSource::fromGd($gd, 'image/png');
        imagedestroy($gd);

        // 2 source pixels per cell in each axis → cellW/cellH are half the frame.
        $cellWidth = (int) round($frame->w / 2);
        $cellHeight = (int) round($frame->h / 2);

        return (new MosaicQuarterBlockRenderer())->render($imageSource, max(1, $cellWidth), max(1, $cellHeight));
    }

    public function cellDimensions(Mode $mode): array
    {
        // SV-L51: Mode answers the packing; no per-renderer table.
        return ['w' => $mode->colsPerCell(), 'h' => $mode->rowsPerCell()];
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Render;

use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\QuarterBlockRenderer as MosaicQuarterBlockRenderer;
use SugarCraft\Reel\Decode\RgbFrame;

/**
 * Quarter-block Unicode renderer — delegates to candy-mosaic's
 * QuarterBlockRenderer.
 *
 * Each terminal cell shows a 2×2 group of pixels via a quadrant glyph
 * (▘▝▀▖▌▞▛▗▚▐▜▄▙▟█): the four quadrant colours are split into a foreground and a
 * background group, giving two colours across four sub-pixel positions — sharper
 * than half-block.
 *
 * Like {@see HalfBlockRenderer} this is the "Mosaic" path used by direct
 * {@see RendererFactory::create()} callers; the Player runtime renders
 * quarter-block inline via its Buffer path (Player::frameToBuffer).
 */
final class QuarterBlockRenderer implements FrameRenderer
{
    public function render(RgbFrame $frame, Mode $mode): string
    {
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
        // Quarter-block reads a 2×2 source pixel group per terminal cell.
        return ['w' => 2, 'h' => 2];
    }
}

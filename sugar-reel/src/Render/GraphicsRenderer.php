<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Render;

use SugarCraft\Mosaic\Capability;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\Renderer\KittyRenderer;
use SugarCraft\Reel\Decode\RgbFrame;

/**
 * Graphics renderer — delegates to candy-mosaic's Sixel/Kitty/iTerm2 renderers.
 *
 * Bridges RgbFrame → GD image → ImageSource, then delegates to Mosaic for
 * protocol encoding. Each graphics mode fills the terminal with the image
 * at its native pixel dimensions.
 *
 * Mirrors charmbracelet/sugar-reel Render.GraphicsRenderer.
 *
 * @see Mosaic::sixel()  For sixel protocol delegation.
 * @see Mosaic::iterm2()  For iTerm2 inline-image protocol delegation.
 * @see KittyRenderer  For kitty graphics protocol (DCS APC sequences).
 * @see video_plan.md lines 204–207  For protocol header reference.
 */
final class GraphicsRenderer implements FrameRenderer
{
    public function __construct(
        private readonly Mode $mode,
    ) {}

    /**
     * @inheritDoc
     */
    public function render(RgbFrame $frame, Mode $mode): string
    {
        if ($frame->w <= 0 || $frame->h <= 0) {
            return '';
        }

        // Bridge: RgbFrame (raw rgb24) → GD image → ImageSource.
        $gd = $frame->toGd();
        $imageSource = ImageSource::fromGd($gd, 'image/png');
        \imagedestroy($gd);

        // Delegate to candy-mosaic's Mosaic facade for protocol encoding.
        // Pass frame pixel dimensions as cell dimensions — the graphics
        // protocols handle aspect-ratio internally.
        return match ($this->mode) {
            Mode::Sixel => Mosaic::sixel()->render($imageSource, $frame->w, null),
            Mode::Kitty => (new Mosaic(new KittyRenderer(), Capability::universal(), null, null, null))
                ->render($imageSource, $frame->w, null),
            Mode::Iterm2 => Mosaic::iterm2()->render($imageSource, $frame->w, null),
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

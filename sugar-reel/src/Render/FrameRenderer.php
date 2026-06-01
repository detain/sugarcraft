<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Render;

use SugarCraft\Reel\Decode\RgbFrame;

/**
 * Contract for rendering an RgbFrame to an ANSI escape-string.
 *
 * Implementations are responsible for mapping pixel data to the
 * appropriate terminal escape sequences based on the rendering mode.
 */
interface FrameRenderer
{
    /**
     * Render one RgbFrame to an ANSI string at the frame's native dimensions.
     *
     * @param RgbFrame $frame Source frame with w×h pixels
     * @param Mode     $mode  Rendering mode to apply
     * @return string   Raw ANSI escape sequence bytes (possibly empty on failure)
     */
    public function render(RgbFrame $frame, Mode $mode): string;

    /**
     * Return the terminal cell dimensions consumed by a pixel of the given mode.
     *
     * Most modes render one source pixel per cell [1,1].
     * HalfBlock reads 2 source rows per cell [1,2].
     *
     * @param Mode $mode Rendering mode
     * @return array{w: int, h: int} Cell width and height in terminal cells
     */
    public function cellDimensions(Mode $mode): array;
}

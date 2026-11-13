<?php

declare(strict_types=1);

namespace SugarCraft\Video\Render;

use SugarCraft\Video\Decode\RgbFrame;

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
     * The terminal cell dimensions a pixel of the GIVEN mode consumes.
     *
     * SV-L51: `Mode` is the single packing authority — implementations
     * answer by delegating to {@see Mode::colsPerCell()} /
     * {@see Mode::rowsPerCell()} instead of holding per-renderer tables
     * (the old tables drifted: quarter-block's 2×2 was missing from this
     * very contract's prose). The answer follows the mode passed in, not
     * the renderer's own mode. The method survives as a call-path shim
     * for external implementations and callers; in-library consumers read
     * Mode directly.
     *
     * @param Mode $mode Rendering mode
     * @return array{w: int, h: int} Cell width and height in terminal cells
     */
    public function cellDimensions(Mode $mode): array;
}

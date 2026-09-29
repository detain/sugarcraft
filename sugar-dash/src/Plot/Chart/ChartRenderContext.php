<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Buffer\Buffer;

/**
 * Render context for diff-based chart rendering.
 *
 * Tracks the previous frame and dimensions to enable efficient
 * delta emission (only changed cells are sent). The context must
 * be passed back to render() on subsequent calls with the same Chart.
 *
 * Since F1 the $prevWidth/$prevHeight keys mirror the ACTUAL previousFrame
 * buffer dimensions (the diff geometry: diffWidth() x emitted-frame height),
 * not the chart-area size — that is what render()'s full-frame gate
 * compares, so keeping the keys consistent with the gate is the invariant.
 */
final class ChartRenderContext
{
    public function __construct(
        public ?Buffer $previousFrame = null,
        public ?int $prevWidth = null,
        public ?int $prevHeight = null,
    ) {}

    /**
     * Create a fresh context (no previous frame).
     */
    public static function fresh(): self
    {
        return new self();
    }

    /**
     * Create a new context with updated values after render.
     */
    public function withNext(Buffer $frame, int $width, int $height): self
    {
        return new self(previousFrame: $frame, prevWidth: $width, prevHeight: $height);
    }
}

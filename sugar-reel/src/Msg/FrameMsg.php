<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Reel\Decode\RgbFrame;

/**
 * A frame message carrying a newly decoded RgbFrame ready for rendering.
 *
 * Dispatched by the decoder pipeline when a frame has been decoded
 * and is ready to be displayed. The frame is already stored in the
 * Player's $currentFrame state; this Msg signals that a new frame
 * is available for display.
 *
 * No single upstream — the decode→frame-ready event mirrors the pipeline
 * shape in maxcurzi/tplay and joelibaceta/video-to-ascii.
 */
final class FrameMsg implements Msg
{
    public function __construct(
        public readonly RgbFrame $frame,
    ) {
    }
}

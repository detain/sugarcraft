<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

use SugarCraft\Animation\AnsiArt;

/**
 * The one frame of the chosen logo art an overlay paints right now, carried
 * on {@see \SugarCraft\Top\Overlay\OverlayContext} so every menu reads the
 * same snapshot the App advanced on its last tick (the context is built
 * fresh per paint — pairing art and index keeps them from disagreeing).
 */
final class LogoFrame
{
    public function __construct(
        public readonly AnsiArt $art,
        public readonly int $frameIndex,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Top\Overlay;

use SugarCraft\Sprinkles\Border;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;

/**
 * What an {@see Overlay} reads on every update() and paint(): the App's
 * CURRENT config, terminal size and theme. Built fresh per call — never
 * cache it (a resize, a tty_mode flip or a theme swap would go stale).
 */
final class OverlayContext
{
    public function __construct(
        public readonly Config $config,
        public readonly int $cols,
        public readonly int $rows,
        public readonly Ink $ink,
    ) {
    }

    /** btop createBox's corners: rounded unless tty mode or rounded_corners off. */
    public function border(): Border
    {
        return FrameBuilder::border($this->config);
    }

    public function tty(): bool
    {
        return $this->config->ttyMode();
    }
}

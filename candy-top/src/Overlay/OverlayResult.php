<?php

declare(strict_types=1);

namespace SugarCraft\Top\Overlay;

/**
 * What {@see Overlay::update()} returns.
 *
 * - `$overlay`: the overlay's next state; null closes it (btop `Closed`),
 *   uncovering the one below (btop re-runs the next set menu bit).
 * - `$push`: an overlay opened ON TOP of this one (btop `Switch`: the main
 *   menu sets the Help / Options bit and stays set underneath).
 * - `$cmd`: side effects — a signal, a renice, a quit.
 * - `$set`: config writes, applied synchronously by the App with the same
 *   validation as {@see \SugarCraft\Top\Panel\PanelResult::$set} (P-F2's
 *   options menu writes through here).
 */
final class OverlayResult
{
    /**
     * @param array<string, bool|int|string> $set
     */
    public function __construct(
        public readonly ?Overlay $overlay,
        public readonly ?\Closure $cmd = null,
        public readonly ?Overlay $push = null,
        public readonly array $set = [],
    ) {
    }

    public static function keep(Overlay $overlay): self
    {
        return new self($overlay);
    }

    public static function close(?\Closure $cmd = null): self
    {
        return new self(null, $cmd);
    }
}

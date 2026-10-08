<?php

declare(strict_types=1);

namespace SugarCraft\Top\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Top\Overlay\Overlay;

/**
 * Push `$overlay` onto the App's menu stack — btop's `Menu::show(menu)`
 * from an ASYNCHRONOUS producer: a Cmd that only learns it must show a
 * menu when it runs (a failed kill() opening the signal failure box).
 * A panel reacting to a key returns the overlay in
 * {@see \SugarCraft\Top\Panel\PanelResult::$overlay} instead, so it opens
 * inside the same update().
 */
final class OpenOverlayMsg implements Msg
{
    public function __construct(
        public readonly Overlay $overlay,
    ) {
    }
}

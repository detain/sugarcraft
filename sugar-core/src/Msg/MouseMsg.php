<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;

/**
 * A single mouse event (press, release, motion, or wheel). Coordinates are
 * 1-based, exactly as reported by the terminal: the only mouse decoder is
 * the SGR (CSI ?1006) one, and it passes the wire values through unchanged
 * (`ESC[<0;10;20M` arrives as x=10, y=20), so there is no off-by-one
 * translation layer between the terminal and these fields.
 *
 * Bubble Tea v2 splits mouse messages into four concrete types. The
 * subclasses {@see MouseClickMsg} / {@see MouseReleaseMsg} /
 * {@see MouseWheelMsg} / {@see MouseMotionMsg} let callers pattern-
 * match on the event kind via `instanceof`; existing `instanceof
 * MouseMsg` checks keep working since each subclass extends this base.
 */
class MouseMsg implements Msg
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly MouseButton $button,
        public readonly MouseAction $action,
        public readonly bool $shift = false,
        public readonly bool $alt = false,
        public readonly bool $ctrl = false,
    ) {
    }
}

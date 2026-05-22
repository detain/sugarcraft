<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;

/**
 * A single mouse event (press, release, motion, or wheel). Coordinates are
 * 1-based as reported by the terminal.
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

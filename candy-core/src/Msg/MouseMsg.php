<?php

declare(strict_types=1);

namespace CandyCore\Core\Msg;

use CandyCore\Core\Msg;
use CandyCore\Core\MouseAction;
use CandyCore\Core\MouseButton;

/**
 * A single mouse event (press, release, motion, or wheel). Coordinates are
 * 1-based as reported by the terminal.
 */
final class MouseMsg implements Msg
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly MouseButton $button,
        public readonly MouseAction $action,
        public readonly bool $shift = false,
        public readonly bool $alt = false,
        public readonly bool $ctrl = false,
    ) {}
}

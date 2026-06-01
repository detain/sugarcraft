<?php

declare(strict_types=1);

namespace SugarCraft\Input\Event;

use SugarCraft\Input\Event;

/**
 * A terminal resize event (SIGWINCH).
 *
 * @see Mirrors charmbracelet/bubbletea (input handling).
 * @readonly
 */
readonly class ResizeEvent implements Event
{
    public function __construct(
        public int $cols,
        public int $rows,
    ) {}
}

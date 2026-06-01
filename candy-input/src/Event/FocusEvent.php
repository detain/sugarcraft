<?php

declare(strict_types=1);

namespace SugarCraft\Input\Event;

use SugarCraft\Input\Event;

/**
 * Focus event — terminal window gained or lost focus.
 *
 * CSI I  = focus gained  (DECSET 1004 report)
 * CSI O  = focus lost    (DECSET 1004 report)
 *
 * @see Mirrors charmbracelet/bubbletea (input handling).
 * @readonly
 */
readonly class FocusEvent implements Event
{
    public function __construct(
        public bool $gained,
    ) {}
}

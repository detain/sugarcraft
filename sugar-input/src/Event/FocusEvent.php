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
 * Mirrors charmbracelet/bubbletea's input handling.
 * @readonly
 */
final readonly class FocusEvent implements Event
{
    public function __construct(
        public bool $gained,
        /** Exact bytes that produced this event ("\x1b[I" / "\x1b[O"); null when synthesized. */
        public ?string $raw = null,
    ) {}
}

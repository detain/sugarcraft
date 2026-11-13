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
 * Mirrors charmbracelet/bubbletea's `tea.FocusMsg`/`tea.BlurMsg` pair
 * (focus.go): gained=true for the first, gained=false for the second.
 *
 * Instances are immutable value objects: every field is fixed at construction.
 */
final readonly class FocusEvent implements Event
{
    public function __construct(
        public bool $gained,
        /** Exact bytes that produced this event ("\x1b[I" / "\x1b[O"); null when synthesized. */
        public ?string $raw = null,
    ) {}
}

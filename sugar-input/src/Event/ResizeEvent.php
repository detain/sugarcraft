<?php

declare(strict_types=1);

namespace SugarCraft\Input\Event;

use SugarCraft\Input\Event;

/**
 * A terminal resize event (SIGWINCH).
 *
 * The constructor rejects a zero or negative frame: a terminal at least
 * one cell wide and tall is an invariant by construction, not a promise
 * every consumer has to re-check (fail-fast at the boundary, the same
 * house law the paste cap follows).
 *
 * Mirrors charmbracelet/bubbletea's input handling.
 *
 * Instances are immutable value objects: every field is fixed at construction.
 */
final readonly class ResizeEvent implements Event
{
    public function __construct(
        public int $cols,
        public int $rows,
    ) {
        if ($cols < 1 || $rows < 1) {
            throw new \InvalidArgumentException("Resize dimensions must be positive, got {$cols}x{$rows}");
        }
    }
}

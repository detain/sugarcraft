<?php

declare(strict_types=1);

namespace SugarCraft\Hermit\Concerns;

/**
 * Provides show/hide/isVisible state for UI components that can be toggled.
 *
 * Used by bars and other overlay elements that need runtime visibility control.
 */
trait Visible
{
    private bool $visible = true;

    public function show(): self
    {
        $clone = clone $this;
        $clone->visible = true;
        return $clone;
    }

    public function hide(): self
    {
        $clone = clone $this;
        $clone->visible = false;
        return $clone;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }
}

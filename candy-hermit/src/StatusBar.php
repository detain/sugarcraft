<?php

declare(strict_types=1);

namespace SugarCraft\Hermit;

use SugarCraft\Hermit\Concerns\Visible;
use SugarCraft\Sprinkles\Bar\StatusBar as BarStatusBar;

/**
 * StatusBar — renders a single-line status message for the Hermit overlay.
 *
 * Used to display dynamic state information such as item counts,
 * selected file path, filter statistics, or other transient context.
 *
 * Rendering is delegated to the shared {@see BarStatusBar} primitive: the
 * bracketed segments and the message become one left-anchored group joined
 * by a space. This class keeps Hermit's map-based segment API (unique names,
 * update-in-place) as a thin wrapper over that primitive.
 */
final class StatusBar
{
    use Visible;

    private string $message = '';

    /** @var array<string, string> Optional named segments for compound status */
    private array $segments = [];

    public function __construct(string $message = '', bool $visible = true)
    {
        $this->message = $message;
        // Visible trait initializes $visible = true; re-apply if false passed.
        if (!$visible) {
            $this->visible = false;
        }
    }

    /**
     * Set the primary status message.
     */
    public function withMessage(string $message): self
    {
        $clone = clone $this;
        $clone->message = $message;
        return $clone;
    }

    /**
     * Clear the primary status message.
     */
    public function withNoMessage(): self
    {
        $clone = clone $this;
        $clone->message = '';
        return $clone;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * Add a named segment that can be rendered alongside the primary message.
     */
    public function withSegment(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->segments[(string) $name] = $value;
        return $clone;
    }

    /**
     * Remove a named segment by name.
     */
    public function withoutSegment(string $name): self
    {
        $clone = clone $this;
        unset($clone->segments[(string) $name]);
        return $clone;
    }

    /** @return array<string, string> */
    public function segments(): array
    {
        return $this->segments;
    }

    /**
     * Render the status bar as a single line of text.
     * Format: "[segment1: value] message"
     * When no message and no segments: returns empty string.
     */
    public function render(): string
    {
        if (!$this->visible) {
            return '';
        }

        $parts = [];
        foreach ($this->segments as $name => $value) {
            $parts[] = "[{$name}: {$value}]";
        }

        if ($this->message !== '') {
            $parts[] = $this->message;
        }

        // Delegate the join to the shared primitive (default separator is a
        // single space, no fixed width → natural concatenation).
        return BarStatusBar::new()->left(...$parts)->render();
    }
}

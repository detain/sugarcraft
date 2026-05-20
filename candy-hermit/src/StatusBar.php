<?php

declare(strict_types=1);

namespace SugarCraft\Hermit;

/**
 * StatusBar — renders a single-line status message for the Hermit overlay.
 *
 * Used to display dynamic state information such as item counts,
 * selected file path, filter statistics, or other transient context.
 */
final class StatusBar
{
    private string $message = '';

    private bool $visible = true;

    /** @var array<string, string> Optional named segments for compound status */
    private array $segments = [];

    public function __construct(string $message = '', bool $visible = true)
    {
        $this->message = $message;
        $this->visible = $visible;
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
     * Format: "[segment1: value] message [segment2: value]"
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

        return \implode(' ', $parts);
    }
}

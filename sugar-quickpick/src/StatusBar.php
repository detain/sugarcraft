<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick;

use SugarCraft\QuickPick\Concerns\Visible;
use SugarCraft\QuickPick\Support\UntrustedText;
use SugarCraft\Style\Bar\StatusBar as BarStatusBar;

/**
 * StatusBar — renders a single-line status message for the QuickPick overlay.
 *
 * Used to display dynamic state information such as item counts,
 * selected file path, filter statistics, or other transient context.
 *
 * Rendering is delegated to the shared {@see BarStatusBar} primitive: the
 * bracketed segments and the message become one left-anchored group joined
 * by a space. This class keeps QuickPick's map-based segment API (unique names,
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
        // Status text commonly echoes dynamic state (a selected path read
        // back from history) — guard at ingress; the render-time bracket
        // structure and separator are added after and survive.
        $this->message = UntrustedText::guard($message);
        // Visible trait initializes $visible = true; re-apply if false passed.
        if ($visible === false) {
            $this->visible = false;
        }
    }

    /**
     * House factory (`::new()`), mirroring {@see QuickPick::new()}: same
     * signature as the constructor, which stays public (the docs promise a
     * bare construction site).
     */
    public static function new(string $message = '', bool $visible = true): self
    {
        return new self($message, $visible);
    }

    /**
     * Set the primary status message.
     */
    public function withMessage(string $message): self
    {
        $clone = clone $this;
        $clone->message = UntrustedText::guard($message);
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
     *
     * Both the segment name and its value reach the rendered line verbatim,
     * so both are guarded at ingress. Names are stored in guarded form, which
     * is byte-identical for ordinary names (see {@see self::withoutSegment()}).
     */
    public function withSegment(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->segments[UntrustedText::guard($name)] = UntrustedText::guard($value);
        return $clone;
    }

    /**
     * Remove a named segment by name.
     *
     * Names are stored in guarded form, so the lookup name is guarded with
     * the same function.
     */
    public function withoutSegment(string $name): self
    {
        $clone = clone $this;
        unset($clone->segments[UntrustedText::guard($name)]);
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
        if ($this->visible === false) {
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

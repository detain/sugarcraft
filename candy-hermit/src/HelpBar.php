<?php

declare(strict_types=1);

namespace SugarCraft\Hermit;

/**
 * HelpBar — renders a single-line keyboard shortcut summary for the Hermit overlay.
 *
 * Displays key → description pairs in a compact status-line format.
 * Mirrors the Hermit quick-fix help bar rendered at the bottom of the overlay.
 */
final class HelpBar
{
    /** @var array<string, string> key → description mappings */
    private array $shortcuts = [];

    private bool $visible = true;

    public function __construct(array $shortcuts = [], bool $visible = true)
    {
        foreach ($shortcuts as $key => $description) {
            $this->shortcuts[(string) $key] = (string) $description;
        }
        $this->visible = $visible;
    }

    /**
     * Add a keyboard shortcut entry.
     */
    public function withShortcut(string $key, string $description): self
    {
        $clone = clone $this;
        $clone->shortcuts[(string) $key] = $description;
        return $clone;
    }

    /**
     * Remove a keyboard shortcut entry by key.
     */
    public function withoutShortcut(string $key): self
    {
        $clone = clone $this;
        unset($clone->shortcuts[(string) $key]);
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

    /** @return array<string, string> */
    public function shortcuts(): array
    {
        return $this->shortcuts;
    }

    /**
     * Render the help bar as a single line of text.
     * Format: "key: description | key: description | ..."
     */
    public function render(): string
    {
        if (!$this->visible || $this->shortcuts === []) {
            return '';
        }

        $parts = [];
        foreach ($this->shortcuts as $key => $description) {
            $parts[] = "{$key}: {$description}";
        }

        return \implode(' │ ', $parts);
    }
}

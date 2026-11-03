<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick;

use SugarCraft\QuickPick\Concerns\Visible;
use SugarCraft\QuickPick\Support\UntrustedText;
use SugarCraft\Style\Bar\HelpBar as BarHelpBar;

/**
 * HelpBar — renders a single-line keyboard shortcut summary for the QuickPick overlay.
 *
 * Displays key → description pairs in a compact status-line format.
 * Mirrors the QuickPick quick-fix help bar rendered at the bottom of the overlay.
 *
 * Rendering is delegated to the shared {@see BarHelpBar} primitive; this class
 * keeps QuickPick's map-based shortcut API (unique keys, update-in-place) as a
 * thin wrapper over it.
 */
final class HelpBar
{
    use Visible;

    /** @var array<string, string> key → description mappings */
    private array $shortcuts = [];

    public function __construct(array $shortcuts = [], bool $visible = true)
    {
        foreach ($shortcuts as $key => $description) {
            // Legend text reaches the terminal verbatim — guard at ingress so
            // the separator glyphs and host styling added in render() survive.
            $this->shortcuts[UntrustedText::guard((string) $key)] = UntrustedText::guard((string) $description);
        }
        if ($visible === false) {
            $this->visible = false;
        }
    }

    /**
     * Add a keyboard shortcut entry.
     */
    public function withShortcut(string $key, string $description): self
    {
        $clone = clone $this;
        $clone->shortcuts[UntrustedText::guard($key)] = UntrustedText::guard($description);
        return $clone;
    }

    /**
     * Remove a keyboard shortcut entry by key.
     *
     * Keys are stored in guarded form (see {@see self::withShortcut()}), so
     * the lookup name is guarded with the same function — plain names are
     * byte-identical under the guard and delete as expected.
     */
    public function withoutShortcut(string $key): self
    {
        $clone = clone $this;
        unset($clone->shortcuts[UntrustedText::guard($key)]);
        return $clone;
    }

    /** @return array<string, string> */
    public function shortcuts(): array
    {
        return $this->shortcuts;
    }

    /**
     * Render the help bar as a single line of text.
     * Format: "key: description │ key: description │ ..."
     */
    public function render(): string
    {
        if ($this->visible === false || $this->shortcuts === []) {
            return '';
        }

        // Delegate to the shared primitive — its defaults (": " key separator,
        // " │ " entry separator) match QuickPick's historical format byte-for-byte.
        return BarHelpBar::fromMap($this->shortcuts)->render();
    }
}

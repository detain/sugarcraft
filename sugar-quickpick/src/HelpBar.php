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
 * Antecedent: the help line Genekkion/theHermit renders at the bottom of its
 * quick-fix overlay — rebuilt native here (this lib is a native PHP project,
 * not a member-by-member mirror; see README "Credits & inspiration").
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

    /**
     * @param array<string, string> $shortcuts key → description pairs
     *
     * Fail-fast ingress (-039): every key and every description must be a
     * string — the former `(string)` casts turned `null` into a selectable
     * empty legend entry and arrays into a PHP Warning plus the literal
     * 'Array'. Non-strings now throw, naming the offending entry.
     *
     * Post-guard key collisions are LAST-WRITE-WINS by documented design
     * (-064): two raw keys whose guarded spellings are byte-identical (e.g.
     * "a\x1b[2J" and "a") collapse to one entry keeping the later
     * description. Distinct visible legends always stay distinct; only
     * control-byte noise can collide, and refusing the pair outright would
     * make an invisible difference decide the API.
     */
    public function __construct(array $shortcuts = [], bool $visible = true)
    {
        foreach ($shortcuts as $key => $description) {
            if (!\is_string($key)) {
                throw new \InvalidArgumentException(\sprintf(
                    'HelpBar shortcut key must be a string, %s given',
                    \get_debug_type($key),
                ));
            }
            if (!\is_string($description)) {
                throw new \InvalidArgumentException(\sprintf(
                    'HelpBar shortcut description for key "%s" must be a string, %s given',
                    $key,
                    \get_debug_type($description),
                ));
            }
            // Legend text reaches the terminal verbatim — guard at ingress so
            // the separator glyphs and host styling added in render() survive.
            $this->shortcuts[UntrustedText::guard($key)] = UntrustedText::guard($description);
        }
        if ($visible === false) {
            $this->visible = false;
        }
    }

    /**
     * House factory (`::new()`), mirroring {@see QuickPick::new()}: same
     * signature as the constructor, which stays public (the docs promise a
     * bare construction site).
     */
    public static function new(array $shortcuts = [], bool $visible = true): self
    {
        return new self($shortcuts, $visible);
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

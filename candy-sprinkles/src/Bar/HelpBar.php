<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Bar;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Lang;
use SugarCraft\Sprinkles\Style;

/**
 * A themed single-line keyboard-shortcut help bar.
 *
 * Renders an ordered list of key → label pairs as
 * `key<keySeparator>label`, with entries joined by {@see separator()}.
 * Optional {@see keyStyle()} / {@see labelStyle()} theme the two halves.
 *
 * This is the shared owner primitive behind candy-hermit's HelpBar (and
 * the help rows the dash/crush status bars carry): each becomes a thin
 * themed wrapper over this class.
 *
 * Immutable + fluent — every setter returns a new instance via {@see mutate()}.
 */
final class HelpBar
{
    use Mutable;

    /**
     * @param list<array{key:string,label:string}> $entries
     */
    public function __construct(
        private readonly array $entries = [],
        private readonly string $keySeparator = ': ',
        private readonly string $separator = ' │ ',
        private readonly ?Style $keyStyle = null,
        private readonly ?Style $labelStyle = null,
        private readonly ?int $width = null,
        private readonly bool $visible = true,
    ) {}

    /** Empty, visible help bar with default separators. */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Build a help bar from a key → label map. Insertion order is
     * preserved; a repeated key overwrites the earlier label.
     *
     * @param array<string, string> $bindings
     */
    public static function fromMap(array $bindings): self
    {
        $entries = [];
        foreach ($bindings as $key => $label) {
            $entries[] = ['key' => (string) $key, 'label' => (string) $label];
        }
        return new self($entries);
    }

    // ─── Entries ────────────────────────────────────────────────────────

    /** Append a key → label entry. Duplicate keys are kept (ordered list). */
    public function add(string $key, string $label): self
    {
        return $this->mutate(['entries' => [...$this->entries, ['key' => $key, 'label' => $label]]]);
    }

    /**
     * Replace all entries from a key → label map.
     *
     * @param array<string, string> $bindings
     */
    public function bindings(array $bindings): self
    {
        $entries = [];
        foreach ($bindings as $key => $label) {
            $entries[] = ['key' => (string) $key, 'label' => (string) $label];
        }
        return $this->mutate(['entries' => $entries]);
    }

    /** Drop the first entry whose key matches. */
    public function without(string $key): self
    {
        $entries = [];
        $dropped = false;
        foreach ($this->entries as $entry) {
            if (!$dropped && $entry['key'] === $key) {
                $dropped = true;
                continue;
            }
            $entries[] = $entry;
        }
        return $this->mutate(['entries' => $entries]);
    }

    // ─── Layout knobs ───────────────────────────────────────────────────

    /** Separator between a key and its label (default `': '`). */
    public function keySeparator(string $separator): self
    {
        return $this->mutate(['keySeparator' => $separator]);
    }

    /** Separator between entries (default `' │ '`). */
    public function separator(string $separator): self
    {
        return $this->mutate(['separator' => $separator]);
    }

    /** Style applied to each key. Null renders keys raw. */
    public function keyStyle(?Style $style): self
    {
        return $this->mutate(['keyStyle' => $style]);
    }

    /** Style applied to each label. Null renders labels raw. */
    public function labelStyle(?Style $style): self
    {
        return $this->mutate(['labelStyle' => $style]);
    }

    /** Cap the rendered line to this width (ANSI-aware truncation). Null = uncapped. */
    public function width(?int $width): self
    {
        if ($width !== null && $width < 0) {
            throw new \InvalidArgumentException(Lang::t('style.width_nonneg'));
        }
        return $this->mutate(['width' => $width]);
    }

    /** Toggle visibility — a hidden bar renders to the empty string. */
    public function visible(bool $on = true): self
    {
        return $this->mutate(['visible' => $on]);
    }

    /** Alias for `visible(false)`. */
    public function hidden(): self
    {
        return $this->visible(false);
    }

    // ─── Accessors ──────────────────────────────────────────────────────

    /** @return list<array{key:string,label:string}> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function getKeySeparator(): string
    {
        return $this->keySeparator;
    }

    public function getSeparator(): string
    {
        return $this->separator;
    }

    public function getKeyStyle(): ?Style
    {
        return $this->keyStyle;
    }

    public function getLabelStyle(): ?Style
    {
        return $this->labelStyle;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    // ─── Render ─────────────────────────────────────────────────────────

    /**
     * Assemble the help bar into a single line. Returns the empty string
     * when hidden or when there are no entries.
     */
    public function render(): string
    {
        if (!$this->visible || $this->entries === []) {
            return '';
        }

        $parts = [];
        foreach ($this->entries as $entry) {
            $key   = $this->keyStyle !== null ? $this->keyStyle->render($entry['key']) : $entry['key'];
            $label = $this->labelStyle !== null ? $this->labelStyle->render($entry['label']) : $entry['label'];
            $parts[] = $key . $this->keySeparator . $label;
        }

        $line = implode($this->separator, $parts);

        if ($this->width !== null && Width::string($line) > $this->width) {
            $line = Width::truncateAnsi($line, $this->width);
        }

        return $line;
    }

    public function __toString(): string
    {
        return $this->render();
    }
}

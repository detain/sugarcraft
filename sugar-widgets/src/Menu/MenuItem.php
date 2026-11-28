<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Menu;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\Util\Sanitize;

/**
 * One entry of a {@see Menu} tree: a label, an optional id that a selection
 * reports back, and an optional list of child items. An item with children
 * is a branch and opens a cascade; an item with no children is a leaf and
 * is selectable.
 *
 * The label is sanitized to one line and stripped of zone sentinels, as
 * Confirm does for its prompt, so a hostile label never reaches the
 * terminal as an escape or a forged sugar-mouse marker. The id is reported
 * as given and never printed.
 *
 * Immutable: every `with*()` returns a new instance through `mutate()` from
 * sugar-core `Concerns\Mutable`.
 *
 * Original SugarCraft work: charmbracelet/bubbles has no menu component,
 * so there is no upstream Mirrors citation.
 */
final class MenuItem
{
    use Mutable;

    /**
     * @param list<MenuItem> $children
     */
    private function __construct(
        public readonly string $label,
        public readonly ?string $id,
        public readonly array $children,
    ) {
    }

    /**
     * @param array<MenuItem> $children non-MenuItem entries throw a \TypeError
     */
    public static function new(string $label, ?string $id = null, array $children = []): self
    {
        return new self(self::cleanLine($label), $id, self::listOf($children));
    }

    public function label(): string
    {
        return $this->label;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    /** @return list<MenuItem> */
    public function children(): array
    {
        return $this->children;
    }

    /** True when the item opens a cascade rather than being selectable. */
    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    public function withLabel(string $label): self
    {
        return $this->mutate(['label' => self::cleanLine($label)]);
    }

    public function withId(?string $id): self
    {
        return $this->mutate(['id' => $id]);
    }

    /**
     * @param array<MenuItem> $children an empty list makes the item a leaf
     */
    public function withChildren(array $children): self
    {
        return $this->mutate(['children' => self::listOf($children)]);
    }

    /**
     * Reindexes to a list and lets the variadic type check reject anything
     * that is not a MenuItem with a \TypeError naming the bad argument.
     *
     * @param array<mixed> $items
     * @return list<MenuItem>
     */
    public static function listOf(array $items): array
    {
        return (static fn (MenuItem ...$list): array => $list)(...array_values($items));
    }

    private static function cleanLine(string $text): string
    {
        return str_replace(["\r\n", "\n", "\r", "\t"], ' ', Sanitize::untrustedForMarkedFrames($text));
    }
}

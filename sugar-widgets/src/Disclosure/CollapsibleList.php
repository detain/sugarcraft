<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Disclosure;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Subscriptions;

/**
 * A vertical stack of {@see Disclosure} items under one cursor: each item
 * opens and closes on its own, and the cursor picks which one the keys act
 * on.
 *
 * While focused, Up or `k` and Down or `j` move the cursor (clamped, no
 * wrap), Home or `g` and End or `G` jump to the first and last item, and
 * the {@see Disclosure} keys act on the item under the cursor: Enter or
 * Space toggles, Right or `l` opens, Left or `h` closes. A key that opens or
 * closes an item returns a Cmd that sends a {@see DisclosureToggledMsg}
 * carrying the item's index; cursor moves return a null Cmd. Keys are read
 * only while focused, and a new list starts blurred with the cursor on the
 * first item.
 *
 * The list owns item focus: items are stored blurred, and {@see view()}
 * paints the item under the cursor in its focused style only while the
 * list itself is focused.
 *
 * Immutable: every `with*()`, {@see toggle()}, {@see focus()} and
 * {@see blur()} returns a new instance through the private `mutate()`,
 * except that {@see toggle()} on an empty list with no index has nothing to
 * flip and returns the same instance.
 *
 * Original SugarCraft work: charmbracelet/bubbles has no collapsible list
 * component, so there is no upstream Mirrors citation.
 */
final class CollapsibleList implements Model
{
    /**
     * @param list<Disclosure> $items
     */
    private function __construct(
        public readonly array $items,
        public readonly int $cursor,
        public readonly bool $focused,
    ) {
    }

    /**
     * @param array<Disclosure> $items Keys are dropped; order is kept.
     * @throws \InvalidArgumentException when an item is not a Disclosure
     */
    public static function new(array $items = []): self
    {
        return new self(self::normalize($items), 0, false);
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }

    /**
     * @return array{0: CollapsibleList, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        if (!$this->focused
            || !$msg instanceof KeyMsg
            || $msg instanceof KeyReleaseMsg
            || $this->items === []
        ) {
            return [$this, null];
        }

        $move = $this->cursorTarget($msg);
        if ($move !== null) {
            return [$this->withCursor($move), null];
        }

        $item = $this->items[$this->cursor];
        $target = Disclosure::targetState($msg, $item->open);
        if ($target === null || $target === $item->open) {
            return [$this, null];
        }
        $next = $this->withOpen($this->cursor, $target);
        return [$next, Cmd::send(new DisclosureToggledMsg($item->title, $target, $this->cursor))];
    }

    /** The cursor position a key asks for, or null when it is not a motion key. */
    private function cursorTarget(KeyMsg $msg): ?int
    {
        if ($msg->ctrl || $msg->alt) {
            return null;
        }
        $last = count($this->items) - 1;
        return match (true) {
            $msg->type === KeyType::Up, self::isRune($msg, 'k') => $this->cursor - 1,
            $msg->type === KeyType::Down, self::isRune($msg, 'j') => $this->cursor + 1,
            $msg->type === KeyType::Home, self::isRune($msg, 'g') => 0,
            $msg->type === KeyType::End, self::isRune($msg, 'G') => $last,
            default => null,
        };
    }

    public function view(): string
    {
        $rows = [];
        foreach ($this->items as $i => $item) {
            $rows[] = $item->withFocused($this->focused && $i === $this->cursor)->view();
        }
        return implode("\n", $rows);
    }

    public function __toString(): string
    {
        return $this->view();
    }

    /** @return list<Disclosure> */
    public function items(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    /** The item under the cursor, or null for an empty list. */
    public function selected(): ?Disclosure
    {
        return $this->items[$this->cursor] ?? null;
    }

    /** The item at `$index`, or null when out of range. */
    public function item(int $index): ?Disclosure
    {
        return $this->items[$index] ?? null;
    }

    /** Whether the item at `$index` is open; false when out of range. */
    public function isOpen(int $index): bool
    {
        return ($this->items[$index] ?? null)?->open ?? false;
    }

    /** @return list<int> Indices of the open items, in order. */
    public function openIndices(): array
    {
        $open = [];
        foreach ($this->items as $i => $item) {
            if ($item->open) {
                $open[] = $i;
            }
        }
        return $open;
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    /**
     * Replace the items; the cursor is clamped into the new range.
     *
     * @param array<Disclosure> $items
     * @throws \InvalidArgumentException when an item is not a Disclosure
     */
    public function withItems(array $items): self
    {
        $items = self::normalize($items);
        return $this->mutate(items: $items, cursor: self::clamp($this->cursor, count($items)));
    }

    /** Move the cursor; out-of-range positions clamp to the first or last item. */
    public function withCursor(int $cursor): self
    {
        return $this->mutate(cursor: self::clamp($cursor, count($this->items)));
    }

    public function withFocused(bool $focused = true): self
    {
        return $this->mutate(focused: $focused);
    }

    public function focus(): self
    {
        return $this->withFocused(true);
    }

    public function blur(): self
    {
        return $this->withFocused(false);
    }

    /**
     * Open or close the item at `$index`.
     *
     * @throws \OutOfRangeException when `$index` names no item
     */
    public function withOpen(int $index, bool $open = true): self
    {
        $item = $this->items[$index] ?? throw new \OutOfRangeException(sprintf(
            'CollapsibleList has no item %d (count %d)',
            $index,
            count($this->items),
        ));
        $items = $this->items;
        $items[$index] = $item->withOpen($open);
        return $this->mutate(items: $items);
    }

    /** Open or close every item. */
    public function withAllOpen(bool $open = true): self
    {
        return $this->mutate(items: array_map(
            static fn (Disclosure $item): Disclosure => $item->withOpen($open),
            $this->items,
        ));
    }

    /**
     * Flip the item at `$index`, or the item under the cursor when null.
     * An empty list has nothing under the cursor and comes back unchanged.
     *
     * @throws \OutOfRangeException when an explicit `$index` names no item
     */
    public function toggle(?int $index = null): self
    {
        if ($index === null && $this->items === []) {
            return $this;
        }
        $index ??= $this->cursor;
        return $this->withOpen($index, !$this->isOpen($index));
    }

    private static function isRune(KeyMsg $msg, string $rune): bool
    {
        return $msg->type === KeyType::Char && $msg->rune === $rune;
    }

    private static function clamp(int $cursor, int $count): int
    {
        return $count === 0 ? 0 : max(0, min($cursor, $count - 1));
    }

    /**
     * @param array<mixed> $items
     * @return list<Disclosure>
     */
    private static function normalize(array $items): array
    {
        $out = [];
        foreach ($items as $key => $item) {
            if (!$item instanceof Disclosure) {
                throw new \InvalidArgumentException(sprintf(
                    'CollapsibleList item %s must be a %s, got %s',
                    var_export($key, true),
                    Disclosure::class,
                    get_debug_type($item),
                ));
            }
            // The list owns focus; a stored focused item would paint as
            // focused regardless of the cursor.
            $out[] = $item->blur();
        }
        return $out;
    }

    /**
     * @param list<Disclosure>|null $items
     */
    private function mutate(?array $items = null, ?int $cursor = null, ?bool $focused = null): self
    {
        return new self(
            items: $items ?? $this->items,
            cursor: $cursor ?? $this->cursor,
            focused: $focused ?? $this->focused,
        );
    }
}

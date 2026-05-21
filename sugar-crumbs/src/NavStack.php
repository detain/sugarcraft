<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs;

/**
 * Navigation stack — last-in, first-out navigation state.
 *
 * Push new items to go deeper; pop to go back up.
 * The top of the stack is the "current" screen/section.
 *
 * ## Click dispatch (step 10.21)
 *
 * When a {@see \SugarCraft\Zone\MsgZoneInBounds} is received for a crumb
 * zone (e.g. `crumb-0`, `crumb-1`), the parent component should dispatch
 * to this stack's `pushDirectory()` / `view()` — the actual wiring is
 * implemented in step 10.21.
 *
 * Port of KevM/bubbleo NavStack.
 *
 * @see https://github.com/KevM/bubbleo
 */
final class NavStack
{
    /** @var list<NavigationItem> */
    private array $items = [];

    /**
     * Push a new navigation item onto the stack.
     */
    public function push(string $title, mixed $data = null): self
    {
        $this->items[] = new NavigationItem($title, $data);
        return $this;
    }

    /**
     * Pop the topmost item off the stack and return it.
     * Returns null if the stack is empty.
     */
    public function pop(): ?NavigationItem
    {
        if ($this->items === []) {
            return null;
        }
        return \array_pop($this->items);
    }

    /**
     * Peek at the top item without removing it.
     */
    public function current(): ?NavigationItem
    {
        return $this->items[\count($this->items) - 1] ?? null;
    }

    /**
     * Peek at the item below the top.
     */
    public function parent(): ?NavigationItem
    {
        $n = \count($this->items);
        return $n >= 2 ? $this->items[$n - 2] : null;
    }

    /**
     * Current stack depth (number of items).
     */
    public function depth(): int
    {
        return \count($this->items);
    }

    /**
     * Is the stack empty?
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Get all items as a list.
     *
     * @return list<NavigationItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Replace the data on the topmost item.
     * Does nothing if stack is empty.
     */
    public function updateTop(mixed $data): self
    {
        if ($this->items === []) return $this;
        $top = $this->items[\count($this->items) - 1];
        $this->items[\count($this->items) - 1] = new NavigationItem($top->title, $data);
        return $this;
    }

    /**
     * Clear the stack completely.
     */
    public function clear(): self
    {
        $this->items = [];
        return $this;
    }

    /**
     * Replace all items at once (used by Shell for immutable operations).
     *
     * @param list<NavigationItem> $items
     */
    public function setItems(array $items): self
    {
        $this->items = $items;
        return $this;
    }

    /**
     * Render the navigation stack as a breadcrumb string.
     * e.g. "Home > Settings > Display"
     */
    public function view(string $separator = ' > '): string
    {
        if ($this->items === []) {
            return '';
        }
        return \implode($separator, \array_map(
            static fn(NavigationItem $item): string => $item->title,
            $this->items
        ));
    }

    /**
     * Filter items by title or data matching $term.
     * Returns a new NavStack with only matching items.
     */
    public function filter(string $term): self
    {
        $filtered = \array_filter(
            $this->items,
            static function(NavigationItem $item) use ($term): bool {
                $titleMatch = \stripos($item->title, $term) !== false;
                $dataMatch = $item->data !== null && \stripos((string) $item->data, $term) !== false;
                return $titleMatch || $dataMatch;
            }
        );
        $new = new self();
        $new->items = \array_values($filtered);
        return $new;
    }
}

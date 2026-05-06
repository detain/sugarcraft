<?php

declare(strict_types=1);

namespace CandyCore\Crumbs;

/**
 * Navigation stack — last-in, first-out navigation state.
 *
 * Push new items to go deeper; pop to go back up.
 * The top of the stack is the "current" screen/section.
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
}

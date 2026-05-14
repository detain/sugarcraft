<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

/**
 * Manages focus state for a layout hierarchy.
 */
final class FocusManager
{
    /**
     * @var array<string, bool>
     */
    private array $focusMap = [];

    private ?string $focusedId = null;

    public function __construct(
        private readonly string $rootId = 'root',
    ) {
        $this->focusMap[$rootId] = true;
    }

    public function focus(string $id): self
    {
        $clone = clone $this;
        $clone->focusedId = $id;
        $clone->focusMap[$id] = true;
        return $clone;
    }

    public function blur(string $id): self
    {
        $clone = clone $this;
        $clone->focusMap[$id] = false;
        if ($clone->focusedId === $id) {
            $clone->focusedId = null;
        }
        return $clone;
    }

    public function isFocused(string $id): bool
    {
        return $this->focusedId === $id && ($this->focusMap[$id] ?? false);
    }

    public function getFocusedId(): ?string
    {
        return $this->focusedId;
    }

    public function focusNext(): self
    {
        $ids = array_keys($this->focusMap);
        if ($ids === []) {
            return $this;
        }

        $currentIndex = $this->focusedId !== null
            ? array_search($this->focusedId, $ids, true)
            : -1;

        $nextIndex = ($currentIndex + 1) % count($ids);
        return $this->focus($ids[$nextIndex]);
    }

    public function focusPrevious(): self
    {
        $ids = array_keys($this->focusMap);
        if ($ids === []) {
            return $this;
        }

        $currentIndex = $this->focusedId !== null
            ? array_search($this->focusedId, $ids, true)
            : 0;

        $prevIndex = $currentIndex > 0 ? $currentIndex - 1 : count($ids) - 1;
        return $this->focus($ids[$prevIndex]);
    }

    public function register(string $id): self
    {
        if (isset($this->focusMap[$id])) {
            return $this;
        }
        $clone = clone $this;
        $clone->focusMap[$id] = false;
        return $clone;
    }

    public function unregister(string $id): self
    {
        $clone = clone $this;
        unset($clone->focusMap[$id]);
        if ($clone->focusedId === $id) {
            $clone->focusedId = null;
        }
        return $clone;
    }
}

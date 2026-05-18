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

    /**
     * Set focus to a panel.
     *
     * Note: $id is not type-hinted as string because PHP array_keys()
     * returns int for numeric string keys. Callers must cast to string
     * before passing, or this method will coerce internally.
     */
    public function focus(string|int $id): self
    {
        $key = (string) $id;
        $clone = clone $this;
        $clone->focusedId = $key;
        $clone->focusMap[$key] = true;
        return $clone;
    }

    public function blur(string $id): self
    {
        $clone = clone $this;
        $clone->focusMap[(string) $id] = false;
        if ($clone->focusedId === $id) {
            $clone->focusedId = null;
        }
        return $clone;
    }

    public function isFocused(string $id): bool
    {
        return $this->focusedId === (string) $id && ($this->focusMap[(string) $id] ?? false);
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

    public function register(string|int $id): self
    {
        $key = (string) $id;
        if (isset($this->focusMap[$key])) {
            return $this;
        }
        $clone = clone $this;
        $clone->focusMap[$key] = false;
        return $clone;
    }

    public function unregister(string|int $id): self
    {
        $key = (string) $id;
        $clone = clone $this;
        unset($clone->focusMap[$key]);
        if ($clone->focusedId === $key) {
            $clone->focusedId = null;
        }
        return $clone;
    }
}

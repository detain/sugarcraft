<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

use SugarCraft\Dash\State\Persistence;

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

        $currentIndex = -1;
        if ($this->focusedId !== null) {
            $idx = array_search($this->focusedId, $ids, true);
            if ($idx !== false) {
                $currentIndex = $idx;
            }
        }

        $nextIndex = ($currentIndex + 1) % count($ids);
        return $this->focus($ids[$nextIndex]);
    }

    public function focusPrevious(): self
    {
        $ids = array_keys($this->focusMap);
        if ($ids === []) {
            return $this;
        }

        $currentIndex = 0;
        if ($this->focusedId !== null) {
            $idx = array_search($this->focusedId, $ids, true);
            if ($idx !== false) {
                $currentIndex = $idx;
            }
        }

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

    // ─── Persistence ───────────────────────────────────────────────

    /**
     * Save focus state to disk via the persistence layer.
     *
     * Persists focusedId and the focusMap so panel focus survives restart.
     */
    public function persistState(Persistence $persistence, string $path): void
    {
        $persistence->save($path, [
            'focusedId' => $this->focusedId,
            'focusMap' => $this->focusMap,
        ]);
    }

    /**
     * Restore focus state from disk.
     *
     * Returns a new FocusManager with restored state, or $this if no
     * persisted state exists.
     */
    public function restoreState(Persistence $persistence, string $path): self
    {
        $data = $persistence->load($path);
        if ($data === null) {
            return $this;
        }

        $clone = clone $this;
        $clone->focusedId = $data['focusedId'] ?? null;
        $clone->focusMap = $data['focusMap'] ?? [$this->rootId => true];
        return $clone;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Focus;

/**
 * An immutable focus ring: an ordered set of focusable region ids with a single
 * focused member, plus Tab/Shift-Tab traversal that wraps around.
 *
 * It is the app-wide "which panel has focus" state for a TUI layout — register
 * each focusable region (sidebar, grid, filter bar…), map Tab to {@see next()}
 * and Shift-Tab to {@see previous()}, and style the region {@see current()}
 * names with an accent border. The ring holds no rendering or key-decoding of
 * its own (so it has no dependencies); the owning model wires keys to it and
 * reads {@see isFocused()} when drawing.
 *
 * Invariant: a non-empty ring always has exactly one focused region; an empty
 * ring focuses nothing ({@see current()} is null, {@see index()} is -1). Every
 * mutator returns a new instance and leaves the receiver untouched.
 *
 * Mirrors the focus-traversal role of charmbracelet/bubbles' focus handling and
 * sugar-dash's FocusManager, but as a standalone, dependency-free, ordered ring.
 */
final class FocusRing
{
    /**
     * @param list<string> $ids   registered region ids, in traversal order
     * @param int          $index focused position into $ids, or -1 when empty
     */
    private function __construct(
        private readonly array $ids,
        private readonly int $index,
    ) {
    }

    /** An empty ring with nothing registered or focused. */
    public static function new(): self
    {
        return new self([], -1);
    }

    /**
     * A ring of the given region ids in traversal order (duplicates dropped,
     * first occurrence wins), focusing the first one.
     */
    public static function of(string ...$ids): self
    {
        $unique = [];
        foreach ($ids as $id) {
            if (!in_array($id, $unique, true)) {
                $unique[] = $id;
            }
        }

        return new self($unique, $unique === [] ? -1 : 0);
    }

    /**
     * Register a region at the end of the traversal order. A no-op (returns the
     * same ring) if it is already registered. Registering into an empty ring
     * focuses the new region.
     */
    public function register(string $id): self
    {
        if (in_array($id, $this->ids, true)) {
            return $this;
        }

        $ids = $this->ids;
        $ids[] = $id;

        return new self($ids, $this->index === -1 ? 0 : $this->index);
    }

    /**
     * Remove a region. A no-op if it was not registered. If the removed region
     * was focused, focus shifts to the region that took its slot (or the new
     * last region, or nothing when the ring becomes empty); focus otherwise
     * stays on the same region.
     */
    public function unregister(string $id): self
    {
        $pos = array_search($id, $this->ids, true);
        if ($pos === false) {
            return $this;
        }

        $ids = $this->ids;
        array_splice($ids, $pos, 1);

        if ($ids === []) {
            return new self([], -1);
        }

        $index = $this->index;
        if ($pos < $index) {
            // A region before the focused one shifted left by one.
            --$index;
        } elseif ($pos === $index) {
            // The focused region went away; keep the slot, clamped to the end.
            $index = min($index, count($ids) - 1);
        }

        return new self($ids, $index);
    }

    /**
     * Focus a specific registered region. A no-op if it is not registered or is
     * already focused.
     */
    public function focus(string $id): self
    {
        $pos = array_search($id, $this->ids, true);
        if ($pos === false || $pos === $this->index) {
            return $this;
        }

        return new self($this->ids, $pos);
    }

    /** Move focus to the next region (Tab), wrapping past the end to the start. */
    public function next(): self
    {
        if (count($this->ids) < 2) {
            return $this;
        }

        return new self($this->ids, ($this->index + 1) % count($this->ids));
    }

    /** Move focus to the previous region (Shift-Tab), wrapping past the start. */
    public function previous(): self
    {
        $count = count($this->ids);
        if ($count < 2) {
            return $this;
        }

        return new self($this->ids, ($this->index - 1 + $count) % $count);
    }

    /** The focused region id, or null when the ring is empty. */
    public function current(): ?string
    {
        return $this->ids[$this->index] ?? null;
    }

    public function isFocused(string $id): bool
    {
        return $this->current() === $id;
    }

    public function has(string $id): bool
    {
        return in_array($id, $this->ids, true);
    }

    /** The focused position, or -1 when the ring is empty. */
    public function index(): int
    {
        return $this->index;
    }

    /** @return list<string> registered region ids in traversal order */
    public function ids(): array
    {
        return $this->ids;
    }

    public function count(): int
    {
        return count($this->ids);
    }

    public function isEmpty(): bool
    {
        return $this->ids === [];
    }
}

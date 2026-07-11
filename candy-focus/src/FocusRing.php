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
final class FocusRing implements \IteratorAggregate, \JsonSerializable
{
    /**
     * Positions into $ids of the enabled regions, in ascending order. Cached so
     * the hot-path traversal ({@see next()}/{@see previous()}) never rebuilds it
     * per keystroke; maintained incrementally by every mutator that touches $ids
     * or $disabled (register/unregister/enable/disable), and recomputed only when
     * the order changes wholesale ({@see reorder()}) or a caller omits it.
     *
     * @var list<int>
     */
    private readonly array $enabledPositions;

    /**
     * @param list<string>            $ids               registered region ids, in traversal order
     * @param int                     $index             focused position into $ids, or -1 when empty
     * @param array<string, true>     $disabled          set of disabled region ids
     * @param list<int>|null          $enabledPositions  precomputed enabled positions; null recomputes them
     */
    private function __construct(
        private readonly array $ids,
        private readonly int $index,
        private readonly array $disabled = [],
        ?array $enabledPositions = null,
    ) {
        // Invariant: empty ring => index -1; non-empty ring => index in [0, count)
        assert($ids === [] ? $index === -1 : ($index >= 0 && $index < count($ids)), 'FocusRing focus index out of range for ids');

        $this->enabledPositions = $enabledPositions ?? self::computeEnabledPositions($ids, $disabled);

        // A supplied enabledPositions is trusted on the hot path (asserts are
        // stripped in production); in dev/tests this guards every mutator's
        // incremental maintenance against the freshly-computed truth.
        assert(
            $this->enabledPositions === self::computeEnabledPositions($ids, $disabled),
            'FocusRing enabledPositions out of sync with ids/disabled',
        );
    }

    /**
     * The positions into $ids whose region is not disabled, ascending. Kept in
     * one place so the constructor's recompute path and the incremental
     * maintenance below agree byte-for-byte.
     *
     * @param list<string>        $ids
     * @param array<string, true> $disabled
     * @return list<int>
     */
    private static function computeEnabledPositions(array $ids, array $disabled): array
    {
        $positions = [];
        foreach ($ids as $i => $id) {
            if (!isset($disabled[$id])) {
                $positions[] = $i;
            }
        }

        return $positions;
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
     * A strict variant of {@see of()} that throws on duplicate ids instead of
     * silently dropping them. Useful when callers want to enforce uniqueness.
     *
     * Mirrors charmbracelet/bubbles focus handling — ofStrict() is the
     * non-silent-failure companion to of().
     *
     * @param list<string> $ids region ids in traversal order
     * @throws \InvalidArgumentException when a duplicate id is provided
     */
    public static function ofStrict(string ...$ids): self
    {
        $seen = [];
        foreach ($ids as $id) {
            if (in_array($id, $seen, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Duplicate region id "%s" passed to FocusRing::ofStrict()',
                    $id,
                ));
            }
            $seen[] = $id;
        }

        return new self($seen, $seen === [] ? -1 : 0);
    }

    /**
     * Register a region at the end of the traversal order. A no-op (returns the
     * same ring) if it is already registered. Registering into an empty ring
     * focuses the new region. A newly registered region is always enabled.
     */
    public function register(string $id): self
    {
        if (in_array($id, $this->ids, true)) {
            return $this;
        }

        // The new region lands at the current end and is always enabled, so its
        // position is the old count and appends to the (ascending) enabled list.
        $newPos = count($this->ids);

        $ids = $this->ids;
        $ids[] = $id;

        // Drop from disabled map if present (re-registration re-enables)
        $disabled = $this->disabled;
        unset($disabled[$id]);

        $enabledPositions = $this->enabledPositions;
        $enabledPositions[] = $newPos;

        return new self($ids, $this->index === -1 ? 0 : $this->index, $disabled, $enabledPositions);
    }

    /**
     * Remove a region. A no-op if it was not registered. If the removed region
     * was focused, focus shifts to the region that took its slot (or the new
     * last region, or nothing when the ring becomes empty); focus otherwise
     * stays on the same region. The disabled flag for the removed id is cleared.
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

        // Drop disabled flag for the removed id
        $disabled = $this->disabled;
        unset($disabled[$id]);

        // Drop the removed slot from the enabled list and shift everything that
        // sat after it left by one, mirroring array_splice() on $ids.
        $enabledPositions = [];
        foreach ($this->enabledPositions as $p) {
            if ($p < $pos) {
                $enabledPositions[] = $p;
            } elseif ($p > $pos) {
                $enabledPositions[] = $p - 1;
            }
        }

        return new self($ids, $index, $disabled, $enabledPositions);
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

        // Only $index moves; $ids and $disabled are untouched, so the cache carries over.
        return new self($this->ids, $pos, $this->disabled, $this->enabledPositions);
    }

    /**
     * Replace the traversal order while keeping focus on the current region id
     * when it survives the dedupe. Acts as a batch set-replace — ids not
     * previously registered are added; ids missing from the new list are dropped.
     *
     * Semantics:
     * 1. Dedupe incoming ids (first-wins), mirroring of()'s contract.
     * 2. Empty result = empty ring.
     * 3. If current() survives, focus stays on it at its new position;
     *    otherwise focus the first element (index 0).
     * 4. If the deduped list is identical to $this->ids and index unchanged,
     *    return $this (no-op fast-path).
     *
     * Use-case: dynamic layout where the set of regions changes without
     * wanting to rebuild the ring from scratch.
     */
    public function reorder(string ...$ids): self
    {
        // 1. Dedupe incoming ids (first-wins)
        $unique = [];
        foreach ($ids as $id) {
            if (!in_array($id, $unique, true)) {
                $unique[] = $id;
            }
        }

        // 2. Empty result = empty ring
        if ($unique === []) {
            return new self([], -1);
        }

        // 3. Compute new index: preserve current() if it survives, else 0
        $currentId = $this->current();
        $newIndex = array_search($currentId, $unique, true);
        if ($newIndex === false) {
            $newIndex = 0;
        }

        // 4. No-op fast-path
        if ($unique === $this->ids && $newIndex === $this->index) {
            return $this;
        }

        return new self($unique, $newIndex, $this->disabled);
    }

    /** Move focus to the next enabled region (Tab), wrapping past the end. Disabled regions are skipped. Disabling the focused region does not move focus; it is left in place and the next traversal carries it off. */
    public function next(): self
    {
        if (count($this->ids) < 2) {
            return $this;
        }

        // Enabled positions are maintained incrementally — no per-keystroke rebuild.
        $enabledPositions = $this->enabledPositions;

        // All-disabled: noOp
        if ($enabledPositions === []) {
            return $this;
        }

        // Sole enabled: wrap would land on self — noOp
        if (count($enabledPositions) === 1) {
            return $this;
        }

        // Find current position among enabled; if current is disabled, find next enabled from current
        $currentEnabledIdx = array_search($this->index, $enabledPositions, true);
        if ($currentEnabledIdx === false) {
            // Current region is disabled — find the first enabled after current
            $total = count($this->ids);
            for ($offset = 1; $offset <= $total; $offset++) {
                $candidate = ($this->index + $offset) % $total;
                if (!isset($this->disabled[$this->ids[$candidate]])) {
                    return new self($this->ids, $candidate, $this->disabled, $enabledPositions);
                }
            }
            return $this; // Should not reach: we have ≥2 enabled, one must be findable
        }

        // Wrap-around to next enabled
        $nextIdx = ($currentEnabledIdx + 1) % count($enabledPositions);

        return new self($this->ids, $enabledPositions[$nextIdx], $this->disabled, $enabledPositions);
    }

    /** Move focus to the previous enabled region (Shift-Tab), wrapping. Disabled regions are skipped. Disabling the focused region does not move focus; it is left in place and the next traversal carries it off. */
    public function previous(): self
    {
        if (count($this->ids) < 2) {
            return $this;
        }

        // Enabled positions are maintained incrementally — no per-keystroke rebuild.
        $enabledPositions = $this->enabledPositions;

        // All-disabled: noOp
        if ($enabledPositions === []) {
            return $this;
        }

        // Sole enabled: wrap would land on self — noOp
        if (count($enabledPositions) === 1) {
            return $this;
        }

        $currentEnabledIdx = array_search($this->index, $enabledPositions, true);
        if ($currentEnabledIdx === false) {
            // Current region is disabled — find the first enabled before current
            $total = count($this->ids);
            for ($offset = 1; $offset <= $total; $offset++) {
                $candidate = ($this->index - $offset + $total) % $total;
                if (!isset($this->disabled[$this->ids[$candidate]])) {
                    return new self($this->ids, $candidate, $this->disabled, $enabledPositions);
                }
            }
            return $this;
        }

        // Wrap-around to previous enabled
        $prevIdx = ($currentEnabledIdx - 1 + count($enabledPositions)) % count($enabledPositions);

        return new self($this->ids, $enabledPositions[$prevIdx], $this->disabled, $enabledPositions);
    }

    /** Disable a region so next()/previous() skip over it. Disabling the currently focused region does not move focus — it is a pure metadata change so disable() never causes surprising focus jumps. */
    public function disable(string $id): self
    {
        if (!in_array($id, $this->ids, true) || isset($this->disabled[$id])) {
            return $this;
        }

        $disabled = $this->disabled;
        $disabled[$id] = true;

        // The id was registered-and-enabled (guarded above), so its position is
        // in the cache; drop it while preserving ascending order.
        $pos = array_search($id, $this->ids, true);
        $enabledPositions = array_values(array_filter(
            $this->enabledPositions,
            static fn (int $p): bool => $p !== $pos,
        ));

        return new self($this->ids, $this->index, $disabled, $enabledPositions);
    }

    /** Re-enable a previously disabled region. A no-op if the id is not registered or is already enabled. */
    public function enable(string $id): self
    {
        if (!in_array($id, $this->ids, true) || !isset($this->disabled[$id])) {
            return $this;
        }

        $disabled = $this->disabled;
        unset($disabled[$id]);

        // Re-insert the newly enabled position, restoring ascending order.
        $pos = array_search($id, $this->ids, true);
        $enabledPositions = $this->enabledPositions;
        $enabledPositions[] = $pos;
        sort($enabledPositions);

        return new self($this->ids, $this->index, $disabled, $enabledPositions);
    }

    /** @return bool true when the region is registered and not disabled */
    public function isEnabled(string $id): bool
    {
        return in_array($id, $this->ids, true) && !isset($this->disabled[$id]);
    }

    /** @return list<string> ids of all enabled regions in traversal order */
    public function enabledIds(): array
    {
        return array_values(array_filter(
            $this->ids,
            fn (string $id): bool => !isset($this->disabled[$id]),
        ));
    }

    /** @return list<string> ids of all disabled regions in traversal order */
    public function disabledIds(): array
    {
        return array_values(array_filter(
            $this->ids,
            fn (string $id): bool => isset($this->disabled[$id]),
        ));
    }

    /** Zero-cost enabled region count (avoids array allocation of enabledIds()). */
    public function enabledCount(): int
    {
        return count($this->ids) - count($this->disabled);
    }

    /** Zero-cost disabled region count. */
    public function disabledCount(): int
    {
        return count($this->disabled);
    }

    /** @return \Traversable<int, string> Yields region ids in traversal order */
    public function getIterator(): \Traversable
    {
        yield from $this->ids;
    }

    public function jsonSerialize(): array
    {
        return [
            'ids' => $this->ids,
            'index' => $this->index,
            'disabled' => array_keys($this->disabled),
        ];
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
        return array_values($this->ids);
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

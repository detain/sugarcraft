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
final class FocusRing implements \Countable, \IteratorAggregate, \JsonSerializable
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
        // Invariant: only registered ids can carry a disabled flag, otherwise the
        // counts, enabledIds()/disabledIds() and jsonSerialize() disagree.
        assert(
            array_diff_key($disabled, array_fill_keys($ids, true)) === [],
            'FocusRing disabled set names an unregistered id',
        );

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

    /**
     * Drop duplicate ids, first occurrence wins, preserving order. One hash-set
     * pass rather than an in_array() scan per id, shared by every factory and
     * {@see reorder()} so they cannot drift on the dedupe contract.
     *
     * @param array<array-key, string> $ids
     * @return list<string>
     */
    private static function unique(array $ids): array
    {
        $seen = [];
        $unique = [];
        foreach ($ids as $id) {
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $unique[] = $id;
            }
        }

        return $unique;
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
        $unique = self::unique($ids);

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
        $unique = self::unique($ids);
        if (count($unique) !== count($ids)) {
            // Name the first id that repeats, so the caller can find the clash.
            $seen = [];
            foreach ($ids as $id) {
                if (isset($seen[$id])) {
                    throw new \InvalidArgumentException(sprintf(
                        'Duplicate region id "%s" passed to FocusRing::ofStrict()',
                        $id,
                    ));
                }
                $seen[$id] = true;
            }
        }

        return new self($unique, $unique === [] ? -1 : 0);
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
     * Focus a specific registered, ENABLED region. A no-op if it is not
     * registered, is disabled, or is already focused.
     *
     * Refusing disabled ids (audit 2026-10-07 ruling) keeps the two ways focus
     * moves consistent: next()/previous() skip disabled regions, so focus()
     * must not be a teleport past the same wall. Focus stays parkable ON a
     * disabled region the other way round — disable() never moves an existing
     * focus (see next()/previous() docs), and snapshot restore focuses ids
     * before re-applying their disabled flags, which this order keeps working.
     */
    public function focus(string $id): self
    {
        $pos = array_search($id, $this->ids, true);
        if ($pos === false || $pos === $this->index || isset($this->disabled[$id])) {
            return $this;
        }

        return $this->withIndex($pos);
    }

    /**
     * Replace the traversal order while keeping focus on the current region id
     * when it survives the dedupe. Acts as a batch set-replace — ids not
     * previously registered are added; ids missing from the new list are dropped.
     *
     * Semantics:
     * 1. Dedupe incoming ids (first-wins), mirroring of()'s contract.
     * 2. Empty result = empty ring.
     * 3. If current() survives, focus stays on it at its new position. When it
     *    was dropped, focus lands on the first ENABLED survivor in ring order —
     *    the fallback must not teleport onto a disabled head, the same wall
     *    {@see focus()} refuses (audit 010c58c67). Only when EVERY survivor is
     *    disabled does it park at index 0: a non-empty ring always focuses
     *    exactly one region, and focus-on-a-disabled-region is a legitimate
     *    parked state in this model ({@see disable()} never moves focus).
     * 4. Disabled flags carry over for surviving ids only. A dropped id loses
     *    its flag exactly as {@see unregister()} clears it, and an id added by
     *    the new list is enabled exactly as {@see register()} enables it — so a
     *    region dropped while disabled and later re-added comes back enabled.
     * 5. If the deduped list is identical to $this->ids and index unchanged,
     *    return $this (no-op fast-path).
     *
     * Use-case: dynamic layout where the set of regions changes without
     * wanting to rebuild the ring from scratch.
     */
    public function reorder(string ...$ids): self
    {
        $unique = self::unique($ids);

        if ($unique === []) {
            return new self([], -1);
        }

        // Flags carry over for survivors only, computed before the fallback so it
        // can honour the enabled wall.
        $disabled = array_intersect_key($this->disabled, array_fill_keys($unique, true));

        $newIndex = array_search($this->current(), $unique, true);
        if ($newIndex === false) {
            // The focused region was dropped. Skip past any disabled head onto
            // the first enabled survivor; an exhaustively disabled survivor set
            // has nowhere enabled to go, so index 0 parks on a disabled region
            // exactly as disable() would have left it.
            $enabled = self::computeEnabledPositions($unique, $disabled);
            $newIndex = $enabled === [] ? 0 : $enabled[0];
        }

        if ($unique === $this->ids && $newIndex === $this->index) {
            return $this;
        }

        return new self($unique, $newIndex, $disabled);
    }

    /**
     * Move focus to the next enabled region (Tab), wrapping past the end.
     * Disabled regions are skipped. Disabling the focused region does not move
     * focus; it is left in place and the next traversal carries it off — onto
     * the nearest enabled region after it, even when that is the only enabled
     * region left. A no-op (returns the same ring) when focus already sits on
     * the only enabled region, or when no region is enabled.
     */
    public function next(): self
    {
        return $this->step(1);
    }

    /**
     * Move focus to the previous enabled region (Shift-Tab), wrapping past the
     * start. Disabled regions are skipped. Disabling the focused region does not
     * move focus; it is left in place and the next traversal carries it off —
     * onto the nearest enabled region before it, even when that is the only
     * enabled region left. A no-op (returns the same ring) when focus already
     * sits on the only enabled region, or when no region is enabled.
     */
    public function previous(): self
    {
        return $this->step(-1);
    }

    /**
     * The single traversal routine behind {@see next()} (+1) and
     * {@see previous()} (-1), so the two directions cannot drift apart.
     */
    private function step(int $direction): self
    {
        // Enabled positions are maintained incrementally — no per-keystroke rebuild.
        $enabled = $this->enabledPositions;
        $enabledCount = count($enabled);

        if ($enabledCount === 0) {
            return $this;
        }

        $at = array_search($this->index, $enabled, true);

        if ($at === false) {
            // Focus is parked on a disabled region. Any enabled region —
            // including a sole survivor — is a legitimate landing spot, so this
            // branch must run before the sole-enabled no-op below.
            return $this->withIndex($this->nearestEnabled($direction));
        }

        if ($enabledCount === 1) {
            // Focus already sits on the only enabled region; every other region
            // is disabled, so there is nowhere else to go.
            return $this;
        }

        return $this->withIndex($enabled[($at + $direction + $enabledCount) % $enabledCount]);
    }

    /**
     * The first enabled position strictly after (direction +1) or before
     * (direction -1) the focused one, wrapping. Only called while the focused
     * region is disabled and at least one region is enabled, so a match exists.
     */
    private function nearestEnabled(int $direction): int
    {
        $enabled = $this->enabledPositions;

        if ($direction > 0) {
            foreach ($enabled as $p) {
                if ($p > $this->index) {
                    return $p;
                }
            }

            return $enabled[0];
        }

        for ($i = count($enabled) - 1; $i >= 0; $i--) {
            if ($enabled[$i] < $this->index) {
                return $enabled[$i];
            }
        }

        return $enabled[count($enabled) - 1];
    }

    /** A copy focused at $index; $ids and $disabled are untouched, so the cache carries over. */
    private function withIndex(int $index): self
    {
        return new self($this->ids, $index, $this->disabled, $this->enabledPositions);
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
        return count($this->enabledPositions);
    }

    /** Zero-cost disabled region count. */
    public function disabledCount(): int
    {
        return count($this->ids) - count($this->enabledPositions);
    }

    /** @return \Traversable<int, string> Yields region ids in traversal order */
    public function getIterator(): \Traversable
    {
        yield from $this->ids;
    }

    /**
     * Snapshot for session persistence: the ids in traversal order, the focused
     * index, and the disabled ids in traversal order. Disabled ids are read from
     * $ids rather than from the keys of the disabled set, because PHP coerces a
     * numeric-string key such as "1" to int(1) — the snapshot must stay a list
     * of strings so it can be fed straight back into {@see disable()}.
     *
     * @return array{ids: list<string>, index: int, disabled: list<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'ids' => $this->ids,
            'index' => $this->index,
            'disabled' => $this->disabledIds(),
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

    /** Number of registered regions (enabled and disabled); backs count($ring). */
    public function count(): int
    {
        return count($this->ids);
    }

    public function isEmpty(): bool
    {
        return $this->ids === [];
    }
}

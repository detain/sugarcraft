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
 * ring focuses nothing ({@see current()} is null, {@see index()} is -1). The
 * focused region MAY be disabled — a parked state that disable() leaves behind
 * and unregister() falls back to when every survivor is disabled; see
 * {@see isFocused()} before painting a focus accent. Every mutator returns a
 * new instance and leaves the receiver untouched.
 *
 * Mirrors the focus-traversal role of charmbracelet/bubbles' focus handling and
 * sugar-dashboard's FocusManager, but as a standalone, dependency-free, ordered ring.
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
        assert(
            $ids === [] ? $index === -1 : ($index >= 0 && $index < count($ids)),
            sprintf('FocusRing focus index %d is out of range for %d registered region(s)', $index, count($ids)),
        );
        // Invariant: only registered ids can carry a disabled flag, otherwise the
        // counts, enabledIds()/disabledIds() and jsonSerialize() disagree.
        assert(
            array_diff_key($disabled, array_fill_keys($ids, true)) === [],
            sprintf(
                'FocusRing disabled set names unregistered id(s): %s',
                implode(', ', array_map('strval', array_keys(array_diff_key($disabled, array_fill_keys($ids, true))))),
            ),
        );

        $this->enabledPositions = $enabledPositions ?? self::computeEnabledPositions($ids, $disabled);

        // A supplied enabledPositions is trusted on the hot path: released PHP
        // ships zend.assertions=-1, which compiles every assert() away (and the
        // setting is INI_SYSTEM — no phpunit.xml can raise it), so this guard
        // fires only on assertion-enabled builds. The check that holds on every
        // runtime is assertCacheConsistent() in tests/FocusRingTest.php, run
        // against each mutator's result.
        assert(
            $this->enabledPositions === self::computeEnabledPositions($ids, $disabled),
            sprintf(
                'FocusRing enabledPositions %s disagree with computed %s',
                json_encode($this->enabledPositions),
                json_encode(self::computeEnabledPositions($ids, $disabled)),
            ),
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

    /**
     * A caller-supplied id made safe to embed in an exception message: every
     * C0 control byte (`\x00..\x1f` — ESC, BEL, newlines…), DEL (`\x7f`) and
     * raw C1 byte (`\x80..\x9f` — a bare CSI `\x9b` followed by `H` is a
     * complete cursor-motion sequence) becomes a printable octal escape, and
     * absurd lengths are clipped, so a hostile id cannot forge terminal
     * output through an error the host prints verbatim.
     */
    private static function describeId(string $id): string
    {
        // addcslashes walks bytes, so this also catches the second byte of a
        // UTF-8-encoded C1 (\xc2\x80..\xc2\x9f): the pair splits and the lone
        // lead byte that remains is inert — the forgery is neutralised either way.
        $id = addcslashes($id, "\x00..\x1f\x7f\x80..\x9f");

        return strlen($id) > 80 ? substr($id, 0, 79) . '...' : $id;
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
     * @param string ...$ids region ids in traversal order
     * @throws \InvalidArgumentException when a duplicate id is provided
     */
    public static function ofStrict(string ...$ids): self
    {
        $unique = self::unique($ids);
        if (count($unique) !== count($ids)) {
            // Name the first id that repeats, so the caller can find the clash.
            // describeId() keeps that caller-supplied text from smuggling raw
            // control bytes into the terminal-rendered error.
            $seen = [];
            foreach ($ids as $id) {
                if (isset($seen[$id])) {
                    throw new \InvalidArgumentException(sprintf(
                        'Duplicate region id "%s" passed to FocusRing::ofStrict()',
                        self::describeId($id),
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

        // No disabled-flag cleanup is needed here: the id is not registered
        // (guard above) and the constructor invariant confines flags to
        // registered ids, so no flag for it can exist — a fresh region is
        // enabled by construction. Re-enabling a dropped region happens via
        // unregister() then register(), never by re-register alone.
        $enabledPositions = $this->enabledPositions;
        $enabledPositions[] = $newPos;

        return new self($ids, $this->index === -1 ? 0 : $this->index, $this->disabled, $enabledPositions);
    }

    /**
     * Remove a region. A no-op if it was not registered. If the removed region
     * was focused, focus lands on the nearest ENABLED survivor at or after the
     * vacated slot (wrapping to the first enabled region at the end) — the wall
     * that next() skips must not strand the next keystroke. Only when EVERY
     * survivor is disabled does focus keep the slot (clamped to the new end):
     * the parked state, which the next traversal carries off. Focus otherwise
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
        }

        // Drop disabled flag for the removed id
        $disabled = $this->disabled;
        unset($disabled[$id]);

        // Drop the removed slot from the enabled list and shift everything that
        // sat after it left by one, mirroring array_splice() on $ids. Done
        // before the landing decision so that reads the survivors' truth.
        $enabledPositions = [];
        foreach ($this->enabledPositions as $p) {
            if ($p < $pos) {
                $enabledPositions[] = $p;
            } elseif ($p > $pos) {
                $enabledPositions[] = $p - 1;
            }
        }

        if ($pos === $this->index) {
            // The focused region went away: land on the nearest enabled
            // survivor at or after the vacated slot, wrapping around; with
            // nothing enabled left, keep the slot (clamped to the new end).
            $slot = min($pos, count($ids) - 1);
            $index = $slot;
            if ($enabledPositions !== []) {
                $landing = $enabledPositions[0];
                foreach ($enabledPositions as $p) {
                    if ($p >= $slot) {
                        $landing = $p;
                        break;
                    }
                }
                $index = $landing;
            }
        }

        return new self($ids, $index, $disabled, $enabledPositions);
    }

    /**
     * Focus a specific registered, ENABLED region. A no-op if it is not
     * registered, is disabled, or is already focused.
     *
     * Refusing disabled ids keeps the two ways focus
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
     * Focus the first ENABLED region in traversal order — disabled heads are
     * skipped. A no-op when nothing is enabled, or when focus already sits
     * there.
     */
    public function focusFirst(): self
    {
        if ($this->enabledPositions === []) {
            return $this;
        }

        return $this->focus($this->ids[$this->enabledPositions[0]]);
    }

    /**
     * Focus the last ENABLED region in traversal order — disabled tails are
     * skipped. A no-op when nothing is enabled, or when focus already sits
     * there.
     */
    public function focusLast(): self
    {
        if ($this->enabledPositions === []) {
            return $this;
        }

        return $this->focus($this->ids[$this->enabledPositions[count($this->enabledPositions) - 1]]);
    }

    /**
     * Replace the traversal order while keeping focus on the current region id
     * when it survives the dedupe. Acts as a batch set-replace — ids not
     * previously registered are added; ids missing from the new list are dropped.
     *
     * Semantics:
     * 1. Dedupe incoming ids (first-wins), mirroring of()'s contract.
     * 2. Empty result = empty ring (a no-op returning $this when the ring was
     *    already empty — every refusal in this class keeps the instance).
     * 3. If current() survives, focus stays on it at its new position. When it
     *    was dropped, focus lands on the first ENABLED survivor in ring order —
     *    the fallback must not teleport onto a disabled head, the same wall
     *    {@see focus()} refuses. Only when EVERY survivor is
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
            // Emptying an already-empty ring changes nothing: identity, like
            // every other no-op in this class.
            return $this->ids === [] ? $this : new self([], -1);
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
     * Pure query: the id focus would land on stepping forward from $id,
     * without moving focus. Mirrors next() including its no-op shape — an id
     * with nowhere enabled to go answers with itself (even when focus parked
     * on a disabled region). null when the id is not registered.
     */
    public function nextOf(string $id): ?string
    {
        $pos = array_search($id, $this->ids, true);
        if ($pos === false) {
            return null;
        }

        return $this->ids[$this->landOn($pos, 1)];
    }

    /**
     * Pure query: the id focus would land on stepping backward from $id,
     * without moving focus. Mirrors previous(); see {@see nextOf()}.
     */
    public function previousOf(string $id): ?string
    {
        $pos = array_search($id, $this->ids, true);
        if ($pos === false) {
            return null;
        }

        return $this->ids[$this->landOn($pos, -1)];
    }

    /**
     * The single traversal routine behind {@see next()} (+1) and
     * {@see previous()} (-1), so the two directions cannot drift apart.
     */
    private function step(int $direction): self
    {
        // The enabled list is maintained incrementally — no per-keystroke
        // rebuild; locating the focused slot is one linear scan over it, a
        // handful of entries at real pane counts.
        $landing = $this->landOn($this->index, $direction);

        return $landing === $this->index ? $this : $this->withIndex($landing);
    }

    /**
     * Where traversal stepping by $direction would land if focus sat at
     * $from. Shared by step() and the pure queries {@see nextOf()} /
     * {@see previousOf()}; returns $from when there is nowhere to go (no
     * enabled region, or focus already sits on the only enabled one).
     */
    private function landOn(int $from, int $direction): int
    {
        $enabled = $this->enabledPositions;
        $enabledCount = count($enabled);

        if ($enabledCount === 0) {
            return $from;
        }

        $at = array_search($from, $enabled, true);

        if ($at === false) {
            // $from is parked on a disabled region (or off-list). Any enabled
            // region — including a sole survivor — is a legitimate landing
            // spot, so this branch must run before the sole-enabled no-op.
            return self::nearestEnabledPosition($enabled, $from, $direction);
        }

        if ($enabledCount === 1) {
            // Focus already sits on the only enabled region; every other region
            // is disabled, so there is nowhere else to go.
            return $from;
        }

        return $enabled[($at + $direction + $enabledCount) % $enabledCount];
    }

    /**
     * The first enabled position strictly after (direction +1) or before
     * (direction -1) $from, wrapping. Only called with a non-empty $enabled
     * list, so a match always exists.
     *
     * @param list<int> $enabled
     */
    private static function nearestEnabledPosition(array $enabled, int $from, int $direction): int
    {
        assert($enabled !== [], 'FocusRing::nearestEnabledPosition() requires at least one enabled region');

        if ($direction > 0) {
            foreach ($enabled as $p) {
                if ($p > $from) {
                    return $p;
                }
            }

            return $enabled[0];
        }

        for ($i = count($enabled) - 1; $i >= 0; $i--) {
            if ($enabled[$i] < $from) {
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
        // One lookup answers both "registered" and "where"; the old in_array +
        // array_search pair scanned $ids twice per call.
        $pos = array_search($id, $this->ids, true);
        if ($pos === false || isset($this->disabled[$id])) {
            return $this;
        }

        $disabled = $this->disabled;
        $disabled[$id] = true;

        // The id was registered-and-enabled (guarded above), so its position is
        // in the cache; drop it while preserving ascending order.
        $enabledPositions = array_values(array_filter(
            $this->enabledPositions,
            static fn (int $p): bool => $p !== $pos,
        ));

        return new self($this->ids, $this->index, $disabled, $enabledPositions);
    }

    /** Re-enable a previously disabled region. A no-op if the id is not registered or is already enabled. */
    public function enable(string $id): self
    {
        $pos = array_search($id, $this->ids, true);
        if ($pos === false || !isset($this->disabled[$id])) {
            return $this;
        }

        $disabled = $this->disabled;
        unset($disabled[$id]);

        // Walk into place: ascending order restored by one backward scan instead
        // of append + sort. The single array_search above also closes the old
        // typing hole — registration is proven here, so $pos flows on as the int
        // leg of array_search()'s int|false return and the false branch can no
        // longer be pushed into the list<int> cache before sort() erased it.
        $enabledPositions = $this->enabledPositions;
        $at = count($enabledPositions);
        while ($at > 0 && $enabledPositions[$at - 1] > $pos) {
            $at--;
        }
        array_splice($enabledPositions, $at, 0, [$pos]);

        return new self($this->ids, $this->index, $disabled, $enabledPositions);
    }

    /**
     * True when the region is registered and not disabled. False conflates
     * unregistered with disabled — use {@see has()} to tell them apart.
     *
     * @return bool true when the region is registered and not disabled
     */
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
     * region (as both an id and a position), and the disabled ids in traversal
     * order. Disabled ids are read from $ids rather than from the keys of the
     * disabled set, because PHP coerces a numeric-string key such as "1" to
     * int(1) — the snapshot must stay a list of strings so {@see restore()} can
     * consume it verbatim.
     *
     * @return array{ids: list<string>, focused: ?string, index: int, disabled: list<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'ids' => $this->ids,
            // 'focused' carries the id — restore() prefers it because it stays
            // correct across a re-ordered survivor set; 'index' remains for
            // readers that only ever consumed the positional leg.
            'focused' => $this->current(),
            'index' => $this->index,
            'disabled' => $this->disabledIds(),
        ];
    }

    /**
     * Rebuild a ring from a {@see jsonSerialize()} snapshot — the inverse of the
     * persistence format.
     *
     * Nothing here trusts the file: "ids" must be a list of strings (otherwise
     * InvalidArgumentException), the focus leg is CLAMPED rather than consumed
     * raw — a stale index from a shorter session can never TypeError the
     * constructor at startup — and disabled entries that are not strings or not
     * registered are dropped fail-soft (a region that no longer exists carries
     * no flag to restore).
     *
     * Resolution order for focus: a string "focused" id wins (id-keyed,
     * drift-proof); an unknown focused id leaves the first region focused.
     * The positional "index" is consulted only when no usable focus leg is
     * present at all (legacy snapshots), and then clamped into range. An
     * empty ids list focuses nothing either way.
     *
     * @param array<array-key, mixed> $snapshot the decoded shape from json_encode()
     * @throws \InvalidArgumentException when "ids" is not a list of strings
     */
    public static function restore(array $snapshot): self
    {
        $rawIds = $snapshot['ids'] ?? [];
        if (!is_array($rawIds) || !array_is_list($rawIds)) {
            throw new \InvalidArgumentException('FocusRing::restore() requires "ids" to be a list of strings');
        }

        foreach ($rawIds as $offset => $id) {
            if (!is_string($id)) {
                throw new \InvalidArgumentException(sprintf(
                    'FocusRing::restore() requires "ids" to be a list of strings, offset %d is %s',
                    $offset,
                    get_debug_type($id),
                ));
            }
        }

        $ids = self::unique($rawIds);
        $count = count($ids);

        $target = $count === 0 ? -1 : 0;
        $focused = $snapshot['focused'] ?? null;
        if (is_string($focused)) {
            $pos = array_search($focused, $ids, true);
            if ($pos !== false) {
                $target = $pos;
            }
        } elseif ($count > 0 && isset($snapshot['index']) && is_int($snapshot['index'])) {
            // Legacy positional snapshot: clamp it, never trust it.
            $target = max(0, min($snapshot['index'], $count - 1));
        }

        $disabled = [];
        $rawDisabled = $snapshot['disabled'] ?? [];
        if (is_array($rawDisabled)) {
            // A non-iterable top-level list is corruption, not a flag set:
            // degrade to the spec rather than warning out of foreach().
            foreach ($rawDisabled as $id) {
                if (is_string($id) && in_array($id, $ids, true)) {
                    $disabled[$id] = true;
                }
            }
        }

        return new self($ids, $target, $disabled);
    }

    /** The focused region id, or null when the ring is empty. */
    public function current(): ?string
    {
        return $this->ids[$this->index] ?? null;
    }

    /**
     * Whether $id is the focused member. CAUTION for painters: focus can
     * legally PARK on a disabled region — disable() never moves an existing
     * focus, and unregister() lands on the vacated slot when every survivor is
     * disabled — so draw a focus accent only when
     * isFocused($id) && isEnabled($id) both hold.
     */
    public function isFocused(string $id): bool
    {
        return $this->current() === $id;
    }

    public function has(string $id): bool
    {
        return in_array($id, $this->ids, true);
    }

    /**
     * The focused position into the REGISTERED ids (disabled regions count
     * too), or -1 when the ring is empty. For "region k of n" among the
     * focusable ones use {@see enabledIndex()} with {@see enabledCount()}.
     */
    public function index(): int
    {
        return $this->index;
    }

    /**
     * The focused region's 0-based ordinal into {@see enabledIds()} — the "k"
     * of "region k of n" for a status line. null when the ring is empty or
     * focus is parked on a disabled region, cases where index() counts every
     * registered region and cannot answer.
     */
    public function enabledIndex(): ?int
    {
        $at = array_search($this->index, $this->enabledPositions, true);

        return $at === false ? null : $at;
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

<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout;

use SugarCraft\Dashboard\State\Persistence;
use SugarCraft\Focus\FocusRing;

/**
 * Manages focus state for a layout hierarchy.
 *
 * A thin persistence wrapper over sugar-focus's {@see FocusRing}: the ring owns
 * the ordered set of focusable ids and the wrap-around Tab/Shift-Tab traversal,
 * while this class layers on the dashboard's own conventions — a nullable
 * "nothing focused yet" state (a fresh manager seeds the root id but focuses
 * nothing), a per-id focused-history flag ({@see $focusMap}), and durable
 * save/restore. The traversal arithmetic that used to live here is delegated to
 * the ring so the two ports share one implementation.
 */
final class FocusManager
{
    /**
     * Per-id "has been focused and not since blurred" flag. Distinct from the
     * ring's single current member: several ids can be true at once (focus()
     * never resets a prior id to false). Kept as the on-disk persistence shape
     * and consulted by {@see isFocused()} as a secondary gate.
     *
     * @var array<string, bool>
     */
    private array $focusMap = [];

    private ?string $focusedId = null;

    /** Last restoreState() skip/repair reason, or null when the restore was clean. */
    private ?string $restoreNotice = null;

    /** Ordered focusable-id set + wrap-around traversal, mirrors array_keys($focusMap). */
    private FocusRing $ring;

    public function __construct(
        private readonly string $rootId = 'root',
    ) {
        $this->focusMap[$rootId] = true;
        $this->ring = FocusRing::of($rootId);
    }

    /**
     * Move focus to a REGISTERED panel.
     *
     * Registration belongs to register()/unregister() — focus and blur only
     * move state (finding 127). The old shape grew the traversal set through
     * focus() itself, so tab order silently became first-touched order and
     * focus(-1) minted a phantom slot named '-1'; such a call is now refused
     * (no-op return) instead of mutating the ring.
     */
    public function focus(string|int $id): self
    {
        $key = (string) $id;
        if (!$this->ring->has($key)) {
            return $this;
        }

        $clone = clone $this;
        $clone->focusedId = $key;
        $clone->focusMap[$key] = true;
        return $clone;
    }

    /**
     * Drop the focused flag for a REGISTERED panel.
     *
     * Blurring an id that was never registered is a no-op — it must not seed
     * a ghost tab-stop (finding 127: the old blur INSERTED into the ring,
     * the opposite of intent through the door meant to shrink focus).
     */
    public function blur(string $id): self
    {
        $key = (string) $id;
        if (!$this->ring->has($key)) {
            return $this;
        }

        $clone = clone $this;
        $clone->focusMap[$key] = false;
        if ($clone->focusedId === $key) {
            $clone->focusedId = null;
        }
        return $clone;
    }

    public function isFocused(string $id): bool
    {
        return $this->focusedId === (string) $id && ($this->focusMap[(string) $id] ?? false);
    }

    public function focusedId(): ?string
    {
        return $this->focusedId;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::focusedId()} instead. REMOVED-IN: 2.0.0.
     */
    public function getFocusedId(): ?string { return $this->focusedId(); }

    public function focusNext(): self
    {
        $ids = $this->ring->ids();
        if ($ids === []) {
            return $this;
        }

        // A null (or stale) focused id behaves like index -1: the next step
        // lands on the first registered id.
        if ($this->focusedId === null || !$this->ring->has($this->focusedId)) {
            return $this->focus($ids[0]);
        }

        // The ring's own cursor is never advanced by these wrappers (focus()
        // only moves the manager's state now), so the query re-anchors to the
        // authoritative focusedId first — peekNext answers what the old
        // mover+current() shape computed.
        $next = $this->ring->focus($this->focusedId)->peekNext();
        return $this->focus($next ?? $ids[0]);
    }

    public function focusPrevious(): self
    {
        $ids = $this->ring->ids();
        if ($ids === []) {
            return $this;
        }

        // A null (or stale) focused id behaves like index 0: stepping back
        // wraps to the last registered id.
        if ($this->focusedId === null || !$this->ring->has($this->focusedId)) {
            return $this->focus($ids[count($ids) - 1]);
        }

        $prev = $this->ring->focus($this->focusedId)->peekPrevious();
        return $this->focus($prev ?? $ids[count($ids) - 1]);
    }

    public function register(string|int $id): self
    {
        $key = (string) $id;
        if (isset($this->focusMap[$key])) {
            return $this;
        }
        $clone = clone $this;
        $clone->focusMap[$key] = false;
        $clone->ring = $this->ring->register($key);
        return $clone;
    }

    public function unregister(string|int $id): self
    {
        $key = (string) $id;
        $clone = clone $this;
        unset($clone->focusMap[$key]);
        $clone->ring = $this->ring->unregister($key);
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
     * The ring is derived from the focusMap keys on restore, so it is not
     * written separately.
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
     * Returns a new FocusManager with restored state, or $this if no persisted
     * state exists. Fail-soft by contract (finding 127, the D-08
     * disable-with-reason doctrine ported to focus state): a version
     * mismatch or unreadable shape never throws through init — the restore is
     * skipped or repaired, and {@see restoreNotice()} carries the honest
     * reason for callers that want to surface it.
     */
    public function restoreState(Persistence $persistence, string $path): self
    {
        try {
            $data = $persistence->load($path);
        } catch (\RuntimeException $e) {
            $skipped = clone $this;
            $skipped->restoreNotice = 'restore skipped: ' . $e->getMessage();
            return $skipped;
        }

        if ($data === null) {
            return $this;
        }

        $rawMap = $data['focusMap'] ?? null;
        if (!is_array($rawMap)) {
            $skipped = clone $this;
            $skipped->restoreNotice = 'restore skipped: focusMap is not an object in ' . $path;
            return $skipped;
        }

        $reasons = [];
        $map = [];
        foreach ($rawMap as $key => $value) {
            // Array keys are int|string by PHP's own cast — the value is the
            // corruptible slot: only the bool polarity the manager stores.
            if (!is_bool($value)) {
                $reasons[] = "entry '{$key}' is not a bool";
                continue;
            }
            $map[(string) $key] = $value;
        }

        $clone = clone $this;
        $clone->focusMap = $map;
        // Rebuild the traversal ring from the surviving ids (registration
        // order preserved); numeric-string keys are normalised back to strings.
        $clone->ring = FocusRing::of(...array_map(strval(...), array_keys($map)));

        $focusedId = $data['focusedId'] ?? null;
        if ($focusedId === null) {
            $clone->focusedId = null;
        } elseif ((is_string($focusedId) || is_int($focusedId)) && isset($map[(string) $focusedId])) {
            $clone->focusedId = (string) $focusedId;
        } else {
            $clone->focusedId = null;
            $reasons[] = "focusedId is not a registered panel";
        }

        if ($reasons !== []) {
            $clone->restoreNotice = 'restore repaired: ' . implode('; ', $reasons);
        }

        return $clone;
    }

    /**
     * Why the last restoreState() skipped or repaired something, or null if
     * the last restore was clean. The reason is surfaced, not swallowed —
     * the chrome or log line that shows it is the consumer's call.
     */
    public function restoreNotice(): ?string
    {
        return $this->restoreNotice;
    }
}

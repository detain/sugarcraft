<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

use SugarCraft\Dash\State\Persistence;
use SugarCraft\Focus\FocusRing;

/**
 * Manages focus state for a layout hierarchy.
 *
 * A thin persistence wrapper over candy-focus's {@see FocusRing}: the ring owns
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

    /** Ordered focusable-id set + wrap-around traversal, mirrors array_keys($focusMap). */
    private FocusRing $ring;

    public function __construct(
        private readonly string $rootId = 'root',
    ) {
        $this->focusMap[$rootId] = true;
        $this->ring = FocusRing::of($rootId);
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
        // Focusing an unregistered id extends the traversal set (mirrors the
        // focusMap append); an already-registered id is a ring no-op.
        $clone->ring = $this->ring->register($key);
        return $clone;
    }

    public function blur(string $id): self
    {
        $key = (string) $id;
        $clone = clone $this;
        $clone->focusMap[$key] = false;
        $clone->ring = $this->ring->register($key);
        if ($clone->focusedId === $key) {
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
        $ids = $this->ring->ids();
        if ($ids === []) {
            return $this;
        }

        // A null (or stale) focused id behaves like index -1: the next step
        // lands on the first registered id.
        if ($this->focusedId === null || !$this->ring->has($this->focusedId)) {
            return $this->focus($ids[0]);
        }

        $next = $this->ring->focus($this->focusedId)->next()->current();
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

        $prev = $this->ring->focus($this->focusedId)->previous()->current();
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
        // Rebuild the traversal ring from the restored ids (registration order
        // preserved); numeric-string keys are normalised back to strings.
        $clone->ring = FocusRing::of(...array_map(strval(...), array_keys($clone->focusMap)));
        return $clone;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Toast;

/**
 * Dual-ring notification queue per Homedash pattern.
 *
 * - items[max 20]  — active, dismissable ring.
 * - history[max 50] — append-only ring.
 *
 * Uses two-slice semantics (not a true ring buffer) — appropriate for
 * the small max sizes. New items push onto items; dismissing moves the
 * head to history. Both rings evict oldest entries when full.
 *
 * The capacity invariant (`count(items) <= maxItems`,
 * `count(history) <= maxHistory`) holds for every reachable instance:
 * caps are clamped to >= 1 at the constructor boundary and the
 * `with*()` setters trim the rings into any newly lowered cap.
 */
final class NotificationQueue
{
    /**
     * Maximum active notifications kept in the items ring (>= 1).
     */
    private readonly int $maxItems;

    /**
     * Maximum historical notifications kept in the history ring (>= 1).
     */
    private readonly int $maxHistory;

    /**
     * @var list<Notification>
     */
    private array $items;

    /**
     * @var list<Notification>
     */
    private array $history;

    public function __construct(int $maxItems = 20, int $maxHistory = 50)
    {
        $this->maxItems = max(1, $maxItems);
        $this->maxHistory = max(1, $maxHistory);
        $this->items = [];
        $this->history = [];
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * Push a notification onto the items ring.
     *
     * If items is at capacity, the oldest item is evicted to history
     * as the new one joins.
     */
    public function push(Notification $notification): self
    {
        $clone = $this->mutate();
        $clone->items[] = $notification;
        $clone->trimItemsToCap();

        return $clone;
    }

    /**
     * Dismiss the head of the items ring, moving it to history.
     *
     * Returns a new instance. If items is empty, returns same instance.
     */
    public function dismiss(): self
    {
        $dismissed = $this->current();
        if ($dismissed === null) {
            return $this;
        }

        $clone = $this->mutate();
        array_shift($clone->items);
        $clone->history[] = $dismissed;
        $clone->trimHistoryToCap();

        return $clone;
    }

    /**
     * Return the head of the items ring, or null if empty.
     */
    public function current(): ?Notification
    {
        return $this->items[0] ?? null;
    }

    /**
     * Return the last $n notifications from history, newest-first.
     *
     * @return list<Notification>
     */
    public function recent(int $n): array
    {
        if ($n <= 0 || $this->history === []) {
            return [];
        }

        $count = min($n, count($this->history));
        return array_slice(array_reverse($this->history), 0, $count);
    }

    /**
     * Return all active items.
     *
     * @return list<Notification>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Return all history items, oldest-first.
     *
     * @return list<Notification>
     */
    public function history(): array
    {
        return $this->history;
    }

    /**
     * Return the number of active items.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Return the number of history items.
     */
    public function historyCount(): int
    {
        return count($this->history);
    }

    /**
     * Check if the items ring is empty.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Check if history has any entries.
     */
    public function hasHistory(): bool
    {
        return $this->history !== [];
    }

    /**
     * New instance carrying both rings under a different items cap.
     *
     * Lowering the cap below the live count evicts the oldest active
     * items into history — the same overflow route push() takes — so
     * the capacity invariant survives the resize.
     */
    public function withMaxItems(int $maxItems): self
    {
        $clone = $this->mutate(maxItems: $maxItems);
        $clone->trimItemsToCap();

        return $clone;
    }

    /**
     * New instance carrying both rings under a different history cap.
     *
     * Lowering the cap drops the oldest history entries (there is no
     * third ring to demote them into).
     */
    public function withMaxHistory(int $maxHistory): self
    {
        $clone = $this->mutate(maxHistory: $maxHistory);
        $clone->trimHistoryToCap();

        return $clone;
    }

    private function mutate(?int $maxItems = null, ?int $maxHistory = null): self
    {
        $clone = new self(
            maxItems: $maxItems ?? $this->maxItems,
            maxHistory: $maxHistory ?? $this->maxHistory,
        );
        $clone->items = $this->items;
        $clone->history = $this->history;

        return $clone;
    }

    /**
     * Move oldest active items into history until the items cap holds.
     */
    private function trimItemsToCap(): void
    {
        while (count($this->items) > $this->maxItems) {
            $this->history[] = array_shift($this->items);
        }

        $this->trimHistoryToCap();
    }

    /**
     * Drop oldest history entries until the history cap holds.
     */
    private function trimHistoryToCap(): void
    {
        while (count($this->history) > $this->maxHistory) {
            array_shift($this->history);
        }
    }
}

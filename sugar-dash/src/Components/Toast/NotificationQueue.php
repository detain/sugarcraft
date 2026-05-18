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
 */
final class NotificationQueue
{
    /**
     * @var list<Notification>
     */
    private array $items;

    /**
     * @var list<Notification>
     */
    private array $history;

    public function __construct(
        /**
         * Maximum active notifications kept in the items ring.
         */
        private readonly int $maxItems = 20,
        /**
         * Maximum historical notifications kept in the history ring.
         */
        private readonly int $maxHistory = 50,
    ) {
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
     * before the new one is added.
     */
    public function push(Notification $notification): self
    {
        $clone = $this->mutate();

        if (count($clone->items) >= $this->maxItems) {
            $evicted = array_shift($clone->items);
            if ($evicted !== null) {
                $clone->history[] = $evicted;
                if (count($clone->history) > $clone->maxHistory) {
                    array_shift($clone->history);
                }
            }
        }

        $clone->items[] = $notification;

        return $clone;
    }

    /**
     * Dismiss the head of the items ring, moving it to history.
     *
     * Returns a new instance. If items is empty, returns same instance.
     */
    public function dismiss(): self
    {
        if ($this->items === []) {
            return $this;
        }

        $clone = $this->mutate();
        $dismissed = array_shift($clone->items);

        if ($dismissed !== null) {
            $clone->history[] = $dismissed;
            if (count($clone->history) > $clone->maxHistory) {
                array_shift($clone->history);
            }
        }

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
     * Create a new instance with a different maxItems.
     */
    public function withMaxItems(int $maxItems): self
    {
        $clone = $this->mutate();
        return new self(
            maxItems: $maxItems,
            maxHistory: $clone->maxHistory,
        );
    }

    /**
     * Create a new instance with a different maxHistory.
     */
    public function withMaxHistory(int $maxHistory): self
    {
        $clone = $this->mutate();
        return new self(
            maxItems: $clone->maxItems,
            maxHistory: $maxHistory,
        );
    }

    private function mutate(): self
    {
        $clone = new self(
            maxItems: $this->maxItems,
            maxHistory: $this->maxHistory,
        );
        $clone->items = $this->items;
        $clone->history = $this->history;
        return $clone;
    }
}

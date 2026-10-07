<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

/**
 * Immutable log of dismissed alerts.
 *
 * Records the alerts that dismiss() moves out of the queue so callers can
 * inspect what was shown and then cleared. Kept bounded via the $limit
 * argument of {@see push()} (driven by Toast::withHistoryLimit()).
 */
final class HistoryLog
{
    /**
     * @param list<Alert> $entries  Dismissed alerts in chronological order
     */
    public function __construct(
        private readonly array $entries = [],
    ) {}

    /**
     * Append an alert, returning a new log instance.
     *
     * When $limit is set and the log would exceed it, the OLDEST entries are
     * evicted first (Overflow::DropOldest semantics) so the newest $limit
     * survive. $limit = null keeps every entry — unbounded is an explicit,
     * supported choice, mirrored by Toast::withHistoryLimit(null).
     *
     * @param int|null $limit  Maximum entries to retain (null = unbounded)
     */
    public function push(Alert $alert, ?int $limit = null): self
    {
        $entries = [...$this->entries, $alert];
        if ($limit !== null && \count($entries) > $limit) {
            $entries = \array_slice($entries, \count($entries) - $limit);
        }
        return new self($entries);
    }

    /**
     * Return all recorded alerts.
     *
     * @return list<Alert>
     */
    public function all(): array
    {
        return $this->entries;
    }

    /**
     * Return the number of recorded entries.
     */
    public function count(): int
    {
        return \count($this->entries);
    }
}

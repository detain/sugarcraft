<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

/**
 * Immutable log of dismissed alerts.
 *
 * Records every alert that passes through dismiss() so callers can inspect
 * what was shown and then cleared.
 */
final class HistoryLog
{
    /**
     * @param list<Alert> $entries  All dismissed alerts in chronological order
     */
    public function __construct(
        private readonly array $entries = [],
    ) {}

    /**
     * Append an alert, returning a new log instance.
     */
    public function push(Alert $alert): self
    {
        return new self([...$this->entries, $alert]);
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

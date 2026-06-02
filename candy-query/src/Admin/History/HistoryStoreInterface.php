<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\History;

use SugarCraft\Query\Admin\StatusSnapshot;

/**
 * Persistence layer for historical StatusSnapshot records.
 *
 * Allows saving snapshots for later rate analysis over multi-hour windows,
 * independent of the live polling cadence.
 */
interface HistoryStoreInterface
{
    /**
     * Persist a single snapshot.
     */
    public function save(StatusSnapshot $snapshot): void;

    /**
     * Retrieve snapshots within a time range (inclusive).
     *
     * @return array<StatusSnapshot>
     */
    public function query(\DateTimeImmutable $since, \DateTimeImmutable $until): array;

    /**
     * Total number of stored snapshots.
     */
    public function count(): int;

    /**
     * Delete all snapshots older than the cutoff.
     *
     * @return int Number of records deleted
     */
    public function prune(\DateTimeImmutable $before): int;

    /**
     * Release any held resources (e.g. close the SQLite database).
     */
    public function close(): void;
}

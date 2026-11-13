<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin\History;

use SugarCraft\Sql\Admin\StatusSnapshot;

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
     * Boundary-only read (SQL-P02): the oldest and newest snapshots in the
     * range, ascending, without materializing the window. One row when the
     * window holds a single snapshot; empty when none.
     *
     * @return list<StatusSnapshot>
     */
    public function queryBoundaries(\DateTimeImmutable $since, \DateTimeImmutable $until): array;

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

<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Table;

/**
 * Row-reader contract consumed by {@see Table::data()}. Any data
 * source — in-memory matrix, lazy iterator, database row-walker —
 * implements `rows()` / `columns()` / `at()` to feed Table.
 *
 * Mirrors lipgloss's `Data` interface. Implementations are expected
 * to be stable across multiple `at()` calls for the same coordinates;
 * Table reads each cell once on `data()` and snapshots the result.
 *
 * Most callers should reach for {@see StringData} — the default
 * 2D-array implementation that ships in the same namespace.
 */
interface Data
{
    /** Total row count (excluding any header). */
    public function rows(): int;

    /** Column count. Should be uniform across rows. */
    public function columns(): int;

    /**
     * Cell content at `($row, $col)`. Coordinates are 0-based. Out-of-
     * range coordinates may return an empty string or throw — callers
     * should respect {@see rows()} / {@see columns()} bounds.
     */
    public function at(int $row, int $col): string;
}

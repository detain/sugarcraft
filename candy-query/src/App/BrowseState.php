<?php

declare(strict_types=1);

namespace SugarCraft\Query\App;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Query\ResultTable;

/**
 * Table/rows-browsing slice of the {@see \SugarCraft\Query\App} model: the
 * table list + cursor, the selected table's row preview, and the query-result
 * grid. Grouped out of App's constructor per plan 3.3.
 */
final class BrowseState
{
    use Mutable;

    /**
     * @param list<string> $tables
     * @param list<array<string,mixed>> $rows
     */
    public function __construct(
        public readonly array $tables = [],
        public readonly int $tableCursor = 0,
        public readonly ?string $selectedTable = null,
        public readonly array $rows = [],
        public readonly int $rowCursor = 0,
        public readonly ?ResultTable $resultTable = null,
        public readonly bool $rowsLoading = false,
    ) {}

    public static function new(): self
    {
        return new self();
    }

    /** @param list<string> $tables */
    public function withTables(array $tables): self
    {
        return $this->mutate(['tables' => $tables]);
    }

    public function withTableCursor(int $tableCursor): self
    {
        return $this->mutate(['tableCursor' => $tableCursor]);
    }

    public function withSelectedTable(?string $selectedTable): self
    {
        return $this->mutate(['selectedTable' => $selectedTable]);
    }

    /** @param list<array<string,mixed>> $rows */
    public function withRows(array $rows): self
    {
        return $this->mutate(['rows' => $rows]);
    }

    public function withRowCursor(int $rowCursor): self
    {
        return $this->mutate(['rowCursor' => $rowCursor]);
    }

    public function withResultTable(?ResultTable $resultTable): self
    {
        return $this->mutate(['resultTable' => $resultTable]);
    }

    public function withRowsLoading(bool $rowsLoading): self
    {
        return $this->mutate(['rowsLoading' => $rowsLoading]);
    }
}

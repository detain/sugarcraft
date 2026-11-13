<?php

declare(strict_types=1);

namespace SugarCraft\Sql\App;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Sql\ResultTable;

/**
 * Table/rows-browsing slice of the {@see \SugarCraft\Sql\App} model: the
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
        public readonly int $loadGen = 0,
        public readonly string $filterText = '',
        public readonly bool $filterMode = false,
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

    /** The live `/`-filter string typed over the Tables pane (jorgerojas26/lazysql parity). */
    public function withFilterText(string $filterText): self
    {
        return $this->mutate(['filterText' => $filterText]);
    }

    /** Whether the `/` filter line is currently open and eating key input. */
    public function withFilterMode(bool $filterMode): self
    {
        return $this->mutate(['filterMode' => $filterMode]);
    }

    /**
     * Table names the cursor can reach right now: the filter is a lowercase
     * substring match, so `/ord` leaves `orders`, `OrdLog`, `xORDy` — and the
     * cursor indexes THIS list, never the raw one.
     *
     * @return list<string>
     */
    public function visibleTables(): array
    {
        if ($this->filterText === '') {
            return $this->tables;
        }

        $needle = mb_strtolower($this->filterText);

        return array_values(array_filter(
            $this->tables,
            static fn (string $name): bool => str_contains(mb_strtolower($name), $needle),
        ));
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

    /**
     * Advance the async browse generation (client-012 companion of
     * QueryState::withIncrementedQueryGen). A re-selection of the SAME table
     * leaves selectedTable unchanged, so the table-name guard in the fold
     * cannot see that the older leg is now superseded; the generation can.
     */
    public function withIncrementedLoadGen(): self
    {
        return $this->mutate(['loadGen' => $this->loadGen + 1]);
    }
}

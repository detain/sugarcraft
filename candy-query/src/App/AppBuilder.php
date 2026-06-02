<?php

declare(strict_types=1);

namespace SugarCraft\Query\App;

use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Pane;

/**
 * Fluent builder for App.
 *
 * @method self withDb(DatabaseInterface $db)
 * @method self withFlavor(Flavor $flavor)
 * @method self withTables(array $tables)
 * @method self withTableCursor(int $tableCursor)
 * @method self withSelectedTable(?string $selectedTable)
 * @method self withRows(array $rows)
 * @method self withRowCursor(int $rowCursor)
 * @method self withQueryBuf(string $queryBuf)
 * @method self withPane(Pane $pane)
 * @method self withError(?string $error)
 * @method self withStatus(?string $status)
 * @method self withQueryHistory(array $queryHistory)
 * @method self withQueryFavorites(array $queryFavorites)
 * @method self withHistoryIndex(int $historyIndex)
 * @method self withSavedBuf(?string $savedBuf)
 */
final class AppBuilder
{
    private ?DatabaseInterface $db = null;
    private Flavor $flavor = Flavor::Sqlite;
    private array $tables = [];
    private int $tableCursor = 0;
    private ?string $selectedTable = null;
    private array $rows = [];
    private int $rowCursor = 0;
    private string $queryBuf = '';
    private Pane $pane = Pane::Tables;
    private ?string $error = null;
    private ?string $status = null;
    private array $queryHistory = [];
    private array $queryFavorites = [];
    private int $historyIndex = -1;
    private ?string $savedBuf = null;

    public function withDb(DatabaseInterface $db): self
    {
        $clone = clone $this;
        $clone->db = $db;
        return $clone;
    }

    public function withFlavor(Flavor $flavor): self
    {
        $clone = clone $this;
        $clone->flavor = $flavor;
        return $clone;
    }

    public function withTables(array $tables): self
    {
        $clone = clone $this;
        $clone->tables = $tables;
        return $clone;
    }

    public function withTableCursor(int $tableCursor): self
    {
        $clone = clone $this;
        $clone->tableCursor = $tableCursor;
        return $clone;
    }

    public function withSelectedTable(?string $selectedTable): self
    {
        $clone = clone $this;
        $clone->selectedTable = $selectedTable;
        return $clone;
    }

    public function withRows(array $rows): self
    {
        $clone = clone $this;
        $clone->rows = $rows;
        return $clone;
    }

    public function withRowCursor(int $rowCursor): self
    {
        $clone = clone $this;
        $clone->rowCursor = $rowCursor;
        return $clone;
    }

    public function withQueryBuf(string $queryBuf): self
    {
        $clone = clone $this;
        $clone->queryBuf = $queryBuf;
        return $clone;
    }

    public function withPane(Pane $pane): self
    {
        $clone = clone $this;
        $clone->pane = $pane;
        return $clone;
    }

    public function withError(?string $error): self
    {
        $clone = clone $this;
        $clone->error = $error;
        return $clone;
    }

    public function withStatus(?string $status): self
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    public function withQueryHistory(array $queryHistory): self
    {
        $clone = clone $this;
        $clone->queryHistory = $queryHistory;
        return $clone;
    }

    public function withQueryFavorites(array $queryFavorites): self
    {
        $clone = clone $this;
        $clone->queryFavorites = $queryFavorites;
        return $clone;
    }

    public function withHistoryIndex(int $historyIndex): self
    {
        $clone = clone $this;
        $clone->historyIndex = $historyIndex;
        return $clone;
    }

    public function withSavedBuf(?string $savedBuf): self
    {
        $clone = clone $this;
        $clone->savedBuf = $savedBuf;
        return $clone;
    }

    public function build(): \SugarCraft\Query\App
    {
        if ($this->db === null) {
            throw new \LogicException('db is required');
        }

        return new \SugarCraft\Query\App(
            $this->db,
            $this->flavor,
            $this->tables,
            $this->tableCursor,
            $this->selectedTable,
            $this->rows,
            $this->rowCursor,
            $this->queryBuf,
            $this->pane,
            $this->error,
            $this->status,
            $this->queryHistory,
            $this->queryFavorites,
            $this->historyIndex,
            $this->savedBuf,
        );
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use SugarCraft\Query\Lang;

/**
 * Cell-level editor for SQLite tables.
 *
 * Accepts a table name and primary-key column, then allows UPDATE
 * operations on specific cells by row identity.  The editor is
 * intentionally narrow — it does not handle INSERT or DELETE; those
 * are handled by {@see Database} directly.
 */
final class CellEditor
{
    public function __construct(
        public readonly \PDO $pdo,
        public readonly string $table,
        public readonly string $pkColumn,
    ) {}

    /**
     * Update a single cell in a specific row.
     *
     * @param mixed $rowId    Primary-key value of the row to update
     * @param mixed $newValue  New value to set
     * @return int             Number of rows affected (0 or 1)
     * @throws \PDOException  On SQL errors
     */
    public function updateCell(mixed $rowId, string $column, mixed $newValue): int
    {
        $safeTable = str_replace('"', '""', $this->table);
        $safeCol   = str_replace('"', '""', $column);
        $safePk    = str_replace('"', '""', $this->pkColumn);

        $sql = "UPDATE \"{$safeTable}\" SET \"{$safeCol}\" = :newval WHERE \"{$safePk}\" = :rowid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':newval', $newValue);
        $stmt->bindValue(':rowid', $rowId);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Update multiple cells in a single row in one statement.
     *
     * @param mixed                $rowId   Primary-key value of the row
     * @param array<string, mixed>  $cells   Map of column => new value
     * @return int                           Rows affected
     * @throws \PDOException
     */
    public function updateRow(mixed $rowId, array $cells): int
    {
        if ($cells === []) {
            return 0;
        }

        $safeTable = str_replace('"', '""', $this->table);
        $safePk    = str_replace('"', '""', $this->pkColumn);

        $setParts = [];
        $bindValues = [':rowid' => $rowId];
        $i = 0;
        foreach ($cells as $col => $val) {
            $key = ":val{$i}";
            $safeCol = str_replace('"', '""', $col);
            $setParts[] = "\"{$safeCol}\" = {$key}";
            $bindValues[$key] = $val;
            $i++;
        }

        $sql = "UPDATE \"{$safeTable}\" SET " . implode(', ', $setParts)
             . " WHERE \"{$safePk}\" = :rowid";

        $stmt = $this->pdo->prepare($sql);
        foreach ($bindValues as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Read the current value of a cell.
     *
     * @return mixed  The cell value, or null if the row/column does not exist
     */
    public function readCell(mixed $rowId, string $column): mixed
    {
        $safeTable = str_replace('"', '""', $this->table);
        $safeCol   = str_replace('"', '""', $column);
        $safePk    = str_replace('"', '""', $this->pkColumn);

        $sql = "SELECT \"{$safeCol}\" FROM \"{$safeTable}\" WHERE \"{$safePk}\" = :rowid LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':rowid', $rowId);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? ($row[$column] ?? null) : null;
    }
}

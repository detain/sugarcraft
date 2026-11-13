<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Support;

/**
 * Canned PDOStatement for SQL-level execution against ScriptablePdo.
 *
 * SQL-C05: both driver suites previously constructed via
 * newInstanceWithoutConstructor() with pdo=null, so query()/quote()/exec()/
 * prepare() were exercised only on their disconnected branches. This stub
 * replays scripted rows so the connected code paths actually run.
 */
final class ScriptableStatement extends \PDOStatement
{
    /**
     * @param list<array<string,mixed>> $rows
     */
    private function __construct(
        private readonly ScriptablePdo $pdo,
        private readonly string $sql,
        private array $rows,
        private readonly ?\PDOException $error,
        private readonly bool $isSelect,
        private int $affected,
    ) {
    }

    /** @internal */
    public static function for(ScriptablePdo $pdo, string $sql, array $rows, ?\PDOException $error, bool $isSelect, int $affected = 0): self
    {
        return new self($pdo, $sql, $rows, $error, $isSelect, $affected);
    }

    public function execute(?array $params = null): bool
    {
        $this->pdo->log('execute', $this->sql, $params);
        if ($this->error !== null) {
            throw $this->error;
        }
        return true;
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $this->pdo->log('fetchAll', $this->sql, null);
        return $this->rows;
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->rows) ?? false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = array_shift($this->rows);
        if ($row === null) {
            return false;
        }
        $values = array_values($row);
        return $values[$column] ?? false;
    }

    public function columnCount(): int
    {
        return $this->isSelect ? max(1, count($this->rows[0] ?? ['x' => 1])) : 0;
    }

    public function rowCount(): int
    {
        return $this->isSelect ? count($this->rows) : $this->affected;
    }

    public function closeCursor(): bool
    {
        return true;
    }

    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->rows);
    }
}

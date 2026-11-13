<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Db;

/**
 * Builds the single-row writes behind the Rows pane's cell editor (SQL-O03).
 *
 * Pure + flavor-keyed: every identifier goes through {@see Identifier::quote()}
 * (SQL-C22) and every value is a bound `?` parameter. Rows are addressed by
 * their FULL primary key only — a statement that could touch more than the
 * row on screen is unrepresentable (an empty key throws), and the runner
 * additionally refuses to commit when the server reports a different row
 * count than the statement promised.
 *
 * Inspired by the table-editing flow of jorgerojas26/lazysql (change cell,
 * new row, delete row, confirm before write); the builder itself is ours.
 */
final class DmlBuilder
{
    private function __construct(private readonly Flavor $flavor) {}

    public static function new(Flavor $flavor): self
    {
        return new self($flavor);
    }

    /**
     * UPDATE one column of the row addressed by $key.
     *
     * @param array<string, mixed> $key column => value of the full primary key
     */
    public function update(string $table, array $key, string $column, ?string $value): DmlStatement
    {
        $this->nonEmpty($table, 'table');
        $this->nonEmpty($column, 'column');
        [$where, $whereParams, $whereDisplay] = $this->where($key);

        return new DmlStatement(
            DmlStatement::UPDATE,
            'UPDATE ' . $this->q($table) . ' SET ' . $this->q($column) . ' = ?' . $where,
            [$value, ...$whereParams],
            'UPDATE ' . $this->q($table) . ' SET ' . $this->q($column) . ' = ' . $this->literal($value) . $whereDisplay,
            expect: 1,
            allowZero: true,
        );
    }

    /**
     * INSERT one row. Columns absent from $values take their DEFAULT.
     *
     * @param array<string, ?string> $values column => value (null = SQL NULL)
     */
    public function insert(string $table, array $values): DmlStatement
    {
        $this->nonEmpty($table, 'table');
        if ($values === []) {
            $sql = $this->flavor === Flavor::MySQL || $this->flavor === Flavor::MariaDB || $this->flavor === Flavor::Percona
                ? 'INSERT INTO ' . $this->q($table) . ' () VALUES ()'
                : 'INSERT INTO ' . $this->q($table) . ' DEFAULT VALUES';

            return new DmlStatement(DmlStatement::INSERT, $sql, [], $sql);
        }

        $cols = [];
        $marks = [];
        $params = [];
        $lits = [];
        foreach ($values as $col => $val) {
            $this->nonEmpty((string) $col, 'column');
            $cols[] = $this->q((string) $col);
            $marks[] = '?';
            $params[] = $val;
            $lits[] = $this->literal($val);
        }
        $head = 'INSERT INTO ' . $this->q($table) . ' (' . implode(', ', $cols) . ') VALUES (';

        return new DmlStatement(
            DmlStatement::INSERT,
            $head . implode(', ', $marks) . ')',
            $params,
            $head . implode(', ', $lits) . ')',
        );
    }

    /**
     * DELETE the row addressed by $key.
     *
     * @param array<string, mixed> $key column => value of the full primary key
     */
    public function delete(string $table, array $key): DmlStatement
    {
        $this->nonEmpty($table, 'table');
        [$where, $whereParams, $whereDisplay] = $this->where($key);

        return new DmlStatement(
            DmlStatement::DELETE,
            'DELETE FROM ' . $this->q($table) . $where,
            $whereParams,
            'DELETE FROM ' . $this->q($table) . $whereDisplay,
        );
    }

    /**
     * @param array<string, mixed> $key
     * @return array{0: string, 1: list<int|float|string|null>, 2: string}
     */
    private function where(array $key): array
    {
        if ($key === []) {
            throw new \InvalidArgumentException('a row key is required: refusing a statement that could touch more than one row');
        }
        $sql = [];
        $display = [];
        $params = [];
        foreach ($key as $col => $val) {
            $this->nonEmpty((string) $col, 'key column');
            if ($val === null) {
                throw new \InvalidArgumentException('key column ' . $col . ' is NULL: the row cannot be addressed');
            }
            $val = is_bool($val) ? (int) $val : (is_scalar($val) ? $val : (string) json_encode($val));
            $sql[] = $this->q((string) $col) . ' = ?';
            $display[] = $this->q((string) $col) . ' = ' . $this->literal($val);
            $params[] = $val;
        }

        return [' WHERE ' . implode(' AND ', $sql), $params, ' WHERE ' . implode(' AND ', $display)];
    }

    private function q(string $name): string
    {
        return Identifier::quote($this->flavor, $name);
    }

    private function literal(int|float|string|null $v): string
    {
        return match (true) {
            $v === null => 'NULL',
            is_int($v), is_float($v) => (string) $v,
            default => Identifier::quoteLiteral($this->flavor, $v),
        };
    }

    private function nonEmpty(string $v, string $what): void
    {
        if ($v === '') {
            throw new \InvalidArgumentException($what . ' name must not be empty');
        }
    }
}

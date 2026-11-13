<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Support;

/**
 * Replay-able PDO double for connected-path driver tests (SQL-C05).
 *
 * The real \PDO constructor is intentionally never called — the object
 * carries no connection and every executed method is scripted here. Row
 * scripts match by case-insensitive SQL substring; the last matching
 * `on()` wins, so tests layer defaults then refine.
 *
 * Also provides the opt-in live legs used as the executability authority
 * for SQL-C03/C04/C10/P03/O23 acceptance: set CANDY_SQL_MYSQL_DSN /
 * CANDY_SQL_PG_DSN to run against a real server.
 */
final class ScriptablePdo extends \PDO
{
    /** @var list<array{needle:string, rows:list<array<string,mixed>>, error:?\PDOException, sleep:float}> */
    private array $script = [];

    /** @var list<array{op:string, sql:string, params:array<mixed>|null}> */
    public array $log = [];

    /** @var list<array{attr:int, value:mixed}> */
    public array $attributes = [];

    public function __construct(
        public readonly string $dialect = 'mysql',
    ) {
        // Deliberately NOT calling parent::__construct(): no real connection.
    }

    /** Script canned rows for any SQL containing $needle. */
    public function on(string $needle, array $rows, int $affected = 0): self
    {
        $this->script[] = [
            'needle' => strtolower($needle),
            'rows' => $rows,
            'error' => null,
            'sleep' => 0.0,
            'affected' => $affected,
        ];
        return $this;
    }

    /** Make matching SQL throw $error at execute()/fetch time. */
    public function failing(string $needle, ?\PDOException $error = null): self
    {
        $this->script[] = ['needle' => strtolower($needle), 'rows' => [], 'error' => $error ?? new \PDOException($needle), 'sleep' => 0.0, 'affected' => 0];
        return $this;
    }

    /** Make matching SQL block for $seconds at execute()/query() time. */
    public function stalling(string $needle, float $seconds): self
    {
        $this->script[] = ['needle' => strtolower($needle), 'rows' => [], 'error' => null, 'sleep' => $seconds, 'affected' => 0];
        return $this;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $this->log('query', $query, null);
        return $this->statementFor($query);
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->log('prepare', $query, null);
        return $this->statementFor($query);
    }

    public function exec(string $statement): int|false
    {
        $this->log('exec', $statement, null);
        $hit = $this->match($statement);
        $this->burn($hit);
        if ($hit !== null && $hit['error'] !== null) {
            throw $hit['error'];
        }
        return count($hit['rows'] ?? []);
    }

    public function quote(string $string, int $type = \PDO::PARAM_STR): string|false
    {
        $this->log('quote', $string, null);
        // Real PDO behaviour per dialect: mysql escapes with backslashes,
        // pgsql/sqlite double the quote (SQL-C10's mock-quote alignment).
        return match ($this->dialect) {
            'pgsql', 'sqlite' => "'" . str_replace("'", "''", $string) . "'",
            default => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $string) . "'",
        };
    }

    public function lastInsertId(?string $name = null): string|false
    {
        $this->log('lastInsertId', '', null);
        return '0';
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->attributes[] = ['attr' => $attribute, 'value' => $value];
        return true;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function beginTransaction(): bool
    {
        $this->log('beginTransaction', '', null);
        return true;
    }

    public function commit(): bool
    {
        $this->log('commit', '', null);
        return true;
    }

    public function rollBack(): bool
    {
        $this->log('rollBack', '', null);
        return true;
    }

    /** @internal consumed by ScriptableStatement */
    public function log(string $op, string $sql, ?array $params): void
    {
        $this->log[] = ['op' => $op, 'sql' => $sql, 'params' => $params];
    }

    /** @internal */
    public function burn(?array $hit): void
    {
        if ($hit !== null && $hit['sleep'] > 0.0) {
            usleep((int) ($hit['sleep'] * 1_000_000));
        }
    }

    /** @internal */
    public function match(string $sql): ?array
    {
        $needle = strtolower($sql);
        $found = null;
        foreach ($this->script as $entry) {
            if (str_contains($needle, $entry['needle'])) {
                $found = $entry; // last match wins
            }
        }
        return $found;
    }

    private function statementFor(string $query): \PDOStatement
    {
        $hit = $this->match($query);
        $this->burn($hit);
        return ScriptableStatement::for(
            $this,
            $query,
            $hit['rows'] ?? [],
            $hit['error'] ?? null,
            $this->looksLikeSelect($query),
            $hit['affected'] ?? 0,
        );
    }

    private function looksLikeSelect(string $sql): bool
    {
        return str_starts_with(ltrim(strtolower($sql)), 'select')
            || str_starts_with(ltrim(strtolower($sql)), 'show');
    }
}

<?php

declare(strict_types=1);

namespace CandyCore\Query;

/**
 * Thin SQLite wrapper. Everything else in this app talks to a {@see
 * Database} (an interface in spirit, sealed concrete class in
 * practice — promote to an interface the day a non-SQLite driver
 * lands).
 *
 * The wrapper is split out so the Model can be tested against an
 * in-memory `:memory:` PDO with no fixture files on disk.
 */
final class Database
{
    public function __construct(public readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public static function open(string $path): self
    {
        if ($path !== ':memory:' && !is_file($path)) {
            throw new \RuntimeException("candy-query: no such SQLite file: $path");
        }
        return new self(new \PDO('sqlite:' . $path));
    }

    /** @return list<string> */
    public function tables(): array
    {
        $rows = $this->pdo->query(
            "SELECT name FROM sqlite_master "
            . "WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' "
            . "ORDER BY name",
        );
        if ($rows === false) return [];
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['name'])) {
                $out[] = (string) $row['name'];
            }
        }
        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function rows(string $table, int $limit = 100): array
    {
        $sql = sprintf('SELECT * FROM "%s" LIMIT %d', str_replace('"', '""', $table), $limit);
        $stmt = $this->pdo->query($sql);
        return $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Run an arbitrary SELECT and return the rowset, or return a
     * single-row `affected => N` map for non-SELECT statements.
     *
     * @return list<array<string,mixed>>
     */
    public function query(string $sql): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        if ($stmt->columnCount() > 0) {
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        return [['affected' => $stmt->rowCount()]];
    }
}

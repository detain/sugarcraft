<?php

declare(strict_types=1);

namespace SugarCraft\Query\Explain;

use SugarCraft\Query\Db\DatabaseInterface;

/**
 * SQLite EXPLAIN QUERY PLAN provider.
 *
 * Executes `EXPLAIN QUERY PLAN` against a SQLite database and returns
 * the raw detail rows for parsing by ExplainView.
 */
final class SqliteExplainProvider implements ExplainProviderInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /**
     * Execute EXPLAIN QUERY PLAN and return raw rows.
     *
     * @param string $sql The SQL query to explain
     * @return list<array{detail:string}>
     */
    public function explain(string $sql): array
    {
        // Guard: empty SQL should not be explained
        if ($sql === '') {
            return [];
        }

        $stmt = $this->db->query("EXPLAIN QUERY PLAN {$sql}");

        /** @var list<array{detail:string}> */
        return array_filter($stmt, static fn(array $row): bool =>
            isset($row['detail']) && $row['detail'] !== ''
        );
    }

    public function getDriverName(): string
    {
        return 'sqlite';
    }
}

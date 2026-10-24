<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Explain;

use SugarCraft\Sql\Db\DatabaseInterface;

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

    public function driverName(): string
    {
        return 'sqlite';
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::driverName()} instead. REMOVED-IN: 2.0.0.
     */
    public function getDriverName(): string { return $this->driverName(); }
}

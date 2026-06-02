<?php

declare(strict_types=1);

namespace SugarCraft\Query\Explain;

/**
 * Driver-aware interface for EXPLAIN output.
 *
 * Each database driver has its own EXPLAIN syntax and output format.
 * This interface abstracts the driver-specific details so ExplainView
 * can remain driver-agnostic.
 */
interface ExplainProviderInterface
{
    /**
     * Execute EXPLAIN and return parsed output as an array.
     *
     * @param string $sql The SQL query to explain
     * @return list<array{detail:string}> Raw explain rows
     */
    public function explain(string $sql): array;

    /**
     * Get the driver name this provider handles.
     *
     * @return string Driver name ('sqlite', 'mysql', 'pgsql')
     */
    public function getDriverName(): string;
}

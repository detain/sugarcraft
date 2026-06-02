<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

use React\Promise\PromiseInterface;

/**
 * A non-blocking SQL connection that returns a ReactPHP promise of rows.
 *
 * Implemented by the React-native admin connections (MySQL/Postgres) so the
 * admin async layer ({@see AdminQueryCache}) can run queries on the event loop
 * without caring which flavor is behind it.
 */
interface AsyncConnection
{
    /**
     * Execute a query asynchronously.
     *
     * @return PromiseInterface<list<array<string,mixed>>> Rows as assoc arrays
     */
    public function query(string $sql): PromiseInterface;
}

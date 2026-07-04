<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

use React\Promise\PromiseInterface;
use SugarCraft\Async\CancellationToken;

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
     * Cancelling `$cancellation` (a candy-async token) rejects the returned
     * promise immediately with {@see QueryCancelledException} and asks the
     * driver to stop the query server-side where possible — see the concrete
     * connections for what each driver actually cancels.
     *
     * @param string $sql SQL query to execute
     * @param CancellationToken|null $cancellation Optional cooperative-cancel handle
     * @return PromiseInterface<list<array<string,mixed>>> Rows as assoc arrays
     */
    public function query(string $sql, ?CancellationToken $cancellation = null): PromiseInterface;
}

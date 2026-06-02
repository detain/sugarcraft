<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

use PgAsync\Client;
use React\EventLoop\Loop as ReactLoop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/**
 * Async PostgreSQL connection wrapper using voryx/pgasync.
 *
 * PgAsync is the only pure-PHP async Postgres client that runs on the ReactPHP
 * event loop (the one candy-core's Program drives) — no Revolt, no fibers, no
 * loop bridging. (Supersedes an earlier amphp/postgres attempt whose Revolt
 * loop could not be bridged onto React's, crashing the admin pane on open.)
 *
 * PgAsync's query API streams rows as an RxPHP Observable; we buffer the whole
 * result set with toArray() and adapt it to a react/promise via toPromise(),
 * so the admin-fetch path sees the same PromiseInterface<list<rows>> contract
 * as the MySQL wrapper.
 *
 * @see https://github.com/voryx/PgAsync
 */
final class ReactPostgresConnection implements AsyncConnection
{
    private Client $client;

    public function __construct(string $dsn, string $username = '', string $password = '', ?LoopInterface $loop = null)
    {
        $loop = $loop ?? ReactLoop::get();
        $this->client = new Client($this->dsnToParameters($dsn, $username, $password), $loop);
    }

    /**
     * Execute a query asynchronously.
     *
     * @param string $sql SQL query to execute
     * @return PromiseInterface<list<array<string,mixed>>> Rows as assoc arrays
     */
    public function query(string $sql): PromiseInterface
    {
        // PgAsync emits each row as a separate Observable item; toArray() buffers
        // the full result set into one emission, toPromise() bridges to react/promise.
        return $this->client->query($sql)->toArray()->toPromise();
    }

    /**
     * Convert PDO-style DSN to a PgAsync connection-parameter array.
     *
     * PDO:     pgsql:host=localhost;port=5432;dbname=test
     * PgAsync: ['host'=>.., 'port'=>.., 'database'=>.., 'user'=>.., 'password'=>..]
     *
     * PgAsync requires both 'user' and 'database'; host/port have built-in
     * defaults but we pass them explicitly from the DSN when present.
     *
     * @return array<string,mixed>
     */
    private function dsnToParameters(string $dsn, string $username, string $password): array
    {
        $params = [
            'host'            => $this->extractDsnValue($dsn, 'host') ?? 'localhost',
            'port'            => (int) ($this->extractDsnValue($dsn, 'port') ?? '5432'),
            'user'            => $username !== '' ? $username : 'postgres',
            'database'        => $this->extractDsnValue($dsn, 'dbname') ?? 'postgres',
            'auto_disconnect' => true,
        ];

        if ($password !== '') {
            $params['password'] = $password;
        }

        return $params;
    }

    private function extractDsnValue(string $dsn, string $key): ?string
    {
        if (preg_match("/{$key}=([^;]+)/", $dsn, $matches)) {
            return $matches[1];
        }
        return null;
    }
}

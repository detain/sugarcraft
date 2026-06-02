<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

use React\EventLoop\Loop as ReactLoop;
use React\EventLoop\LoopInterface;
use React\Mysql\MysqlClient;
use React\Mysql\MysqlResult;
use React\Promise\PromiseInterface;

/**
 * Async MySQL connection wrapper using react/mysql.
 *
 * Runs natively on the same ReactPHP event loop that candy-core's Program
 * already drives — no Revolt loop, no fibers, no loop bridging. (Supersedes an
 * earlier amphp/mysql attempt that needed amphp's Revolt loop bridged onto
 * React's; that bridge class never existed, so the admin pane crashed on open.)
 *
 * The MysqlClient is lazy: it opens the underlying socket on the first query
 * and resolves/rejects the per-query promise on the React loop.
 *
 * @see https://github.com/friends-of-reactphp/mysql
 */
final class ReactMysqlConnection implements AsyncConnection
{
    private MysqlClient $client;

    public function __construct(string $dsn, string $username, string $password, ?LoopInterface $loop = null)
    {
        $loop = $loop ?? ReactLoop::get();
        $this->client = new MysqlClient($this->dsnToUri($dsn, $username, $password), null, $loop);
    }

    /**
     * Execute a query asynchronously.
     *
     * @param string $sql SQL query to execute
     * @return PromiseInterface<list<array<string,mixed>>> Rows as assoc arrays
     */
    public function query(string $sql): PromiseInterface
    {
        return $this->client->query($sql)->then(
            static fn(MysqlResult $result): array => $result->resultRows ?? [],
        );
    }

    /**
     * Convert PDO-style DSN to react/mysql URI format.
     *
     * PDO:         mysql:host=localhost;port=3306;dbname=test
     * react/mysql: user:pass@localhost:3306/test
     */
    private function dsnToUri(string $dsn, string $username, string $password): string
    {
        $host = $this->extractDsnValue($dsn, 'host') ?? 'localhost';
        $port = $this->extractDsnValue($dsn, 'port') ?? '3306';
        $dbname = $this->extractDsnValue($dsn, 'dbname') ?? '';

        $user = $username !== '' ? $username : 'root';

        return sprintf(
            '%s:%s@%s:%s/%s',
            rawurlencode($user),
            rawurlencode($password),
            $host,
            $port,
            rawurlencode($dbname),
        );
    }

    private function extractDsnValue(string $dsn, string $key): ?string
    {
        if (preg_match("/{$key}=([^;]+)/", $dsn, $matches)) {
            return $matches[1];
        }
        return null;
    }
}

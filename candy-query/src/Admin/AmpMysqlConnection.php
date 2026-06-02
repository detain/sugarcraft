<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

use Amp\Coroutine;
use Amp\EventLoop\Loop as AmpLoop;
use Amp\EventLoop\ReactDriver;
use Amp\Iterator;
use Amp\Mysql\CloseableIterator;
use Amp\Mysql\Config;
use Amp\Mysql\ConnectionPool;
use React\EventLoop\Loop as ReactLoop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Async MySQL connection wrapper using amphp/mysql.
 *
 * Provides truly native non-blocking async MySQL access via amphp's
 * generator-based coroutines, bridged to ReactPHP promises for compatibility.
 *
 * @see https://github.com/amphp/mysql
 */
final class AmpMysqlConnection
{
    private ConnectionPool $pool;
    private ?LoopInterface $loop;

    public function __construct(string $dsn, string $username, string $password, ?LoopInterface $loop = null)
    {
        $this->loop = $loop ?? ReactLoop::get();

        // Bridge ReactPHP loop to amphp so they share the same event loop
        $ampDriver = new ReactDriver($this->loop);
        AmpLoop::setGlobalDriver($ampDriver);

        $uri = $this->dsnToUri($dsn, $username, $password);
        $config = Config::fromUri($uri);

        $this->pool = new ConnectionPool($config);
    }

    /**
     * Execute a query asynchronously.
     *
     * @param string $sql SQL query to execute
     * @return PromiseInterface<list<array<string,mixed>>> Rows as assoc arrays
     */
    public function query(string $sql): PromiseInterface
    {
        $deferred = new Deferred();

        // Schedule coroutine on amphp's event loop (which now uses ReactPHP's loop)
        AmpLoop::defer(function () use ($sql, $deferred): void {
            new Coroutine($this->executeQuery($sql, $deferred));
        });

        return $deferred->promise();
    }

    /**
     * Execute query and resolve deferred promise with results.
     *
     * @see https://amphp.org/mysql/queries#consuming-results
     */
    private function executeQuery(string $sql, Deferred $deferred): \Generator
    {
        $iterator = null;
        try {
            /** @var CloseableIterator<mixed> $iterator */
            $iterator = yield $this->pool->query($sql);

            $rows = [];
            while (yield $iterator->advance()) {
                $rows[] = $iterator->getCurrent()->toArray();
            }

            $deferred->resolve($rows);
        } catch (\Throwable $e) {
            $deferred->reject($e);
        } finally {
            if ($iterator instanceof CloseableIterator) {
                $iterator->close();
            }
        }
    }

    /**
     * Convert PDO-style DSN to amphp/mysql URI format.
     *
     * PDO:  mysql:host=localhost;port=3306;dbname=test
     * amphp: mysql://user:pass@localhost:3306/test
     */
    private function dsnToUri(string $dsn, string $username, string $password): string
    {
        $host = $this->extractDsnValue($dsn, 'host') ?? 'localhost';
        $port = $this->extractDsnValue($dsn, 'port') ?? '3306';
        $dbname = $this->extractDsnValue($dsn, 'dbname') ?? '';

        // amphp/mysql requires user:pass@ prefix even if no auth
        $user = $username ?: 'root';
        $pass = $password;

        return sprintf('mysql://%s:%s@%s:%s/%s', rawurlencode($user), rawurlencode($pass), $host, $port, $dbname);
    }

    private function extractDsnValue(string $dsn, string $key): ?string
    {
        if (preg_match("/{$key}=([^;]+)/", $dsn, $matches)) {
            return $matches[1];
        }
        return null;
    }
}

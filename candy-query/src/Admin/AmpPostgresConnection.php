<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

use Amp\Coroutine;
use Amp\EventLoop\Loop as AmpLoop;
use Amp\EventLoop\ReactDriver;
use Amp\Postgres\ConnectionPool;
use Amp\Postgres\Config;
use React\EventLoop\Loop as ReactLoop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Async PostgreSQL connection wrapper using amphp/postgres.
 *
 * Provides truly native non-blocking async PostgreSQL access via amphp's
 * generator-based coroutines, bridged to ReactPHP promises for compatibility.
 *
 * @see https://github.com/amphp/postgres
 */
final class AmpPostgresConnection
{
    private ConnectionPool $pool;
    private ?LoopInterface $loop;

    public function __construct(string $dsn, ?LoopInterface $loop = null)
    {
        $this->loop = $loop ?? ReactLoop::get();

        // Bridge ReactPHP loop to amphp so they share the same event loop
        $ampDriver = new ReactDriver($this->loop);
        AmpLoop::setGlobalDriver($ampDriver);

        $uri = $this->dsnToUri($dsn);
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
     */
    private function executeQuery(string $sql, Deferred $deferred): \Generator
    {
        $result = null;
        try {
            /** @var \Amp\Postgres\Result $result */
            $result = yield $this->pool->query($sql);

            $rows = [];
            while (yield $result->advance()) {
                $row = $result->getCurrent();
                // amphp/postgres RowData has numeric and string keys
                // Convert to associative array similar to react/pgsql behavior
                $rows[] = (array) $row;
            }

            $deferred->resolve($rows);
        } catch (\Throwable $e) {
            $deferred->reject($e);
        } finally {
            // Result auto-closes when consumed, but guard anyway
            if ($result instanceof \Amp\Postgres\Result) {
                $result->close();
            }
        }
    }

    /**
     * Convert PDO-style DSN to amphp/postgres URI format.
     *
     * PDO:  pgsql:host=localhost;port=5432;dbname=test
     * amphp: postgresql://user:pass@localhost:5432/test
     *
     * Note: amphp/postgres doesn't require explicit user/pass in URI
     */
    private function dsnToUri(string $dsn): string
    {
        $host = $this->extractDsnValue($dsn, 'host') ?? 'localhost';
        $port = $this->extractDsnValue($dsn, 'port') ?? '5432';
        $dbname = $this->extractDsnValue($dsn, 'dbname') ?? '';

        return sprintf('postgresql://%s:%s/%s', $host, $port, $dbname);
    }

    private function extractDsnValue(string $dsn, string $key): ?string
    {
        if (preg_match("/{$key}=([^;]+)/", $dsn, $matches)) {
            return $matches[1];
        }
        return null;
    }
}

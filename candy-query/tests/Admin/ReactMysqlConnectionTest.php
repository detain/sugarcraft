<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\QueryTimeout;
use SugarCraft\Query\Admin\ReactMysqlConnection;
use SugarCraft\Query\Admin\ReactPostgresConnection;

/**
 * Guards the PDO-DSN → react/mysql-URI translation.
 *
 * react/mysql's Factory runs the URI through parse_url() and connects to
 * $parts['host'], requiring a `mysql://` scheme. A scheme-less URI mis-parses
 * (the username becomes the "scheme", host is empty) and every connect is
 * "refused" — so this test pins the scheme and the host/port/credential
 * round-trip. dsnToUri() is exercised in isolation (no socket/loop).
 */
final class ReactMysqlConnectionTest extends TestCase
{
    /** Build the react/mysql URI dsnToUri() would hand the driver. */
    private function uriFor(string $dsn, string $user, string $pass): string
    {
        $ref = new \ReflectionClass(ReactMysqlConnection::class);
        $conn = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('dsnToUri');
        $method->setAccessible(true);

        return $method->invoke($conn, $dsn, $user, $pass);
    }

    public function testUriCarriesTheRequiredMysqlScheme(): void
    {
        $uri = $this->uriFor('mysql:host=db.example.com;port=3306;dbname=shop', 'appuser', 'secret');

        $this->assertStringStartsWith('mysql://', $uri);
    }

    public function testUriParsesToTheRemoteHostNotLocalhost(): void
    {
        $uri = $this->uriFor('mysql:host=db.example.com;port=3307;dbname=shop', 'appuser', 'secret');
        $parts = parse_url($uri);

        $this->assertSame('mysql', $parts['scheme'] ?? null);
        $this->assertSame('db.example.com', $parts['host'] ?? null, 'host must survive — not default to localhost');
        $this->assertSame(3307, $parts['port'] ?? null);
    }

    public function testZeroPortIsNormalisedToTheDefault(): void
    {
        // PDO tolerates port 0 (→3306); react/mysql connects to literal :0 and
        // is refused, so dsnToUri must substitute the default.
        $uri = $this->uriFor('mysql:host=mysqlcluster1;port=0;dbname=my', 'replication_user', 'secret');
        $parts = parse_url($uri);

        $this->assertSame('mysqlcluster1', $parts['host'] ?? null);
        $this->assertSame(3306, $parts['port'] ?? null, 'port 0 must not reach the driver');
    }

    public function testCredentialsAndDbnameRoundTripThroughUrlEncoding(): void
    {
        // react/mysql rawurldecode()s user/pass/path, so special chars must be encoded.
        $uri = $this->uriFor('mysql:host=h;port=3306;dbname=my db', 'user@corp', 'p@ss:w/rd');
        $parts = parse_url($uri);

        $this->assertSame('user@corp', rawurldecode($parts['user'] ?? ''));
        $this->assertSame('p@ss:w/rd', rawurldecode($parts['pass'] ?? ''));
        $this->assertSame('my db', rawurldecode(ltrim($parts['path'] ?? '', '/')));
    }

    /**
     * Pins the query-deadline API on both async wrappers: an optional float
     * defaulting to QueryTimeout::DEFAULT_SECONDS, so existing
     * `new ReactMysqlConnection($dsn,$u,$p)` call sites get the protective
     * deadline without changes. Timeout semantics themselves are covered by
     * QueryTimeoutTest.
     */
    public function testQueryTimeoutParameterDefaultsToTheSharedDeadline(): void
    {
        foreach ([ReactMysqlConnection::class, ReactPostgresConnection::class] as $class) {
            $timeout = null;
            foreach ((new \ReflectionClass($class))->getConstructor()->getParameters() as $param) {
                if ($param->getName() === 'queryTimeout') {
                    $timeout = $param;
                }
            }

            $this->assertNotNull($timeout, $class);
            $this->assertSame(QueryTimeout::DEFAULT_SECONDS, $timeout->getDefaultValue(), $class);
        }
    }

    /**
     * Pins the cancellation API (plan 5.1) on the AsyncConnection contract and
     * both implementations: query() accepts an optional candy-async
     * CancellationToken so callers can abort in-flight queries cooperatively.
     */
    public function testQueryAcceptsAnOptionalCancellationToken(): void
    {
        foreach ([
            \SugarCraft\Query\Admin\AsyncConnection::class,
            ReactMysqlConnection::class,
            ReactPostgresConnection::class,
        ] as $class) {
            $params = (new \ReflectionMethod($class, 'query'))->getParameters();

            $this->assertSame('cancellation', $params[1]->getName(), $class);
            $this->assertSame(\SugarCraft\Async\CancellationToken::class, (string) $params[1]->getType()->getName(), $class);
            $this->assertTrue($params[1]->allowsNull(), $class);
            $this->assertNull($params[1]->getDefaultValue(), $class);
        }
    }

    /**
     * Server-side cancellation (plan 5.1): with the thread id known, the KILL
     * path opens a SEPARATE short-lived connection (the main one is busy
     * executing the very query being killed) and issues `KILL QUERY <id>`
     * with the int-typed id interpolated (MySQL's KILL takes no placeholders).
     */
    public function testKillPathIssuesKillQueryOnASeparateConnection(): void
    {
        $spy = new class {
            /** @var list<string> */
            public array $issued = [];
            public function query(string $sql): \React\Promise\PromiseInterface
            {
                $this->issued[] = $sql;
                return \React\Promise\resolve(null);
            }
            public function quit(): \React\Promise\PromiseInterface
            {
                $this->issued[] = '(quit)';
                return \React\Promise\resolve(null);
            }
            public function close(): void
            {
                $this->issued[] = '(close)';
            }
        };

        $factoryCalls = 0;
        $conn = $this->connectionWith(
            threadId: 42,
            killClientFactory: function (string $uri, $loop) use ($spy, &$factoryCalls) {
                $factoryCalls++;
                $this->assertStringStartsWith('mysql://', $uri, 'killer reuses the same connection URI');
                return $spy;
            },
        );

        $kill = new \ReflectionMethod($conn, 'killQueryOnServer');
        $kill->invoke($conn);

        $this->assertSame(1, $factoryCalls, 'exactly one side-channel connection');
        $this->assertSame(['KILL QUERY 42', '(quit)'], $spy->issued);
    }

    /**
     * Without a known thread id (CONNECTION_ID() not yet resolved, or its
     * fetch failed), cancellation must degrade to client-side only: no
     * side-channel connection is opened at all.
     */
    public function testKillPathIsANoOpWhenThreadIdIsUnknown(): void
    {
        $factoryCalls = 0;
        $conn = $this->connectionWith(
            threadId: null,
            killClientFactory: function () use (&$factoryCalls) {
                $factoryCalls++;
                return null;
            },
        );

        $kill = new \ReflectionMethod($conn, 'killQueryOnServer');
        $kill->invoke($conn);

        $this->assertSame(0, $factoryCalls, 'no KILL side-channel without a thread id');
    }

    /** Build a connection with a spy kill factory, no socket/loop activity. */
    private function connectionWith(?int $threadId, callable $killClientFactory): ReactMysqlConnection
    {
        $ref = new \ReflectionClass(ReactMysqlConnection::class);
        $conn = $ref->newInstanceWithoutConstructor();

        $set = function (string $prop, mixed $value) use ($ref, $conn): void {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($conn, $value);
        };
        $set('uri', 'mysql://root:secret@db.example.com:3306/shop');
        $set('loop', new \React\EventLoop\StreamSelectLoop());
        $set('threadId', $threadId);
        $set('killClientFactory', $killClientFactory);

        return $conn;
    }
}

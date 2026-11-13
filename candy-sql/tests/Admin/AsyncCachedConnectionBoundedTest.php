<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\AdminQueryCache;
use SugarCraft\Sql\Admin\AsyncCachedConnection;
use SugarCraft\Sql\Admin\Resilience\StatementTimeout;
use SugarCraft\Sql\Admin\Resilience\StatementTimeoutException;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\PreparedStatementInterface;

/**
 * SQL-C11 wrapper legs: the admin write-throughs (KILL, GTID, detail reads)
 * delegate synchronously through AsyncCachedConnection — the docblock once
 * promised zero synchronous render I/O; the honest contract is that every
 * synchronous delegation runs under the shared wall-clock cap, so the worst
 * case is a named timeout, never a frozen UI.
 */
final class AsyncCachedConnectionBoundedTest extends TestCase
{
    /** Inner whose exec() blocks for $stall seconds. */
    private static function stallingInner(float $stall): DatabaseInterface
    {
        return new class ($stall) implements DatabaseInterface {
            public function __construct(private readonly float $stall)
            {
            }

            public function query(string $sql): array|null
            {
                return [];
            }

            public function tables(): array
            {
                return [];
            }

            public function rows(string $table, int $limit = 100, int $offset = 0): array
            {
                return [];
            }

            public function lastInsertId(): string|int
            {
                return '0';
            }

            public function quote(string $value): string
            {
                return "'" . $value . "'";
            }

            public function exec(string $sql): int
            {
                usleep((int) ($this->stall * 1_000_000));
                return 1;
            }

            public function close(): void
            {
            }

            public function serverVersion(): string
            {
                return 'MySQL version 8.0.33';
            }

            public function driverName(): string
            {
                return 'mysql';
            }

            public function ping(): bool
            {
                return true;
            }

            public function databases(): array
            {
                return [];
            }

            public function prepare(string $sql): ?PreparedStatementInterface
            {
                return null;
            }

            public function dsn(): string
            {
                return 'mysql:host=h;port=3306;dbname=d';
            }

            public function username(): string
            {
                return 'u';
            }
        };
    }

    private function wrap(DatabaseInterface $inner, ?StatementTimeout $guard = null): AsyncCachedConnection
    {
        $conn = new AsyncCachedConnection($inner);
        if ($guard !== null) {
            $prop = new \ReflectionProperty($conn, 'guard');
            $prop->setValue($conn, $guard);
        }

        return $conn;
    }

    public function testWriteThroughStillReachesTheRealConnection(): void
    {
        AdminQueryCache::reset();
        $inner = new FakeDatabase();
        $conn = $this->wrap($inner);

        self::assertSame(0, $conn->exec('KILL 42'));
        self::assertSame(['KILL 42'], $inner->execLog(), 'the cap rides around the delegation, it does not replace it');

        AdminQueryCache::reset();
    }

    public function testStalledExecDiesAtTheBudget(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl_alarm is not available');
        }

        $conn = $this->wrap(self::stallingInner(5.0), new StatementTimeout(timeoutSeconds: 1));

        $start = microtime(true);
        try {
            $conn->exec('KILL 42');
            self::fail('the stall must die at the budget');
        } catch (StatementTimeoutException $e) {
            self::assertStringContainsString('timed out after 1 s', $e->getMessage());
        }
        self::assertLessThan(3.0, microtime(true) - $start);
    }

    public function testNoPcntlHostStillCompletesTheDelegation(): void
    {
        // The graceful carve-out: without the alarm layer the wrap must be
        // transparent (transport timeouts are the remaining bound there),
        // not a second failure mode.
        $conn = $this->wrap(
            self::stallingInner(0.05),
            new StatementTimeout(timeoutSeconds: 1, pcntlAvailable: false),
        );

        self::assertSame(1, $conn->exec('KILL 42'));
    }
}

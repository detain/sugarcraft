<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Resilience;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\AdminQueryCache;
use SugarCraft\Query\Admin\AsyncConnection;
use SugarCraft\Query\Admin\Resilience\ReconnectManager;
use SugarCraft\Query\Db\ReconnectException;
use SugarCraft\Query\Db\ConnectionConfig;

/**
 * Tests for ReconnectManager.
 */
final class ReconnectManagerTest extends TestCase
{
    public function testShouldReconnectReturnsTrueForError2002(): void
    {
        $manager = new ReconnectManager();
        $exception = new \PDOException(
            'SQLSTATE[HY000] [2002] Can\'t connect to local MySQL server',
            2002,
        );

        $this->assertTrue($manager->shouldReconnect($exception));
    }

    public function testShouldReconnectReturnsTrueForError2003(): void
    {
        $manager = new ReconnectManager();
        $exception = new \PDOException(
            'SQLSTATE[HY000] [2003] Can\'t connect to MySQL server',
            2003,
        );

        $this->assertTrue($manager->shouldReconnect($exception));
    }

    public function testShouldReconnectReturnsTrueForError2013(): void
    {
        $manager = new ReconnectManager();
        $exception = new \PDOException(
            'SQLSTATE[HY000] [2013] Lost connection to MySQL server during query',
            2013,
        );

        $this->assertTrue($manager->shouldReconnect($exception));
    }

    public function testShouldReconnectReturnsFalseForOtherErrors(): void
    {
        $manager = new ReconnectManager();
        $exception = new \PDOException(
            'SQLSTATE[42000] [1142] SELECT command denied',
            1142,
        );

        $this->assertFalse($manager->shouldReconnect($exception));
    }

    public function testShouldReconnectExtractsCodeFromMessageWhenCodeIsZero(): void
    {
        $manager = new ReconnectManager();
        $exception = new \PDOException(
            'SQLSTATE[HY000] [2002] Can\'t connect to local MySQL server',
            0,
        );

        $this->assertTrue($manager->shouldReconnect($exception));
    }

    public function testAttemptReconnectSucceedsWhenConnectionPings(): void
    {
        $manager = new ReconnectManager();

        $mockDb = $this->createMock(\SugarCraft\Query\Db\DatabaseInterface::class);
        $mockDb->method('ping')->willReturn(true);

        $result = $manager->attemptReconnect(fn () => $mockDb);

        $this->assertTrue($result);
    }

    public function testAttemptReconnectFailsWhenConnectionDoesNotPing(): void
    {
        $manager = new ReconnectManager();

        $mockDb = $this->createMock(\SugarCraft\Query\Db\DatabaseInterface::class);
        $mockDb->method('ping')->willReturn(false);

        $result = $manager->attemptReconnect(fn () => $mockDb);

        $this->assertFalse($result);
    }

    public function testAttemptReconnectFailsWhenConnectionReturnsFalse(): void
    {
        $manager = new ReconnectManager();

        $result = $manager->attemptReconnect(fn () => false);

        $this->assertFalse($result);
    }

    public function testSetAndGetConnectionConfig(): void
    {
        $manager = new ReconnectManager();
        $config = ConnectionConfig::new(
            driver: 'mysql',
            host: 'localhost',
            port: 3306,
            user: 'root',
            pass: 'password',
            dbname: 'test',
            sslMode: 'prefer',
        );

        $manager->setConnectionConfig($config);

        $this->assertSame($config, $manager->lastConnectionConfig());
    }

    public function testLastConnectionConfigReturnsNullInitially(): void
    {
        $manager = new ReconnectManager();

        $this->assertNull($manager->lastConnectionConfig());
    }

    /**
     * The async admin connection's cache key (flavor+DSN+user) does not change
     * across a server restart, so after a successful sync-side reconnect the
     * manager must drop it — otherwise admin polling keeps using a socket that
     * died with the old server process.
     */
    public function testSuccessfulReconnectInvalidatesTheCachedAsyncConnection(): void
    {
        $cache = new AdminQueryCache();
        $stale = $cache->connection('mysql|dsn|user', fn () => $this->createMock(AsyncConnection::class));

        $mockDb = $this->createMock(\SugarCraft\Query\Db\DatabaseInterface::class);
        $mockDb->method('ping')->willReturn(true);
        $manager = new ReconnectManager($cache);

        $this->assertTrue($manager->attemptReconnect(fn () => $mockDb));

        $fresh = $cache->connection('mysql|dsn|user', fn () => $this->createMock(AsyncConnection::class));
        $this->assertNotSame($stale, $fresh, 'reconnect must not keep serving the dead async connection');
    }

    public function testFailedReconnectKeepsTheCachedAsyncConnection(): void
    {
        $cache = new AdminQueryCache();
        $existing = $cache->connection('mysql|dsn|user', fn () => $this->createMock(AsyncConnection::class));

        $manager = new ReconnectManager($cache);

        $this->assertFalse($manager->attemptReconnect(fn () => false));

        // No successful reconnect happened, so nothing marks the async
        // connection stale — it must survive untouched.
        $kept = $cache->connection('mysql|dsn|user', fn () => $this->createMock(AsyncConnection::class));
        $this->assertSame($existing, $kept);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\AdminQueryCache;
use SugarCraft\Query\Admin\AsyncConnection;

/**
 * Guards the cache's two audit-driven contracts: it must be constructible for
 * injection (isolated instances, not just the process-global singleton), and
 * invalidateConnection() must force the lazily-cached async connection to be
 * rebuilt — the connection key encodes only flavor+DSN+user, which does not
 * change across a server restart, so without invalidation a reconnect would
 * keep serving admin queries on a dead socket.
 */
final class AdminQueryCacheTest extends TestCase
{
    protected function setUp(): void
    {
        AdminQueryCache::reset();
    }

    protected function tearDown(): void
    {
        AdminQueryCache::reset();
    }

    public function testInstanceReturnsTheSharedProcessGlobal(): void
    {
        $this->assertSame(AdminQueryCache::instance(), AdminQueryCache::instance());
    }

    public function testDirectlyConstructedInstancesAreIsolated(): void
    {
        $a = new AdminQueryCache();
        $b = new AdminQueryCache();
        $a->store('SELECT 1', [['x' => '1']]);

        $this->assertSame([['x' => '1']], $a->lookup('SELECT 1'));
        $this->assertNull($b->lookup('SELECT 1'));
        $this->assertNull(AdminQueryCache::instance()->lookup('SELECT 1'));
    }

    public function testConnectionIsReusedForTheSameKey(): void
    {
        $cache = new AdminQueryCache();
        $calls = 0;
        $factory = function () use (&$calls): AsyncConnection {
            $calls++;
            return $this->createMock(AsyncConnection::class);
        };

        $first = $cache->connection('mysql|dsn|user', $factory);
        $second = $cache->connection('mysql|dsn|user', $factory);

        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
    }

    public function testInvalidateConnectionForcesTheFactoryToRebuild(): void
    {
        $cache = new AdminQueryCache();
        $calls = 0;
        $factory = function () use (&$calls): AsyncConnection {
            $calls++;
            return $this->createMock(AsyncConnection::class);
        };

        $stale = $cache->connection('mysql|dsn|user', $factory);
        $cache->invalidateConnection();
        $fresh = $cache->connection('mysql|dsn|user', $factory);

        $this->assertNotSame($stale, $fresh, 'the post-invalidation connection must be a new instance');
        $this->assertSame(2, $calls);
    }
}

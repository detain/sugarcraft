<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\AdminQueryCache;
use SugarCraft\Query\Admin\AsyncCachingServerContext;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;

/**
 * AsyncCachingServerContext sits on the admin render path, so reading variables must
 * never trigger a synchronous DB query — a slow SHOW GLOBAL VARIABLES against a
 * remote server used to freeze the whole UI for the duration. It must serve from
 * the (async-filled) cache or return [] and let the admin tick fill it in.
 */
final class AsyncCachingServerContextTest extends TestCase
{
    protected function setUp(): void
    {
        AdminQueryCache::reset();
    }

    protected function tearDown(): void
    {
        AdminQueryCache::reset();
    }

    public function testColdMissReturnsEmptyWithoutQueryingInner(): void
    {
        // An inner whose variable fetches would throw proves they are never called.
        $inner = $this->createMock(ServerContextInterface::class);
        $inner->method('serverVariables')->willThrowException(new \RuntimeException('sync query on render path'));
        $inner->method('statusVariables')->willThrowException(new \RuntimeException('sync query on render path'));

        $ctx = new AsyncCachingServerContext($inner);

        $this->assertSame([], $ctx->serverVariables());
        $this->assertSame([], $ctx->statusVariables());
    }

    public function testPassedInCacheIsServedWithoutQueryingInner(): void
    {
        $inner = $this->createMock(ServerContextInterface::class);
        $inner->expects($this->never())->method('serverVariables');
        $inner->expects($this->never())->method('statusVariables');

        $ctx = new AsyncCachingServerContext(
            $inner,
            cachedStatusVars: ['Uptime' => '42'],
            cachedServerVars: ['max_connections' => '500'],
        );

        $this->assertSame(['max_connections' => '500'], $ctx->serverVariables());
        $this->assertSame(['Uptime' => '42'], $ctx->statusVariables());
    }

    public function testInjectedCacheServesVariablesWithoutTouchingTheGlobal(): void
    {
        $inner = $this->createMock(ServerContextInterface::class);
        $inner->expects($this->never())->method('serverVariables');
        $inner->expects($this->never())->method('statusVariables');

        // getStatusVariables()/getServerVariables() read the fixed keys the
        // admin tick stores under.
        $cache = new AdminQueryCache();
        $cache->store('status', ['Threads_connected' => '7']);
        $cache->store('server', ['max_connections' => '151']);

        $ctx = new AsyncCachingServerContext($inner, cache: $cache);

        $this->assertSame(['Threads_connected' => '7'], $ctx->statusVariables());
        $this->assertSame(['max_connections' => '151'], $ctx->serverVariables());
        // The process-global stayed cold — proof the injected cache was used.
        $this->assertNull(AdminQueryCache::instance()->getStatusVariables());
    }

    public function testVersionAndPluginsColdMissDoNotQueryInner(): void
    {
        // A MySQL-family inner whose version/plugins fetches throw proves the
        // render path never triggers the synchronous SELECT VERSION()/SHOW
        // PLUGINS round-trip that used to freeze the event loop.
        $inner = $this->createMock(ServerContextInterface::class);
        $inner->method('flavor')->willReturn(Flavor::MySQL);
        $inner->method('version')->willThrowException(new \RuntimeException('sync SELECT VERSION() on render path'));
        $inner->method('versionString')->willThrowException(new \RuntimeException('sync SELECT VERSION() on render path'));
        $inner->method('plugins')->willThrowException(new \RuntimeException('sync SHOW PLUGINS on render path'));

        $cache = new AdminQueryCache();
        $ctx = new AsyncCachingServerContext($inner, cache: $cache);

        // Cold miss: zero/empty loading state, never a blocking inner call.
        $this->assertSame(0, $ctx->version()->major);
        $this->assertSame('', $ctx->versionString());
        $this->assertSame([], $ctx->plugins());
    }

    public function testVersionAndPluginsServedFromCache(): void
    {
        $inner = $this->createMock(ServerContextInterface::class);
        $inner->method('flavor')->willReturn(Flavor::MySQL);
        $inner->expects($this->never())->method('version');
        $inner->expects($this->never())->method('versionString');
        $inner->expects($this->never())->method('plugins');

        // The async admin tick stores results under the exact SQL keys the
        // wrapper looks up.
        $cache = new AdminQueryCache();
        $cache->store('SELECT VERSION() AS ver', [['ver' => '8.0.36']]);
        $cache->store('SHOW PLUGINS', [['Name' => 'binlog', 'Status' => 'ACTIVE']]);

        $ctx = new AsyncCachingServerContext($inner, cache: $cache);

        $this->assertSame('8.0.36', $ctx->versionString());
        $this->assertSame(8, $ctx->version()->major);
        $this->assertSame(0, $ctx->version()->minor);
        $this->assertSame(36, $ctx->version()->release);
        $this->assertSame([['Name' => 'binlog', 'Status' => 'ACTIVE']], $ctx->plugins());
    }

    public function testFlavorNeverTakesBlockingVersionPath(): void
    {
        // flavor() must resolve from the inner's pre-set enum without ever
        // reaching the blocking version()/serverVersion() path.
        $inner = $this->createMock(ServerContextInterface::class);
        $inner->method('flavor')->willReturn(Flavor::MariaDB);
        $inner->method('version')->willThrowException(new \RuntimeException('flavor() must not query version'));

        $ctx = new AsyncCachingServerContext($inner, cache: new AdminQueryCache());

        $this->assertSame(Flavor::MariaDB, $ctx->flavor());
    }

    public function testNonMysqlFlavorDelegatesVersionToInner(): void
    {
        // Postgres captures its version at construction (non-blocking), so the
        // wrapper must delegate rather than issue a MySQL-only SELECT VERSION().
        $inner = $this->createMock(ServerContextInterface::class);
        $inner->method('flavor')->willReturn(Flavor::Postgres);
        $inner->method('version')->willReturn(\SugarCraft\Query\Db\Version::parse('PostgreSQL 16.2'));
        $inner->method('versionString')->willReturn('PostgreSQL 16.2 on x86_64');
        $inner->method('plugins')->willReturn([]);

        $ctx = new AsyncCachingServerContext($inner, cache: new AdminQueryCache());

        $this->assertSame(16, $ctx->version()->major);
        $this->assertSame('PostgreSQL 16.2 on x86_64', $ctx->versionString());
        $this->assertSame([], $ctx->plugins());
    }
}

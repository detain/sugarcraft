<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\AdminQueryCache;
use SugarCraft\Query\Admin\CachingServerContext;
use SugarCraft\Query\Admin\ServerContextInterface;

/**
 * CachingServerContext sits on the admin render path, so reading variables must
 * never trigger a synchronous DB query — a slow SHOW GLOBAL VARIABLES against a
 * remote server used to freeze the whole UI for the duration. It must serve from
 * the (async-filled) cache or return [] and let the admin tick fill it in.
 */
final class CachingServerContextTest extends TestCase
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

        $ctx = new CachingServerContext($inner);

        $this->assertSame([], $ctx->serverVariables());
        $this->assertSame([], $ctx->statusVariables());
    }

    public function testPassedInCacheIsServedWithoutQueryingInner(): void
    {
        $inner = $this->createMock(ServerContextInterface::class);
        $inner->expects($this->never())->method('serverVariables');
        $inner->expects($this->never())->method('statusVariables');

        $ctx = new CachingServerContext(
            $inner,
            cachedStatusVars: ['Uptime' => '42'],
            cachedServerVars: ['max_connections' => '500'],
        );

        $this->assertSame(['max_connections' => '500'], $ctx->serverVariables());
        $this->assertSame(['Uptime' => '42'], $ctx->statusVariables());
    }
}

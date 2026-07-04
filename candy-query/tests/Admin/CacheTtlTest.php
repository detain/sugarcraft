<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\AdminQueryCache;
use SugarCraft\Query\Admin\CacheTtl;
use SugarCraft\Query\Admin\PostgresServerContext;
use SugarCraft\Query\Admin\ServerContext;

/**
 * Guards the single-source-of-truth property of the cache TTLs: the freshness
 * windows were previously duplicated as private per-file constants (3.0 / 30.0
 * under three different names), so one copy could drift and silently change
 * refresh behaviour in only one layer. Every consumer must resolve to CacheTtl.
 */
final class CacheTtlTest extends TestCase
{
    public function testStatusWindowIsThreeSecondsAndServerWindowThirty(): void
    {
        $this->assertSame(3.0, CacheTtl::STATUS);
        $this->assertSame(30.0, CacheTtl::SERVER);
        $this->assertLessThan(
            CacheTtl::SERVER,
            CacheTtl::STATUS,
            'live status must refresh more often than near-static server config',
        );
    }

    public function testAdminQueryCacheTtlResolvesToTheSharedStatusWindow(): void
    {
        $ttl = new \ReflectionClassConstant(AdminQueryCache::class, 'TTL');

        $this->assertSame(CacheTtl::STATUS, $ttl->getValue());
    }

    public function testServerContextsResolveToTheSharedWindows(): void
    {
        foreach ([ServerContext::class, PostgresServerContext::class] as $context) {
            $status = new \ReflectionClassConstant($context, 'STATUS_CACHE_TTL');
            $server = new \ReflectionClassConstant($context, 'SERVER_CACHE_TTL');

            $this->assertSame(CacheTtl::STATUS, $status->getValue(), $context);
            $this->assertSame(CacheTtl::SERVER, $server->getValue(), $context);
        }
    }

    public function testAppThrottleReferencesTheSharedConstantNotAMagicNumber(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/App.php');

        $this->assertStringContainsString(
            'CacheTtl::STATUS',
            $source,
            'App::subscriptions() must throttle by the shared status window',
        );
        $this->assertStringNotContainsString(
            '$elapsed < 3.0',
            $source,
            'the admin-fetch throttle must not re-inline the magic 3.0',
        );
    }
}

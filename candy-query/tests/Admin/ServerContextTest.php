<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\CacheTtl;
use SugarCraft\Query\Admin\ServerContext;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;

/**
 * Tests for ServerContext and ServerContextInterface.
 *
 * Uses a fake DatabaseInterface to test behavior without a real MySQL connection.
 */
final class ServerContextTest extends TestCase
{
    private FakeDatabase $db;
    private ServerContext $ctx;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
        $this->ctx = new ServerContext($this->db);
    }

    public function testImplementsServerContextInterface(): void
    {
        $this->assertInstanceOf(ServerContextInterface::class, $this->ctx);
    }

    public function testConnectionReturnsBoundDatabase(): void
    {
        $this->assertSame($this->db, $this->ctx->connection());
    }

    public function testServerVariablesReturnsCachedData(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'version', 'Value' => '8.0.33'],
            ['Variable_name' => 'max_connections', 'Value' => '100'],
        ]);

        $result = $this->ctx->serverVariables();
        $this->assertSame('8.0.33', $result['version']);
        $this->assertSame('100', $result['max_connections']);
    }

    public function testServerVariablesCachesOnFirstCall(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'version', 'Value' => '8.0.33'],
        ]);

        $first = $this->ctx->serverVariables();
        $this->db->setQueryResult([
            ['Variable_name' => 'version', 'Value' => '8.0.36'],
        ]);

        $second = $this->ctx->serverVariables();
        $this->assertSame($first, $second);
    }

    public function testStatusVariablesReturnsDataWithTimestamp(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
            ['Variable_name' => 'Threads_connected', 'Value' => '5'],
        ]);

        $before = microtime(true);
        $result = $this->ctx->statusVariables();
        $after = microtime(true);

        $this->assertSame('3600', $result['Uptime']);
        $this->assertSame('5', $result['Threads_connected']);
        $ts = $this->ctx->statusVariablesTs();
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    public function testStatusVariablesDetectsReset(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '100'],
        ]);

        $this->assertFalse($this->ctx->wasReset());
        $this->ctx->statusVariables();

        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '50'],
        ]);

        $this->ctx->refresh();
        $this->ctx->statusVariables();
        $this->assertTrue($this->ctx->wasReset());
    }

    public function testPluginsReturnsPluginList(): void
    {
        $this->db->setQueryResult([
            ['Name' => 'InnoDB', 'Status' => 'ACTIVE', 'Type' => 'STORAGE ENGINE', 'Library' => null],
            ['Name' => 'binlog', 'Status' => 'ACTIVE', 'Type' => 'BINLOG', 'Library' => null],
        ]);

        $plugins = $this->ctx->plugins();
        $this->assertCount(2, $plugins);
        $this->assertSame('InnoDB', $plugins[0]['Name']);
    }

    public function testPluginsReturnsEmptyOnError(): void
    {
        $this->db->setQueryThrows(new \PDOException('Access denied', 42000));
        $this->assertSame([], $this->ctx->plugins());
    }

    public function testVersionParsing(): void
    {
        $this->db->setServerVersion('MySQL version 8.0.33');

        $version = $this->ctx->version();
        $this->assertSame(8, $version->major);
        $this->assertSame(0, $version->minor);
        $this->assertSame(33, $version->release);
    }

    public function testVersionStringReturnsRaw(): void
    {
        $this->db->setServerVersion('MySQL version 8.0.33');
        $this->assertSame('MySQL version 8.0.33', $this->ctx->versionString());
    }

    public function testFlavorDetection(): void
    {
        $this->db->setServerVersion('MySQL version 8.0.33');
        $this->assertSame(Flavor::MySQL, $this->ctx->flavor());
    }

    public function testServerVariablesGracefulDegradationOnAccessDenied(): void
    {
        $this->db->setQueryThrows(new \PDOException('Access denied for user', 42000));
        $this->assertSame([], $this->ctx->serverVariables());
    }

    public function testStatusVariablesGracefulDegradationOnConnectionError(): void
    {
        $this->db->setQueryThrows(new \PDOException("Can't connect to MySQL server", 2002));
        $this->assertSame([], $this->ctx->statusVariables());
    }

    public function testStatusVariablesGracefulDegradationOnTimeout(): void
    {
        $this->db->setQueryThrows(new \PDOException('Lost connection to MySQL server during query', 2013));
        $this->assertSame([], $this->ctx->statusVariables());
    }

    public function testRefreshClearsAllCaches(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'version', 'Value' => '8.0.33'],
            ['Variable_name' => 'Uptime', 'Value' => '100'],
        ]);

        $this->ctx->serverVariables();
        $this->ctx->statusVariables();
        $this->db->setQueryResult([
            ['Variable_name' => 'version', 'Value' => '8.0.36'],
            ['Variable_name' => 'Uptime', 'Value' => '200'],
        ]);

        $this->ctx->refresh();

        $this->db->setQueryResult([
            ['Variable_name' => 'version', 'Value' => '8.0.36'],
            ['Variable_name' => 'Uptime', 'Value' => '200'],
        ]);

        $this->assertSame('8.0.36', $this->ctx->serverVariables()['version']);
    }

    public function testWasResetIsFalseOnFirstSample(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '100'],
        ]);

        $this->assertFalse($this->ctx->wasReset());
        $this->ctx->statusVariables();
        $this->assertFalse($this->ctx->wasReset());
    }

    public function testVersionCachesAfterFirstAccess(): void
    {
        $this->db->setServerVersion('MySQL version 8.0.33');

        $v1 = $this->ctx->version();
        $this->db->setServerVersion('MySQL version 8.0.36');
        $v2 = $this->ctx->version();

        $this->assertSame($v1, $v2);
    }

    public function testFlavorCachesAfterFirstAccess(): void
    {
        $this->db->setServerVersion('MySQL version 8.0.33');

        $f1 = $this->ctx->flavor();
        $this->db->setServerVersion('MySQL version 8.0.36');
        $f2 = $this->ctx->flavor();

        $this->assertSame($f1, $f2);
    }

    public function testServerVariablesReturnsEmptyArrayOnTableNotFound(): void
    {
        $this->db->setQueryThrows(new \PDOException("Table 'performance_schema.global_variables' doesn't exist", 1146));
        $this->assertSame([], $this->ctx->serverVariables());
    }

    public function testGracefulDegradationError1142(): void
    {
        $this->db->setQueryThrows(new \PDOException('SELECT command denied', 1142));
        $this->assertSame([], $this->ctx->serverVariables());
    }

    public function testGracefulDegradationError1227(): void
    {
        $this->db->setQueryThrows(new \PDOException('Command denied', 1227));
        $this->assertSame([], $this->ctx->serverVariables());
    }

    // ------------------------------------------------------------------
    // E718: the TTL window must actually bound sync queries on the render
    // path, and a failing refresh must serve the last-known snapshot rather
    // than crash or retry-storm.
    // ------------------------------------------------------------------

    public function testStatusVariablesInsideTtlWindowExecutesQueryOnce(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '100'],
        ]);

        $first = $this->ctx->statusVariables();
        $second = $this->ctx->statusVariables();
        $this->ctx->statusVariablesTs();
        $this->ctx->wasReset();

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->db->queryCount('SHOW GLOBAL STATUS'));
    }

    public function testStatusVariablesRefetchesAfterTtlWindowExpires(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '100'],
        ]);
        $this->ctx->statusVariables();

        $this->expireStatusCacheWindow();
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '260'],
        ]);

        $next = $this->ctx->statusVariables();

        $this->assertSame('260', $next['Uptime']);
        $this->assertSame(2, $this->db->queryCount('SHOW GLOBAL STATUS'));
    }

    public function testStatusVariablesServesStaleSnapshotWhenRefreshFails(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '100'],
        ]);
        $stale = $this->ctx->statusVariables();

        // "Gone away" (2006) is NOT an ignorable code: the fetch rethrows.
        $this->db->setQueryThrows(new \PDOException('MySQL server has gone away', 2006));
        $this->expireStatusCacheWindow();

        $served = $this->ctx->statusVariables();
        $this->assertSame($stale, $served, 'failed refresh must fail-open to the last-known snapshot');

        // The failed attempt still stamps the window: no retry storm — the
        // next read answers from the swallowed window without a third query.
        $again = $this->ctx->statusVariables();
        $this->assertSame($stale, $again);
        $this->assertSame(2, $this->db->queryCount('SHOW GLOBAL STATUS'));
    }

    public function testStatusVariablesRethrowsWhenColdFetchFails(): void
    {
        $this->db->setQueryThrows(new \PDOException('MySQL server has gone away', 2006));

        $this->expectException(\PDOException::class);
        $this->ctx->statusVariables();
    }

    public function testServerVariablesInsideTtlWindowExecutesQueryOnce(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'version', 'Value' => '8.0.33'],
        ]);

        $this->ctx->serverVariables();
        $this->ctx->serverVariables();

        $this->assertSame(1, $this->db->queryCount('SHOW GLOBAL VARIABLES'));
    }

    public function testServerVariablesServesStaleSnapshotWhenRefreshFails(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'version', 'Value' => '8.0.33'],
        ]);
        $stale = $this->ctx->serverVariables();

        $this->db->setQueryThrows(new \PDOException('MySQL server has gone away', 2006));
        $this->expireServerCacheWindow();

        $this->assertSame($stale, $this->ctx->serverVariables());
        $this->assertSame(2, $this->db->queryCount('SHOW GLOBAL VARIABLES'));
    }

    public function testServerVariablesRethrowsWhenColdFetchFails(): void
    {
        $this->db->setQueryThrows(new \PDOException('MySQL server has gone away', 2006));

        $this->expectException(\PDOException::class);
        $this->ctx->serverVariables();
    }

    /** Force the status TTL window to expire without sleeping 3 seconds. */
    private function expireStatusCacheWindow(): void
    {
        (new \ReflectionProperty(ServerContext::class, 'statusVariablesTsCache'))
            ->setValue($this->ctx, microtime(true) - CacheTtl::STATUS - 0.1);
    }

    /** Force the server-variables TTL window to expire without sleeping 30s. */
    private function expireServerCacheWindow(): void
    {
        (new \ReflectionProperty(ServerContext::class, 'serverVariablesTsCache'))
            ->setValue($this->ctx, microtime(true) - CacheTtl::SERVER - 0.1);
    }
}

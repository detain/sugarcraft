<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Connections;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\AsyncCachingServerContext;
use SugarCraft\Query\Admin\AdminQueryCache;
use SugarCraft\Query\Admin\Connections\ConnectionsPage;
use SugarCraft\Query\Admin\ServerContext;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Core\Msg\ReloadReportMsg;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

/**
 * Fix B pins: ConnectionsPage must adopt data that lands in the async cache
 * after construction — the frozen filtered-processlist memo and the cold-miss
 * performance_schema probe used to stick forever without a keypress, leaving
 * the pane a dead snapshot of the first (empty) render.
 */
final class ConnectionsPageReloadTest extends TestCase
{
    private const PROBE_SQL = 'SELECT @@performance_schema AS ps';

    /**
     * Verbatim fetchViaPS() query from ProcesslistProvider (out of ownership —
     * mirrored here as the cache key it stores under, not reimplemented).
     */
    private const PS_SQL = <<<'SQL'
SELECT
    t.PROCESSLIST_ID,
    t.THREAD_ID,
    t.PROCESSLIST_USER,
    t.PROCESSLIST_HOST,
    t.PROCESSLIST_DB,
    t.PROCESSLIST_COMMAND,
    t.PROCESSLIST_TIME,
    t.PROCESSLIST_STATE,
    t.PROCESSLIST_INFO,
    t.TYPE,
    COALESCE(a.ATTR_VALUE, '') AS PROCESSLIST_ATTRS
FROM performance_schema.threads t
LEFT JOIN performance_schema.session_connect_attrs a
    ON t.THREAD_ID = a.THREAD_ID
    AND a.ATTR_NAME = 'program_name'
ORDER BY t.PROCESSLIST_TIME DESC
SQL;

    protected function setUp(): void
    {
        AdminQueryCache::reset();
    }

    protected function tearDown(): void
    {
        AdminQueryCache::reset();
    }

    private function asyncContext(?array $status = null): AsyncCachingServerContext
    {
        $inner = new ServerContext(new FakeDatabase(), Flavor::MySQL);

        return new AsyncCachingServerContext($inner, $status, ['max_connections' => '151'], false);
    }

    /**
     * @return array<string, string|int|null>
     */
    private function showRow(string $id, string $user, string $command = 'Query'): array
    {
        return [
            'Id' => $id,
            'User' => $user,
            'Host' => 'localhost',
            'db' => 'my',
            'Command' => $command,
            'Time' => '0',
            'State' => '',
            'Info' => 'SELECT 1',
        ];
    }

    /**
     * @return array<string, string|int|null>
     */
    private function psRow(string $pid, string $threadId, string $user): array
    {
        return [
            'PROCESSLIST_ID' => $pid,
            'THREAD_ID' => $threadId,
            'PROCESSLIST_USER' => $user,
            'PROCESSLIST_HOST' => 'h',
            'PROCESSLIST_DB' => null,
            'PROCESSLIST_COMMAND' => 'Query',
            'PROCESSLIST_TIME' => '0',
            'PROCESSLIST_STATE' => 'x',
            'PROCESSLIST_INFO' => null,
            'TYPE' => 'Foreground',
            'PROCESSLIST_ATTRS' => 'mysql-cli',
        ];
    }

    public function testReloadInvalidatesFrozenProcesslistMemoWithoutKeypress(): void
    {
        $context = $this->asyncContext();
        $page = ConnectionsPage::new($context);

        // Cold render: probe and SHOW both miss → memoized as [].
        $this->assertSame([], $page->filteredProcesslist());

        // Rows land in the cache later (async tick drained the pending SHOW).
        AdminQueryCache::instance()->store('SHOW FULL PROCESSLIST', [$this->showRow('42', 'warmuser')]);

        [$reloaded, $cmd] = $page->update(new ReloadReportMsg());
        $this->assertNull($cmd);
        $this->assertNotSame($page, $reloaded, 'memo invalidation must produce a fresh page instance');

        /** @var ConnectionsPage $reloaded */
        $rows = $reloaded->filteredProcesslist();
        $this->assertCount(1, $rows);
        $this->assertSame('warmuser', $rows[0]->user, 'ReloadReportMsg must surface cache rows without any keypress');
    }

    public function testColdPerformanceSchemaProbeStaysUnknownAndAdoptsTrueAfterReload(): void
    {
        $context = $this->asyncContext();
        $page = ConnectionsPage::new($context);

        // Cold probe answers [] (cache miss) — the provider used to pin that as
        // definitive "no PS"; the SHOW fallback then also misses.
        $this->assertSame([], $page->filteredProcesslist());

        $cache = AdminQueryCache::instance();
        $cache->store(self::PROBE_SQL, [['ps' => '1']]);
        // PROCESSLIST_ID and THREAD_ID differ on purpose: a row carrying
        // threadId 7 could only have come from the PS query, proving the stuck
        // false was re-evaluated (threadId would equal processId via SHOW).
        $cache->store(self::PS_SQL, [$this->psRow('42', '7', 'psuser')]);

        [$reloaded,] = $page->update(new ReloadReportMsg());
        $this->assertInstanceOf(ConnectionsPage::class, $reloaded);

        /** @var ConnectionsPage $reloaded */
        $rows = $reloaded->filteredProcesslist();
        $this->assertCount(1, $rows);
        $this->assertSame('psuser', $rows[0]->user);
        $this->assertTrue($rows[0]->isPS, 're-probe must adopt the true answer instead of the cold-miss false');
        $this->assertSame(7, $rows[0]->threadId);
        $this->assertSame('mysql-cli', $rows[0]->connectionAttr);
    }

    public function testReloadAdoptsLiveStatusVarsAndRebuildsCounters(): void
    {
        $context = $this->asyncContext(['Threads_connected' => '5', 'max_used_connections' => '4']);
        $page = ConnectionsPage::new($context);

        $cache = AdminQueryCache::instance();
        $cache->store('status', ['Threads_connected' => '9', 'max_used_connections' => '8']);
        $cache->store('server', ['max_connections' => '200']);

        [$reloaded, $cmd] = $page->update(new ReloadReportMsg());
        $this->assertNull($cmd);
        $this->assertInstanceOf(ConnectionsPage::class, $reloaded);

        $this->assertSame('9', $reloaded->context->statusVariables()['Threads_connected'] ?? null, 'frozen vars must be replaced by the live cache');
        $contextAfter = $reloaded->context;
        $this->assertInstanceOf(AsyncCachingServerContext::class, $contextAfter);
        $this->assertFalse($contextAfter->isLoading(), 'a landed reload ends the loading state');
        // Rendering the reloaded page must not fatal on the rebuilt counters.
        $this->assertNotEmpty(is_string($reloaded->view()) ? $reloaded->view() : '');
    }
}

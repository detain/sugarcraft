<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\AdminQueryCache;
use SugarCraft\Query\Admin\AsyncCachingServerContext;
use SugarCraft\Query\Admin\Dashboard\DashboardPage;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Core\Msg\ReloadReportMsg;
use SugarCraft\Query\Db\Flavor;

/**
 * Phase 2a Workbench-parity pins: each panel is composed as one frame per
 * metric (chart + labels), the paired counters/meters are folded into their
 * timeline's block instead of rendering standalone, and the spec's label
 * wording ("receiving … kb/s", seven statement verbs, threads/max, donut %)
 * actually reaches the screen.
 */
final class DashboardPageBlocksTest extends TestCase
{
    /**
     * @param array<string, string> $status
     * @param array<string, string> $server
     */
    private function context(array $status, array $server, Flavor $flavor = Flavor::MySQL): AsyncCachingServerContext
    {
        $inner = $this->createMock(ServerContextInterface::class);
        $inner->method('flavor')->willReturn($flavor);
        $inner->method('statusVariablesTs')->willReturn(microtime(true));

        return new AsyncCachingServerContext($inner, $status, $server, false);
    }

    /**
     * Render twice with changed counters so every cell ingests a real sample,
     * bypassing the poll throttle the way the reload path does in production.
     *
     * @param array<string, string> $first
     * @param array<string, string> $second
     * @param array<string, string> $server
     */
    private function renderedAfterTwoPolls(array $first, array $second, array $server = ['max_connections' => '100'], Flavor $flavor = Flavor::MySQL): string
    {
        $page = new DashboardPage($this->context($first, $server, $flavor));
        $page->view();

        $cache = AdminQueryCache::instance();
        $cache->store('status', $second);
        $cache->store('server', $server);

        [$page,] = $page->update(new ReloadReportMsg());
        $this->assertInstanceOf(DashboardPage::class, $page);

        // Pin elapsed to ~2s so rates are finite and the assertion set is
        // timing-independent (the label wording, not its magnitude, is what
        // this test guards).
        $prop = new \ReflectionProperty(DashboardPage::class, 'lastPollAt');
        $prop->setValue($page, microtime(true) - 4.0);

        return $page->view();
    }

    protected function setUp(): void
    {
        AdminQueryCache::reset();
    }

    protected function tearDown(): void
    {
        AdminQueryCache::reset();
    }

    public function testNetworkPanelCarriesReceivingSendingLabelsAndThreadReadout(): void
    {
        $view = $this->renderedAfterTwoPolls(
            ['Bytes_received' => '1000', 'Bytes_sent' => '500', 'Threads_connected' => '10', 'Uptime' => '111'],
            ['Bytes_received' => '62440', 'Bytes_sent' => '31500', 'Threads_connected' => '12', 'Uptime' => '113'],
        );

        $this->assertMatchesRegularExpression('/receiving \d+(\.\d+)? kb\/s/', $view);
        $this->assertMatchesRegularExpression('/sending \d+(\.\d+)? kb\/s/', $view);
        // Exact readout: Threads_connected (StatusVar) vs max_connections, no rate math.
        $this->assertStringContainsString('threads 12 / max 100', $view);
        // The paired counters fold into the timelines: 'Bytes In' captions the
        // block once, and its counter no longer renders as its own row.
        $this->assertSame(1, substr_count($view, 'Bytes In'));
        $this->assertSame(1, substr_count($view, 'Bytes Out'));
    }

    public function testSqlStatementsPanelListsAllSevenVerbLabels(): void
    {
        $view = $this->renderedAfterTwoPolls(
            ['Com_select' => '100', 'Com_insert' => '10', 'Com_update' => '10', 'Com_delete' => '10',
                'Com_create_index' => '0', 'Com_alter_table' => '0', 'Com_drop_table' => '0', 'Uptime' => '1'],
            ['Com_select' => '800', 'Com_insert' => '30', 'Com_update' => '25', 'Com_delete' => '15',
                'Com_create_index' => '4', 'Com_alter_table' => '3', 'Com_drop_table' => '2', 'Uptime' => '3'],
        );

        foreach (['select', 'insert', 'update', 'delete', 'create', 'alter', 'drop'] as $verb) {
            $this->assertStringContainsString($verb, $view, "SQL Statements block must label {$verb}");
        }

        // Verb counters are consumed by the block, never standalone rows.
        $this->assertSame(0, substr_count($view, 'SELECT:'), 'uppercase counter captions must not leak');
        $this->assertSame(1, substr_count($view, 'SQL Statements'));
    }

    public function testRoundWidgetsRenderDonutsWithCenterPercent(): void
    {
        $view = $this->renderedAfterTwoPolls(
            ['Uptime' => '1'],
            ['Uptime' => '2'],
        );

        $this->assertMatchesRegularExpression('/\d+(\.\d+)?%/', $view, 'donut hole must carry the percent readout');
        $this->assertSame(1, substr_count($view, 'Table Open Cache'));
    }

    public function testInnodbBufferPoolDonutCarriesPagesPerSecondLabels(): void
    {
        $view = $this->renderedAfterTwoPolls(
            ['Innodb_buffer_pool_read_requests' => '0', 'Innodb_buffer_pool_write_requests' => '0',
                'Innodb_buffer_pool_pages_total' => '1000', 'Innodb_buffer_pool_bytes_data' => '100', 'Uptime' => '1'],
            ['Innodb_buffer_pool_read_requests' => '200', 'Innodb_buffer_pool_write_requests' => '40',
                'Innodb_buffer_pool_pages_total' => '1000', 'Innodb_buffer_pool_bytes_data' => '100', 'Uptime' => '3'],
        );

        $this->assertMatchesRegularExpression('/reads \S+ pages\/s/', $view);
        $this->assertMatchesRegularExpression('/writes \S+ pages\/s/', $view);
        $this->assertMatchesRegularExpression('/disk reads \S+ \/s/', $view);
        $this->assertSame(1, substr_count($view, 'Buffer Pool Usage'));
    }

    public function testInnodbDiskPanelsLabelRates(): void
    {
        $view = $this->renderedAfterTwoPolls(
            ['Innodb_data_written' => '0', 'Innodb_data_read' => '0', 'Innodb_os_log_bytes_written' => '0', 'Uptime' => '1'],
            ['Innodb_data_written' => '20480', 'Innodb_data_read' => '8192', 'Innodb_os_log_bytes_written' => '1024', 'Uptime' => '3'],
        );

        $this->assertMatchesRegularExpression('/writing \d+(\.\d+)? kb\/s/', $view);
        $this->assertMatchesRegularExpression('/reading \d+(\.\d+)? b\/s/', $view);
    }

    public function testPostgresPanelsStillRenderThroughGenericBlocks(): void
    {
        $view = $this->renderedAfterTwoPolls(
            ['pg_stat_database.tup_fetched' => '10', 'pg_stat_database.tup_returned' => '20',
                'pg_stat_database.xact_commit' => '1', 'pg_stat_database.xact_rollback' => '0',
                'pg_stat_database.numbackends' => '3', 'pg_stat_database.blks_hit' => '9', 'pg_stat_database.blks_read' => '1'],
            ['pg_stat_database.tup_fetched' => '110', 'pg_stat_database.tup_returned' => '220',
                'pg_stat_database.xact_commit' => '11', 'pg_stat_database.xact_rollback' => '2',
                'pg_stat_database.numbackends' => '4', 'pg_stat_database.blks_hit' => '99', 'pg_stat_database.blks_read' => '3'],
            [],
            Flavor::Postgres,
        );

        $this->assertStringContainsString('Transactions', $view);
        // PG keeps its counters as standalone rows (generic blocks), so the
        // caption legitimately appears once per timeline+counter pair.
        $this->assertGreaterThanOrEqual(1, substr_count($view, 'Tuples Fetched'));
        $this->assertStringContainsString('Tuples Returned', $view);
    }

    public function testPostgresFlavorPageRendersWithoutMysqlCaptions(): void
    {
        $page = new DashboardPage($this->context(
            ['pg_stat_database.tup_fetched' => '1'],
            [],
            Flavor::Postgres,
        ));

        $view = $page->view();

        $this->assertStringContainsString('Performance Dashboard', $view);
        $this->assertStringNotContainsString('SQL Statements', $view);
        $this->assertStringNotContainsString('receiving', $view);
    }
}

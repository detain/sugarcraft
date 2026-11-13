<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\Reports;

use PHPUnit\Framework\TestCase;
use ReflectionObject;
use ReflectionProperty;
use SugarCraft\Sql\Admin\AdminQueryCache;
use SugarCraft\Sql\Admin\CacheTtl;
use SugarCraft\Sql\Admin\Reports\ReportsPage;
use SugarCraft\Sql\Admin\ServerContext;
use SugarCraft\Sql\Admin\ServerContextInterface;
use SugarCraft\Sql\Admin\ServerStatus\ServerStatusPage;
use SugarCraft\Sql\Core\Msg\ReloadReportMsg;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\PreparedStatementInterface;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * SQL-P10 (adminpages-015/-016) pins: one arrival formats once — a second
 * ReloadReportMsg over the same raw generation reuses the cached
 * ReportResult instead of re-running formatRows over up to 1,000 rows; the
 * session-constant ROUTINES probe polls once per page/context lifetime; and
 * the sys report legs re-query on the wide digest window, not the 3 s
 * live-status cadence.
 */
final class ArrivalIdentityGuardsTest extends TestCase
{
    /**
     * Report-shaped fake with switchable data generation.
     */
    private function reportDb(): DatabaseInterface
    {
        return new class implements DatabaseInterface {
            public int $generation = 0;
            public bool $throw = false;

            public function tables(): array
            {
                return [];
            }

            public function rows(string $table, int $limit = 100, int $offset = 0): array
            {
                return [];
            }

            public function query(string $sql): array|null
            {
                if (str_contains($sql, 'SHOW FULL TABLES FROM sys')) {
                    return [['Tables_in_sys' => 'x$statement_analysis']];
                }
                if (str_contains($sql, 'x$statement_analysis')) {
                    if ($this->throw) {
                        throw new \RuntimeException('boom');
                    }

                    return [
                        ['query' => 'SELECT g' . $this->generation, 'db' => 'test', 'exec_count' => 1, 'total_latency' => 10, 'rows_sent' => 1],
                    ];
                }

                return [];
            }

            public function lastInsertId(): string|int
            {
                return 0;
            }

            public function quote(string $value): string
            {
                return "'" . addslashes($value) . "'";
            }

            public function exec(string $sql): int
            {
                return 0;
            }

            public function close(): void
            {
            }

            public function serverVersion(): string
            {
                return '8.0.32';
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
                return ['test'];
            }

            public function prepare(string $sql): ?PreparedStatementInterface
            {
                return null;
            }

            public function dsn(): string
            {
                return '';
            }

            public function username(): string
            {
                return '';
            }
        };
    }

    /**
     * @return array{0: ReportsPage, 1: DatabaseInterface}
     */
    private function loadedReportsPage(): array
    {
        $db = $this->reportDb();
        $context = $this->createMock(ServerContextInterface::class);
        $context->method('connection')->willReturn($db);

        $page = ReportsPage::new($context, $db);
        $page->view();
        $page = $page->withSelectReport('x$statement_analysis');
        $this->assertNotNull($page->currentResult(), 'non-vacuity: the first load produced a result');

        return [$page, $db];
    }

    public function testSecondReloadOfOneArrivalReusesTheFormattedResult(): void
    {
        [$page, ] = $this->loadedReportsPage();
        $first = $page->currentResult();

        // App forwards ReloadReportMsg for AdminDataLoadedMsg AND
        // AdminDrainCompletedMsg of the same arrival: the second one must
        // not rebuild the formatted grid.
        [$page, ] = $page->update(new ReloadReportMsg());
        $second = $page->currentResult();

        $this->assertSame($first, $second, 'identical arrival generation keeps the SAME ReportResult object (format ran once)');
    }

    public function testNewDataGenerationBuildsAFreshResult(): void
    {
        [$page, $db] = $this->loadedReportsPage();
        $first = $page->currentResult();

        /** @var DatabaseInterface&object{generation:int} $db */
        $db->generation = 1;
        [$page, ] = $page->update(new ReloadReportMsg());
        $moved = $page->currentResult();

        $this->assertNotSame($first, $moved, 'changed rows must re-format');
        $this->assertStringContainsString('SELECT g1', (string) $moved->rows[0]['query'], 'the fresh generation reached the formatted grid');
    }

    public function testFailedReloadClearsTheGuardSoTheNextArrivalRetries(): void
    {
        [$page, $db] = $this->loadedReportsPage();
        $good = $page->currentResult();
        $this->assertNotNull($good);

        /** @var DatabaseInterface&object{throw:bool} $db */
        $db->throw = true;
        [$page, ] = $page->update(new ReloadReportMsg());
        $this->assertNull($page->currentResult(), 'a failed load surfaces as no-result');
        $this->assertStringContainsString('boom', (new ReflectionProperty(ReportsPage::class, 'errorMessage'))->getValue($page));

        // Same rows are answerable again — the guard was parked by a FAILED
        // load, so the retry must re-verify (not ride the fingerprint).
        $db->throw = false;
        [$page, ] = $page->update(new ReloadReportMsg());
        $this->assertNotNull($page->currentResult(), 'the next arrival re-loads after a failure');
    }

    public function testUnitModeToggleStillReRendersTheSameRows(): void
    {
        [$page, ] = $this->loadedReportsPage();

        $rawPage = $page->withToggleUnitDisplay();
        [$rawPage, ] = $rawPage->update(new ReloadReportMsg());
        $rawResult = $rawPage->currentResult();
        $this->assertNotNull($rawResult, 'raw mode still answers after a reload');

        $back = $rawPage->withToggleUnitDisplay();
        [$back, ] = $back->update(new ReloadReportMsg());
        $this->assertNotNull($back->currentResult(), 'returning to formatted mode re-formats over unchanged rows');
    }

    public function testStoredProgramsPolledOncePerPageLifetime(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult([['Variable_name' => 'Uptime', 'Value' => '3600']]);
        $db->setServerVersion('MySQL version 8.0.33');
        $context = new ServerContext($db, Flavor::MySQL);

        $page = ServerStatusPage::new($context);
        $page->view();
        $afterFirst = $db->queryCount('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA NOT IN (\'information_schema\', \'performance_schema\', \'mysql\') LIMIT 1');
        $page->view();
        $page->view();
        $afterThird = $db->queryCount('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA NOT IN (\'information_schema\', \'performance_schema\', \'mysql\') LIMIT 1');

        $this->assertSame(1, $afterFirst, 'non-vacuity: the first frame asked');
        $this->assertSame(1, $afterThird, 'a session-constant boolean rides the memo across frames');

        // A sibling page (fresh context lifetime, e.g. reconnect rebuild)
        // must re-poll — the memo is per page/context, not process-global.
        $fresh = ServerStatusPage::new(new ServerContext($db, Flavor::MySQL));
        $fresh->view();
        $this->assertSame(2, $db->queryCount('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA NOT IN (\'information_schema\', \'performance_schema\', \'mysql\') LIMIT 1'), 'a new page re-asks');
    }

    public function testReportLegsGetTheWideWindowWhileStatusLegsStayLive(): void
    {
        $cache = AdminQueryCache::instance();
        $reportSql = 'SELECT * FROM `sys`.`x$statement_analysis` LIMIT 1000';
        $statusSql = 'SHOW GLOBAL STATUS';
        $cache->store($reportSql, [['query' => 'SELECT 1']]);
        $cache->store($statusSql, [['Variable_name' => 'Uptime', 'Value' => '1']]);

        $win = new ReflectionObject($cache);
        $windowFor = $win->getMethod('windowFor');
        $windowFor->setAccessible(true);
        $this->assertSame(CacheTtl::REPORT, $windowFor->invoke($cache, $reportSql), 'digest scans join the slow window');
        $this->assertSame(CacheTtl::STATUS, $windowFor->invoke($cache, $statusSql), 'live legs keep the 3 s cadence');
        $this->assertSame(CacheTtl::STATUS, $windowFor->invoke($cache, 'SHOW FULL PROCESSLIST'), 'the processlist mirror stays live');

        // Behavioral half: a 5 s-old entry is fresh for the report shape
        // and stale (pended) for the status shape.
        $storedAt = new ReflectionProperty(AdminQueryCache::class, 'storedAt');
        $stamp = $storedAt->getValue($cache);
        $stamp[$reportSql] = microtime(true) - 5.0;
        $stamp[$statusSql] = microtime(true) - 5.0;
        $storedAt->setValue($cache, $stamp);

        $pending = new ReflectionProperty(AdminQueryCache::class, 'pending');
        $pending->setValue($cache, []);

        $this->assertNotNull($cache->lookup($reportSql));
        $this->assertSame([], $pending->getValue($cache), 'the wide window must not pend a 5 s-old digest leg');

        $this->assertNotNull($cache->lookup($statusSql));
        $this->assertSame([$statusSql => true], $pending->getValue($cache), 'the 3 s window still pends a 5 s-old status leg');
    }

    protected function tearDown(): void
    {
        AdminQueryCache::reset();
    }
}

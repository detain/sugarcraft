<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\Reports;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\Reports\Catalog;
use SugarCraft\Sql\Admin\Reports\ReportsPage;
use SugarCraft\Sql\Admin\ServerContextInterface;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\PreparedStatementInterface;

/**
 * Tests for ReportsPage.
 */
final class ReportsPageTest extends TestCase
{
    private ServerContextInterface $context;
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = $this->createMock(ServerContextInterface::class);
        $this->db = $this->createFakeDatabase();
    }

    private function createFakeDatabase(): DatabaseInterface
    {
        return new class implements DatabaseInterface {
            private array $fakeViews = ['x$statement_analysis', 'x$memory_global_total'];

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
                    $result = [];
                    foreach ($this->fakeViews as $view) {
                        $result[] = ['Tables_in_sys' => $view];
                    }
                    return $result;
                }

                if (str_contains($sql, 'x$statement_analysis')) {
                    return [
                        ['query' => 'SELECT * FROM users', 'db' => 'test', 'exec_count' => 100, 'total_latency' => 1500000000, 'rows_sent' => 500],
                        ['query' => 'SELECT * FROM orders', 'db' => 'test', 'exec_count' => 50, 'total_latency' => 750000000, 'rows_sent' => 200],
                    ];
                }

                if (str_contains($sql, 'x$memory_global_total')) {
                    return [
                        ['total_allocated' => 1073741824, 'total_allocated_formatted' => '1.00 GiB'],
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

            public function dsn(): string { return ''; }
            public function username(): string { return ''; }
        };
    }

    public function testPageCreation(): void
    {
        $page = ReportsPage::new($this->context, $this->db);

        $this->assertInstanceOf(ReportsPage::class, $page);
    }

    /**
     * STEP 3.1 removed sync DB query from validate() — no more sysSchemaExists() call.
     * The page now shows async loading state (spinner + "Loading...") when currentResult
     * is null, rather than an immediate error screen. The actual DB query for reports
     * is queued via AsyncCachedConnection and dispatched on ReloadReportMsg (after
     * AdminDataLoadedMsg). This test verifies the NEW loading-state behavior.
     */
    public function testViewShowsLoadingStateWhenDbUnavailable(): void
    {
        $brokenDb = new class implements DatabaseInterface {
            public function tables(): array { return []; }
            public function rows(string $table, int $limit = 100, int $offset = 0): array { return []; }
            public function query(string $sql): array|null {
                if (str_contains($sql, 'SHOW FULL TABLES FROM sys')) {
                    throw new \PDOException('Table not found');
                }
                return [];
            }
            public function lastInsertId(): string|int { return 0; }
            public function quote(string $value): string { return "'" . addslashes($value) . "'"; }
            public function exec(string $sql): int { return 0; }
            public function close(): void {}
            public function serverVersion(): string { return '8.0.32'; }
            public function driverName(): string { return 'mysql'; }
            public function ping(): bool { return true; }
            public function databases(): array { return ['test']; }
            public function prepare(string $sql): ?PreparedStatementInterface { return null; }
            public function dsn(): string { return ''; }
            public function username(): string { return ''; }
        };

        $this->context->method('connection')->willReturn($brokenDb);

        $page = ReportsPage::new($this->context, $brokenDb);
        $view = $page->view();

        // NEW behavior: validate() makes no sync DB queries → currentResult stays null
        // → renderReportGrid() shows loading spinner + "Loading <report>…" message
        // NOT an error screen about sys schema.
        $this->assertStringContainsString('Loading', $view);
        // The loading indicator must be present (⠋ dot spinner in the output)
        $this->assertStringContainsString('⠋', $view);
    }

    /**
     * adminpages-005: the Bits\Tree label pipeline runs every Node label
     * through Sanitize::controlChars(), which strips the ESC of an embedded
     * SGR and leaves the parameters visible — styled labels painted literal
     * '[1m[38;2;…m' garbage across the left column. Selection must ride as
     * plain-text state (the dot marker), never as bytes the widget will eat.
     */
    public function testCategoryTreeCarriesNoShreddedSgrParameters(): void
    {
        $this->context->method('connection')->willReturn($this->db);
        $page = ReportsPage::new($this->context, $this->db);
        $view = $page->view();

        // Any '[NNm'-shaped parameter run NOT preceded by its ESC byte is the
        // shredded form; a live escape is always ESC-led (Style's own output
        // for the header/grid rides the page un-sanitised).
        $orphans = preg_match_all('/(?<!\x1b)\[[0-9;]*m/', $view);
        $this->assertSame(0, $orphans, 'tree labels must not reach the page as orphan SGR parameters');
    }

    public function testCategoryTreeSelectsByPlainTextMarker(): void
    {
        $this->context->method('connection')->willReturn($this->db);
        $page = ReportsPage::new($this->context, $this->db);
        $view = $page->view();

        // Category roots are plain 'name (count)'; the selected report carries
        // the dot. Together they pin the label contract that replaced styling.
        $this->assertStringContainsString('problems (', $view, 'category root label survives plain');
        $this->assertStringContainsString("\u{25CF} ", $view, 'selected report carries the plain-text dot marker');
    }

    public function testCatalogProperty(): void
    {
        $this->context->method('connection')->willReturn($this->db);

        $page = ReportsPage::new($this->context, $this->db);
        $page->view();

        $catalog = $page->catalog();

        $this->assertInstanceOf(Catalog::class, $catalog);
        $this->assertGreaterThan(0, $catalog->count());
    }

    public function testSelectedCategoryInitiallyNull(): void
    {
        $page = ReportsPage::new($this->context, $this->db);

        $this->assertNull($page->selectedCategory());
    }

    public function testSelectedReportInitiallyNull(): void
    {
        $page = ReportsPage::new($this->context, $this->db);

        $this->assertNull($page->selectedReport());
    }

    public function testSelectedRowIndexInitiallyZero(): void
    {
        $page = ReportsPage::new($this->context, $this->db);

        $this->assertSame(0, $page->selectedRowIndex());
    }

    public function testShowRawValuesInitiallyFalse(): void
    {
        $page = ReportsPage::new($this->context, $this->db);

        $this->assertFalse($page->showRawValues());
    }

    public function testWithNavigateDown(): void
    {
        $page = ReportsPage::new($this->context, $this->db);

        $page = $page->withNavigateDown();

        $this->assertSame(0, $page->selectedRowIndex());
    }

    public function testWithNavigateUpDoesNotGoBelowZero(): void
    {
        $this->context->method('connection')->willReturn($this->db);

        $page = ReportsPage::new($this->context, $this->db);
        $page->view();

        $navigated = $page->withNavigateUp();

        $this->assertSame(0, $navigated->selectedRowIndex());
    }

    public function testWithToggleUnitDisplay(): void
    {
        $page = ReportsPage::new($this->context, $this->db);

        $toggled = $page->withToggleUnitDisplay();

        $this->assertTrue($toggled->showRawValues());
    }

    public function testRunnerAfterView(): void
    {
        $this->context->method('connection')->willReturn($this->db);

        $page = ReportsPage::new($this->context, $this->db);
        $page->view();

        $runner = $page->runner();

        $this->assertNotNull($runner);
    }

    public function testExportToCsvReturnsEmptyStringWhenNoReport(): void
    {
        $page = ReportsPage::new($this->context, $this->db);

        $csv = $page->exportToCsv();

        $this->assertSame('', $csv);
    }

    public function testLastExportCsvIsNullInitially(): void
    {
        $page = ReportsPage::new($this->context, $this->db);

        $this->assertNull($page->lastExportCsv());
    }

    public function testWithExportReturnsNewInstance(): void
    {
        $page = ReportsPage::new($this->context, $this->db);

        $exported = $page->withExport();

        // withExport should return a new instance
        $this->assertNotSame($page, $exported);
    }

    public function testWithExportSetsLastExportCsv(): void
    {
        $page = ReportsPage::new($this->context, $this->db);

        // Without a loaded report, lastExportCsv should be empty string
        $exported = $page->withExport();

        $this->assertNotNull($exported->lastExportCsv());
        $this->assertSame('', $exported->lastExportCsv());
    }

    public function testExportToCsvWithData(): void
    {
        $this->context->method('connection')->willReturn($this->db);

        $page = ReportsPage::new($this->context, $this->db);
        $page->view();

        // Force load the x$statement_analysis report (using the actual report name from catalog)
        $page = $page->withSelectReport('x$statement_analysis');
        $csv = $page->exportToCsv();

        $this->assertNotSame('', $csv);

        $lines = explode("\n", $csv);
        $this->assertGreaterThanOrEqual(2, count($lines));

        // Verify header row contains expected column names
        $header = $lines[0];
        $this->assertStringContainsString('query', $header);
        $this->assertStringContainsString('db', $header);
        $this->assertStringContainsString('exec_count', $header);
    }

    public function testExportToCsvEscapesFormulaInjection(): void
    {
        // Create a fake database that returns formula injection data
        $injectionDb = new class implements DatabaseInterface {
            public function tables(): array { return []; }
            public function rows(string $table, int $limit = 100, int $offset = 0): array { return []; }
            public function query(string $sql): array|null {
                if (str_contains($sql, 'SHOW FULL TABLES FROM sys')) {
                    return [['Tables_in_sys' => 'x$statement_analysis']];
                }
                if (str_contains($sql, 'x$statement_analysis')) {
                    // Formula injection payloads that must be escaped
                    return [
                        ['query' => "=CMD|'/C calc'!A0", 'db' => 'test', 'exec_count' => 100, 'total_latency' => 1500000000, 'rows_sent' => 500],
                        ['query' => '+A1+A2', 'db' => 'test', 'exec_count' => 50, 'total_latency' => 750000000, 'rows_sent' => 200],
                        ['query' => '-SUM(B1:B100)', 'db' => 'test', 'exec_count' => 25, 'total_latency' => 500000000, 'rows_sent' => 100],
                        ['query' => '@HYPERLINK("http://evil.com")', 'db' => 'test', 'exec_count' => 10, 'total_latency' => 100000000, 'rows_sent' => 50],
                        ['query' => '=2+2', 'db' => 'test', 'exec_count' => 5, 'total_latency' => 50000000, 'rows_sent' => 10],
                    ];
                }
                return [];
            }
            public function lastInsertId(): string|int { return 0; }
            public function quote(string $value): string { return "'" . addslashes($value) . "'"; }
            public function exec(string $sql): int { return 0; }
            public function close(): void {}
            public function serverVersion(): string { return '8.0.32'; }
            public function driverName(): string { return 'mysql'; }
            public function ping(): bool { return true; }
            public function databases(): array { return ['test']; }
            public function prepare(string $sql): ?PreparedStatementInterface { return null; }
            public function dsn(): string { return ''; }
            public function username(): string { return ''; }
        };

        $this->context->method('connection')->willReturn($injectionDb);

        $page = ReportsPage::new($this->context, $injectionDb);
        $page->view();
        $page = $page->withSelectReport('x$statement_analysis');

        $csv = $page->exportToCsv();

        $this->assertNotSame('', $csv);

        $lines = explode("\n", $csv);
        $this->assertGreaterThanOrEqual(2, count($lines));

        // The CSV should contain escaped formula values with leading single quote preserved
        // When a value starts with =, +, -, or @, it gets prefixed with '
        // When exported to CSV, this appears as: "'=CMD|'/C calc'!A0"
        $this->assertStringContainsString("'=CMD|'/C calc'!A0", $csv);
        $this->assertStringContainsString("'+A1+A2", $csv);
        $this->assertStringContainsString("'-SUM(B1:B100)", $csv);
        $this->assertStringContainsString("'@HYPERLINK", $csv);
        $this->assertStringContainsString("'=2+2", $csv);

        // Verify unescaped formula characters do not appear at start of unquoted cells
        foreach ($lines as $line) {
            // Skip header line
            if (str_contains($line, 'query') && str_contains($line, 'db')) {
                continue;
            }
            // Data lines should have all formula prefixes escaped
            $cells = str_getcsv($line);
            foreach ($cells as $cell) {
                if ($cell === null || $cell === '') {
                    continue;
                }
                $firstChar = $cell[0] ?? '';
                $this->assertFalse(
                    in_array($firstChar, ['=', '+', '-', '@'], true),
                    "CSV cell starts with formula-injection character: " . substr((string) $cell, 0, 20)
                );
            }
        }
    }

    public function testSelectedColumnIndexInitiallyZero(): void
    {
        $page = ReportsPage::new($this->context, $this->db);

        $this->assertSame(0, $page->selectedColumnIndex());
    }

    public function testWithSelectPrevCategory(): void
    {
        $this->context->method('connection')->willReturn($this->db);

        $page = ReportsPage::new($this->context, $this->db);
        $page->view();

        // Starting from first category, prev should wrap to last
        $prevPage = $page->withSelectPrevCategory();
        $categories = $prevPage->catalog()->categories();
        $this->assertSame($categories[count($categories) - 1], $prevPage->selectedCategory());
    }

    public function testWithSelectNextCategory(): void
    {
        $this->context->method('connection')->willReturn($this->db);

        $page = ReportsPage::new($this->context, $this->db);
        $page->view();

        // Starting from first category, next should go to second
        $categories = $page->catalog()->categories();
        $nextPage = $page->withSelectNextCategory();
        $this->assertSame($categories[1], $nextPage->selectedCategory());
    }

    public function testWithSelectPrevReport(): void
    {
        $this->context->method('connection')->willReturn($this->db);

        $page = ReportsPage::new($this->context, $this->db);
        $page->view();

        // With a report selected, prev should go to the previous one (wrapping to last)
        $prevPage = $page->withSelectPrevReport();
        $this->assertNotNull($prevPage->selectedReport());
    }

    public function testWithSelectNextReport(): void
    {
        $this->context->method('connection')->willReturn($this->db);

        $page = ReportsPage::new($this->context, $this->db);
        $page->view();

        // With a report selected, next should go to the next one (wrapping to first)
        $nextPage = $page->withSelectNextReport();
        $this->assertNotNull($nextPage->selectedReport());
    }

    public function testWithSelectPrevColumn(): void
    {
        $page = ReportsPage::new($this->context, $this->db);

        // When no report is loaded, should return same page
        $prevPage = $page->withSelectPrevColumn();
        $this->assertSame(0, $prevPage->selectedColumnIndex());
    }

    public function testWithSelectNextColumn(): void
    {
        $page = ReportsPage::new($this->context, $this->db);

        // When no report is loaded, should return same page
        $nextPage = $page->withSelectNextColumn();
        $this->assertSame(0, $nextPage->selectedColumnIndex());
    }

    // ─── SQL-P14 (adminpages-025): pane seeds make the rows real ───────────

    public function testQueryStatsAndTableStatsProbesDiffer(): void
    {
        $this->context->method('connection')->willReturn($this->db);

        $query = ReportsPage::new($this->context, $this->db, ReportsPage::seedCategoryFor(\SugarCraft\Sql\Admin\AdminPane::QueryStats), 'Query Stats');
        $query->view();
        $table = ReportsPage::new($this->context, $this->db, ReportsPage::seedCategoryFor(\SugarCraft\Sql\Admin\AdminPane::TableStats), 'Table Stats');
        $table->view();

        $this->assertSame('problems', $query->selectedCategory(), 'Query Stats opens on the statement-problems family');
        $this->assertSame('schema', $table->selectedCategory(), 'Table Stats opens on the schema/tables family');
        $this->assertNotSame($query->selectedReport(), $table->selectedReport(), 'the two digits land on different reports');

        $queryView = $query->view();
        $tableView = $table->view();
        $this->assertStringContainsString('Query Stats', $queryView, 'the header answers what was pressed');
        $this->assertStringContainsString('Table Stats', $tableView);
        $this->assertStringNotContainsString('Table Stats', $queryView, 'the headers do not bleed across panes');
    }

    public function testUnseededPageKeepsTheLegacyFirstCategory(): void
    {
        $this->context->method('connection')->willReturn($this->db);

        $page = ReportsPage::new($this->context, $this->db);
        $page->view();

        $this->assertNotNull($page->selectedCategory(), 'non-vacuity: catalog seeded a default');
        $this->assertStringNotContainsString('·', $page->view(), 'no pane label, no decorated header (embedders unchanged)');
    }

    public function testForeignSeedFallsBackToTheFirstCategory(): void
    {
        $this->context->method('connection')->willReturn($this->db);

        $page = ReportsPage::new($this->context, $this->db, 'no-such-category');
        $page->view();

        $this->assertSame('problems', $page->selectedCategory(), 'an unavailable seed must not blank the tree');
    }
}

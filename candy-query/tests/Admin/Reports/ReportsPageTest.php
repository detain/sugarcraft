<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Reports;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Reports\Catalog;
use SugarCraft\Query\Admin\Reports\ReportsPage;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\PreparedStatementInterface;

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

            public function rows(string $table, int $limit = 100): array
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
     * is queued via CachedConnection and dispatched on ReloadReportMsg (after
     * AdminDataLoadedMsg). This test verifies the NEW loading-state behavior.
     */
    public function testViewShowsLoadingStateWhenDbUnavailable(): void
    {
        $brokenDb = new class implements DatabaseInterface {
            public function tables(): array { return []; }
            public function rows(string $table, int $limit = 100): array { return []; }
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
            public function rows(string $table, int $limit = 100): array { return []; }
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
}

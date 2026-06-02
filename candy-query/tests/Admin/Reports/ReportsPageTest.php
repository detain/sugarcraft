<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Reports;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Reports\Catalog;
use SugarCraft\Query\Admin\Reports\ReportsPage;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\DatabaseInterface;

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

            public function query(string $sql): array
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

            public function prepare(string $sql): mixed
            {
                return false;
            }

            public function dsn(): string { return ''; }
            public function username(): string { return ''; }
            public function password(): string { return ''; }
        };
    }

    public function testPageCreation(): void
    {
        $page = ReportsPage::new($this->context, $this->db);

        $this->assertInstanceOf(ReportsPage::class, $page);
    }

    public function testViewShowsErrorWhenSysSchemaNotAvailable(): void
    {
        $brokenDb = new class implements DatabaseInterface {
            public function tables(): array { return []; }
            public function rows(string $table, int $limit = 100): array { return []; }
            public function query(string $sql): array {
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
            public function prepare(string $sql): mixed { return false; }
            public function dsn(): string { return ''; }
            public function username(): string { return ''; }
            public function password(): string { return ''; }
        };

        $this->context->method('connection')->willReturn($brokenDb);

        $page = ReportsPage::new($this->context, $brokenDb);
        $view = $page->view();

        $this->assertStringContainsString('sys schema', $view);
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
}

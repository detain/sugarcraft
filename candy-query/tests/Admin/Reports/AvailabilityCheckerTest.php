<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Reports;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Reports\AvailabilityChecker;
use SugarCraft\Query\Admin\Reports\Catalog;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\PreparedStatementInterface;

/**
 * Tests for AvailabilityChecker sys schema discovery.
 */
final class AvailabilityCheckerTest extends TestCase
{
    private Catalog $catalog;
    private DatabaseInterface $db;
    private array $fakeViews = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = Catalog::new(__DIR__ . '/../../../data');
        $this->catalog->load();
        $this->fakeViews = ['x$statement_analysis', 'x$memory_global_total'];
        $this->db = $this->createFakeDatabase($this->fakeViews);
    }

    private function createFakeDatabase(array $views): DatabaseInterface
    {
        return new class($views) implements DatabaseInterface {
            private array $views;

            public function __construct(array $views)
            {
                $this->views = $views;
            }

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
                    foreach ($this->views as $view) {
                        $result[] = ['Tables_in_sys' => $view];
                    }
                    return $result;
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

    public function testDiscoverViews(): void
    {
        $checker = AvailabilityChecker::new($this->db);

        $views = $checker->discoverViews();

        $this->assertSame(['x$statement_analysis', 'x$memory_global_total'], $views);
    }

    public function testDiscoverViewsCachesResult(): void
    {
        $checker = AvailabilityChecker::new($this->db);

        $views1 = $checker->discoverViews();
        $views2 = $checker->discoverViews();

        $this->assertSame($views1, $views2);
    }

    public function testSysSchemaExists(): void
    {
        $checker = AvailabilityChecker::new($this->db);

        $this->assertTrue($checker->sysSchemaExists());
    }

    public function testSysSchemaNotExistsWhenQueryFails(): void
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
        $checker = AvailabilityChecker::new($brokenDb);

        $this->assertFalse($checker->sysSchemaExists());
    }

    public function testIsViewAvailable(): void
    {
        $checker = AvailabilityChecker::new($this->db);

        $this->assertTrue($checker->isViewAvailable('x$statement_analysis'));
        $this->assertFalse($checker->isViewAvailable('nonexistent_view'));
    }

    public function testAvailableFromCatalog(): void
    {
        $checker = AvailabilityChecker::new($this->db);

        $available = $checker->availableFromCatalog($this->catalog);

        $this->assertNotEmpty($available);
        foreach ($available as $report) {
            $this->assertTrue(
                in_array($report->name, $this->fakeViews, true),
                "Expected {$report->name} to be in fake views"
            );
        }
    }

    public function testMissingFromCatalog(): void
    {
        $checker = AvailabilityChecker::new($this->db);

        $missing = $checker->missingFromCatalog($this->catalog);

        $this->assertNotEmpty($missing);
        foreach ($missing as $viewName) {
            $this->assertFalse(
                in_array($viewName, $this->fakeViews, true),
                "Expected {$viewName} to NOT be in fake views"
            );
        }
    }

    public function testAvailableInCategory(): void
    {
        $checker = AvailabilityChecker::new($this->db);

        $memoryReports = $checker->availableInCategory($this->catalog, 'memory');

        $this->assertNotEmpty($memoryReports);
        foreach ($memoryReports as $report) {
            $this->assertSame('memory', $report->category);
            $this->assertTrue(
                in_array($report->name, $this->fakeViews, true),
                "Expected {$report->name} to be in fake views"
            );
        }
    }

    public function testAvailableInCategoryNoMatches(): void
    {
        $checker = AvailabilityChecker::new($this->db);

        $reports = $checker->availableInCategory($this->catalog, 'problems');

        foreach ($reports as $report) {
            $this->assertSame('problems', $report->category);
            $this->assertTrue(
                in_array($report->name, $this->fakeViews, true),
                "Expected {$report->name} to be in fake views"
            );
        }
    }

    public function testResetClearsCache(): void
    {
        $checker = AvailabilityChecker::new($this->db);

        $checker->discoverViews();
        $checker->reset();

        $this->assertSame(['x$statement_analysis', 'x$memory_global_total'], $checker->discoverViews());
    }
}

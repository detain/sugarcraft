<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Reports;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Reports\AvailabilityChecker;
use SugarCraft\Query\Admin\Reports\Catalog;
use SugarCraft\Query\Admin\Reports\ReportResult;
use SugarCraft\Query\Admin\Reports\ReportRunner;
use SugarCraft\Query\Db\DatabaseInterface;

/**
 * Tests for ReportRunner execution.
 */
final class ReportRunnerTest extends TestCase
{
    private Catalog $catalog;
    private DatabaseInterface $db;
    private AvailabilityChecker $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = Catalog::new(__DIR__ . '/../../../data');
        $this->catalog->load();
        $this->db = $this->createFakeDatabase();
        $this->availability = AvailabilityChecker::new($this->db);
    }

    private function createFakeDatabase(): DatabaseInterface
    {
        return new class implements DatabaseInterface {
            private array $fakeRows = [
                'x$statement_analysis' => [
                    ['query' => 'SELECT * FROM users', 'db' => 'test', 'exec_count' => 100, 'total_latency' => 1500000000, 'rows_sent' => 500],
                    ['query' => 'SELECT * FROM orders', 'db' => 'test', 'exec_count' => 50, 'total_latency' => 750000000, 'rows_sent' => 200],
                ],
                'x$memory_global_total' => [
                    ['total_allocated' => 1073741824, 'total_allocated_formatted' => '1.00 GiB'],
                ],
            ];

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
                    return [
                        ['Tables_in_sys' => 'x$statement_analysis'],
                        ['Tables_in_sys' => 'x$memory_global_total'],
                    ];
                }

                if (str_contains($sql, 'x$statement_analysis')) {
                    $limit = $this->extractLimit($sql);
                    return array_slice($this->fakeRows['x$statement_analysis'], 0, $limit);
                }

                if (str_contains($sql, 'x$memory_global_total')) {
                    return $this->fakeRows['x$memory_global_total'];
                }

                return [];
            }

            private function extractLimit(string $sql): int
            {
                if (preg_match('/LIMIT\s+(\d+)/i', $sql, $matches)) {
                    return (int) $matches[1];
                }
                return 100;
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
        };
    }

    public function testRunFormattedReport(): void
    {
        $runner = ReportRunner::new($this->db, $this->catalog, $this->availability);

        $result = $runner->run('x$statement_analysis');

        $this->assertInstanceOf(ReportResult::class, $result);
        $this->assertSame('x$statement_analysis', $result->report->name);
        $this->assertSame(2, $result->rowCount);
        $this->assertFalse($result->limited);
    }

    public function testRunFormattedTimeColumns(): void
    {
        $runner = ReportRunner::new($this->db, $this->catalog, $this->availability);

        $result = $runner->run('x$statement_analysis');

        $firstRow = $result->firstRow();
        $this->assertNotNull($firstRow);

        $this->assertStringContainsString('s', $firstRow['total_latency']);
    }

    public function testRunRawReport(): void
    {
        $runner = ReportRunner::new($this->db, $this->catalog, $this->availability);

        $result = $runner->runRaw('x$statement_analysis');

        $this->assertInstanceOf(ReportResult::class, $result);
        $this->assertSame(1500000000, $result->firstRow()['total_latency']);
    }

    public function testRunThrowsOnInvalidReport(): void
    {
        $runner = ReportRunner::new($this->db, $this->catalog, $this->availability);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Report not found in catalog');

        $runner->run('nonexistent_report');
    }

    public function testRunThrowsOnUnavailableView(): void
    {
        $runner = ReportRunner::new($this->db, $this->catalog, $this->availability);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('View not available on this server');

        $runner->run('schema_object_overview');
    }

    public function testCanRun(): void
    {
        $runner = ReportRunner::new($this->db, $this->catalog, $this->availability);

        $this->assertTrue($runner->canRun('x$statement_analysis'));
        $this->assertFalse($runner->canRun('nonexistent_report'));
        $this->assertFalse($runner->canRun('schema_object_overview'));
    }

    public function testReportResultFirstRow(): void
    {
        $runner = ReportRunner::new($this->db, $this->catalog, $this->availability);

        $result = $runner->run('x$statement_analysis');

        $firstRow = $result->firstRow();
        $this->assertNotNull($firstRow);
        $this->assertSame('SELECT * FROM users', $firstRow['query']);
    }

    public function testReportResultIsEmpty(): void
    {
        $runner = ReportRunner::new($this->db, $this->catalog, $this->availability);

        $result = $runner->run('x$memory_global_total');

        $this->assertFalse($result->isEmpty());
    }

    public function testReportResultColumnNames(): void
    {
        $runner = ReportRunner::new($this->db, $this->catalog, $this->availability);

        $result = $runner->run('x$statement_analysis');

        $this->assertContains('query', $result->columnNames());
        $this->assertContains('exec_count', $result->columnNames());
        $this->assertContains('total_latency', $result->columnNames());
    }

    public function testReportResultTimeColumns(): void
    {
        $runner = ReportRunner::new($this->db, $this->catalog, $this->availability);

        $result = $runner->run('x$statement_analysis');

        $this->assertContains('total_latency', $result->timeColumns());
    }

    public function testReportResultByteColumns(): void
    {
        $runner = ReportRunner::new($this->db, $this->catalog, $this->availability);

        $result = $runner->run('x$memory_global_total');

        $this->assertContains('total_allocated', $result->byteColumns());
    }

    public function testRunWithLimit(): void
    {
        $runner = ReportRunner::new($this->db, $this->catalog, $this->availability, 1);

        $result = $runner->run('x$statement_analysis', 1);

        $this->assertSame(1, $result->rowCount);
        $this->assertTrue($result->limited);
    }
}

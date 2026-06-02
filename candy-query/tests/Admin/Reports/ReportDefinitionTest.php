<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Reports;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Reports\ColumnDefinition;
use SugarCraft\Query\Admin\Reports\ColumnType;
use SugarCraft\Query\Admin\Reports\ReportDefinition;

/**
 * Tests for ReportDefinition value object.
 */
final class ReportDefinitionTest extends TestCase
{
    private ReportDefinition $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->report = new ReportDefinition(
            name: 'x$statement_analysis',
            category: 'problems',
            caption: 'Statement Analysis',
            description: 'Aggregated statistics of normalized statements.',
            query: 'SELECT * FROM sys.x$statement_analysis',
            columns: [
                new ColumnDefinition('query', ColumnType::String, 60),
                new ColumnDefinition('db', ColumnType::String, 20),
                new ColumnDefinition('exec_count', ColumnType::Bigint, 12),
                new ColumnDefinition('total_latency', ColumnType::Time, 15),
                new ColumnDefinition('rows_sent', ColumnType::Bigint, 12),
            ],
        );
    }

    public function testName(): void
    {
        $this->assertSame('x$statement_analysis', $this->report->name);
    }

    public function testCategory(): void
    {
        $this->assertSame('problems', $this->report->category);
    }

    public function testCaption(): void
    {
        $this->assertSame('Statement Analysis', $this->report->caption);
    }

    public function testDescription(): void
    {
        $this->assertSame('Aggregated statistics of normalized statements.', $this->report->description);
    }

    public function testQuery(): void
    {
        $this->assertSame('SELECT * FROM sys.x$statement_analysis', $this->report->query);
    }

    public function testColumns(): void
    {
        $this->assertCount(5, $this->report->columns);
    }

    public function testHasColumn(): void
    {
        $this->assertTrue($this->report->hasColumn('query'));
        $this->assertTrue($this->report->hasColumn('exec_count'));
        $this->assertFalse($this->report->hasColumn('nonexistent_column'));
    }

    public function testColumn(): void
    {
        $col = $this->report->column('exec_count');

        $this->assertInstanceOf(ColumnDefinition::class, $col);
        $this->assertSame('exec_count', $col->name);
        $this->assertSame(ColumnType::Bigint, $col->type);
        $this->assertSame(12, $col->width);
    }

    public function testColumnNotFound(): void
    {
        $col = $this->report->column('nonexistent_column');

        $this->assertNull($col);
    }

    public function testColumnNames(): void
    {
        $names = $this->report->columnNames();

        $this->assertSame(['query', 'db', 'exec_count', 'total_latency', 'rows_sent'], $names);
    }

    public function testTimeColumns(): void
    {
        $timeCols = $this->report->timeColumns();

        $this->assertSame(['total_latency'], $timeCols);
    }

    public function testByteColumns(): void
    {
        $byteCols = $this->report->byteColumns();

        $this->assertSame([], $byteCols);
    }

    public function testColumnDefinitionIsTime(): void
    {
        $timeCol = new ColumnDefinition('latency', ColumnType::Time, 15);
        $intCol = new ColumnDefinition('count', ColumnType::Int, 10);

        $this->assertTrue($timeCol->isTime());
        $this->assertFalse($intCol->isTime());
    }

    public function testColumnDefinitionIsBytes(): void
    {
        $bytesCol = new ColumnDefinition('size', ColumnType::Bytes, 15);
        $intCol = new ColumnDefinition('count', ColumnType::Int, 10);

        $this->assertTrue($bytesCol->isBytes());
        $this->assertFalse($intCol->isBytes());
    }

    public function testColumnDefinitionIsNumeric(): void
    {
        $intCol = new ColumnDefinition('count', ColumnType::Int, 10);
        $bigintCol = new ColumnDefinition('count', ColumnType::Bigint, 12);
        $floatCol = new ColumnDefinition('rate', ColumnType::Float, 10);
        $stringCol = new ColumnDefinition('name', ColumnType::String, 20);

        $this->assertTrue($intCol->isNumeric());
        $this->assertTrue($bigintCol->isNumeric());
        $this->assertTrue($floatCol->isNumeric());
        $this->assertFalse($stringCol->isNumeric());
    }

    public function testColumnDefinitionWidth(): void
    {
        $col = new ColumnDefinition('name', ColumnType::String, 30);

        $this->assertSame(30, $col->width);
    }

    public function testEmptyReportWithNoTimeOrByteColumns(): void
    {
        $report = new ReportDefinition(
            name: 'test',
            category: 'test',
            caption: 'Test',
            description: 'Test report',
            query: 'SELECT 1',
            columns: [
                new ColumnDefinition('id', ColumnType::Int, 10),
                new ColumnDefinition('name', ColumnType::String, 30),
            ],
        );

        $this->assertSame([], $report->timeColumns());
        $this->assertSame([], $report->byteColumns());
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\{Column, Row, RowData, Table};
use SugarCraft\Testing\Snapshot\Assertions;
use PHPUnit\Framework\TestCase;

/**
 * Golden-file snapshot tests for ANSI rendering output.
 *
 * These tests capture the byte-exact output of render() methods
 * to detect unintended changes to terminal output.
 */
final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    public function testTableBasicRendersAnsi(): void
    {
        $table = Table::withColumns([
                Column::new('id', 'ID', 4),
                Column::new('name', 'Name', 10),
            ])
            ->withRows([
                Row::new(RowData::from(['id' => '1', 'name' => 'Alice'])),
                Row::new(RowData::from(['id' => '2', 'name' => 'Bob'])),
            ]);

        $output = $table->View();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/table-basic.golden',
            $output,
        );
    }

    public function testTableWithZebraStripesRendersAnsi(): void
    {
        $table = Table::withColumns([
                Column::new('id', 'ID', 4),
                Column::new('name', 'Name', 10),
            ])
            ->withRows([
                Row::new(RowData::from(['id' => '1', 'name' => 'Alice'])),
                Row::new(RowData::from(['id' => '2', 'name' => 'Bob'])),
                Row::new(RowData::from(['id' => '3', 'name' => 'Carol'])),
            ])
            ->withZebra(true);

        $output = $table->View();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/table-zebra.golden',
            $output,
        );
    }
}

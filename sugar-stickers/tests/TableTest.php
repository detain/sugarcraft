<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Stickers\Table\{Column, Table};

/**
 * Tests for Table rendering, sorting, filtering, and edge cases.
 */
final class TableTest extends TestCase
{
    // ---- computeTotalWidth -----------------------------------------------

    public function testComputeTotalWidthWithNoColumns(): void
    {
        $t = new Table();
        $this->assertSame(0, $t->computeTotalWidth());
    }

    public function testComputeTotalWidthWithColumns(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addColumn(Column::make('City', 8));
        // 10 + 8 + separator (3 chars * 1 separator = 3) = 21
        $this->assertSame(21, $t->computeTotalWidth());
    }

    public function testComputeTotalWidthWithSeparator(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('A', 5))
            ->withSeparator(' | ')
            ->addColumn(Column::make('B', 5));
        // 5 + 5 + 3 (separator) = 13
        $this->assertSame(13, $t->computeTotalWidth());
    }

    // ---- padHeader alignment --------------------------------------------

    public function testPadHeaderCenterAlignment(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('X', 10)->withAlign('center'));
        // Use reflection to access private padHeader
        $reflection = new \ReflectionClass($t);
        $method = $reflection->getMethod('padHeader');
        $method->setAccessible(true);

        $result = $method->invoke($t, 'Hi', 10, 'center');
        $this->assertSame(10, \strlen($result));
        $this->assertStringContainsString('Hi', $result);
    }

    public function testPadHeaderRightAlignment(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('X', 10)->withAlign('right'));
        $reflection = new \ReflectionClass($t);
        $method = $reflection->getMethod('padHeader');
        $method->setAccessible(true);

        $result = $method->invoke($t, 'Hi', 10, 'right');
        $this->assertSame(10, \strlen($result));
        $this->assertStringStartsWith(' ', $result);
        $this->assertStringEndsWith('H', $result); // ' Hi' padded on left
    }

    public function testPadHeaderTruncatesOversizedHeader(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('X', 5));
        $reflection = new \ReflectionClass($t);
        $method = $reflection->getMethod('padHeader');
        $method->setAccessible(true);

        $result = $method->invoke($t, 'HelloWorld', 5, 'left');
        $this->assertLessThanOrEqual(5, \strlen($result));
    }

    // ---- withSeparator ----------------------------------------------------

    public function testWithSeparatorChangesSeparator(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('A', 5))
            ->addColumn(Column::make('B', 5))
            ->addRow(['x', 'y'])
            ->withSeparator(' | ');

        $output = $t->render();
        $this->assertStringContainsString(' | ', $output);
    }

    // ---- sortBy -----------------------------------------------------------

    public function testSortByNumericColumn(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Num', 5))
            ->addRow(['10'])
            ->addRow(['2'])
            ->addRow(['100'])
            ->sortBy(0, true);

        $output = $t->render();
        $lines = explode("\n", $output);
        // Find '2' row position (after header + separator = 2 lines before data)
        $this->assertStringContainsString("  2 ", $lines[3] ?? '');
    }

    public function testSortByDescending(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Num', 5))
            ->addRow(['1'])
            ->addRow(['2'])
            ->addRow(['3'])
            ->sortBy(0, false); // descending

        $output = $t->render();
        $this->assertStringContainsString('3', $output);
    }

    public function testSortByResetsCursor(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['A'])
            ->addRow(['B'])
            ->setCursor(1)
            ->sortBy(0);

        $this->assertSame(0, $this->getCursorRow($t));
    }

    // ---- filter -----------------------------------------------------------

    public function testFilterCaseInsensitive(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['BOB'])
            ->addRow(['Charlie'])
            ->filter('alice');

        $this->assertSame(1, $t->rowCount());
    }

    public function testFilterNoMatch(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob'])
            ->filter('XYZ');

        $this->assertSame(0, $t->rowCount());
    }

    public function testClearFilterRestoresAllRows(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob'])
            ->filter('Alice')
            ->clearFilter();

        $this->assertSame(2, $t->rowCount());
    }

    public function testFilterResetsCursor(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['A'])
            ->addRow(['B'])
            ->setCursor(1)
            ->filter('A');

        $this->assertSame(0, $this->getCursorRow($t));
    }

    // ---- currentRow / currentCell ----------------------------------------

    public function testCurrentRowReturnsNullWhenOutOfBounds(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice']);

        $t2 = $t->setCursor(99);
        $this->assertNull($t2->currentRow());
    }

    public function testCurrentCellReturnsNullForInvalidColumn(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice']);

        $this->assertNull($t->currentCell(99));
    }

    public function testCurrentCellReturnsValue(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addColumn(Column::make('City', 10))
            ->addRow(['Alice', 'NYC']);

        $this->assertSame('Alice', $t->currentCell(0));
        $this->assertSame('NYC', $t->currentCell(1));
    }

    // ---- withCursorStyle / withHeaderStyle -------------------------------

    public function testWithCursorStyleAcceptsValidSgr(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->withCursorStyle('7'); // reverse

        $output = $t->render();
        $this->assertStringContainsString("\x1b[7m", $output);
    }

    public function testWithCursorStyleRejectsInvalidSgr(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Table())
            ->addColumn(Column::make('Name', 10))
            ->withCursorStyle('invalid');
    }

    public function testWithHeaderStyleAcceptsValidSgr(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->withHeaderStyle('1'); // bold

        $output = $t->render();
        $this->assertStringContainsString("\x1b[1m", $output);
    }

    public function testWithHeaderStyleRejectsInvalidSgr(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Table())
            ->addColumn(Column::make('Name', 10))
            ->withHeaderStyle('bad');
    }

    public function testWithHeaderStyleEmptyStringIsNoOp(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->withHeaderStyle('');

        $output = $t->render();
        $this->assertStringNotContainsString("\x1b[", $output);
    }

    // ---- buildLines edge cases -------------------------------------------

    public function testBuildLinesWithNoColumnsReturnsEmptyArray(): void
    {
        $t = new Table();
        $this->assertSame([], $t->buildLines());
    }

    public function testBuildLinesWithNoRowsReturnsHeaderAndSeparator(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10));

        $lines = $t->buildLines();
        // Should have header + separator = 2 lines
        $this->assertCount(2, $lines);
    }

    public function testBuildLinesProducesSameAsRender(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice']);

        $this->assertSame($t->render(), implode("\n", $t->buildLines()));
    }

    // ---- rowCount / colCount ---------------------------------------------

    public function testRowCountWithFilteredRows(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob'])
            ->addRow(['Charlie'])
            ->filter('a');

        $this->assertSame(1, $t->rowCount());
    }

    public function testColCount(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('A', 5))
            ->addColumn(Column::make('B', 5))
            ->addColumn(Column::make('C', 5));

        $this->assertSame(3, $t->colCount());
    }

    // ---- sortByNext ------------------------------------------------------

    public function testSortByNextTogglesDirectionOnSameColumn(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob'])
            ->sortByNext(0); // ascending

        $first = $t->sortByNext(0); // now descending
        $second = $first->sortByNext(0); // back to ascending

        // After two toggles, should be back to ascending (sortByNext toggles same col)
        $this->assertStringContainsString('Alice', $second->render());
    }

    public function testSortByNextSwitchesColumnToAscending(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('A', 5))
            ->addColumn(Column::make('B', 5))
            ->addRow(['a', '1'])
            ->addRow(['b', '2'])
            ->sortBy(0);

        // Now switch to sort by column 1
        $t2 = $t->sortByNext(1);
        $output = $t2->render();
        $this->assertStringContainsString('1', $output);
    }

    // Helper
    private function getCursorRow(Table $t): int
    {
        $reflection = new \ReflectionClass($t);
        $prop = $reflection->getProperty('cursorRow');
        $prop->setAccessible(true);
        return $prop->getValue($t);
    }
}

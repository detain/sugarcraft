<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flexbox\Table\{Column, Table};

/**
 * Tests for Table rendering, sorting, filtering, and edge cases.
 */
final class TableTest extends TestCase
{
    // ---- computeTotalWidth -----------------------------------------------

    public function testComputeTotalWidthWithNoColumns(): void
    {
        $t = new Table();
        // Zero columns: the (n - 1) joint term used to make this NEGATIVE.
        $this->assertSame(0, $t->computeTotalWidth());
    }

    public function testComputeTotalWidthWithColumns(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addColumn(Column::make('City', 8));
        // 10 + 8 + separator (3 CELLS for ' │ ' * 1) = 21. The old byte
        // count said 23 — ' │ ' is 5 bytes but occupies 3 display cells.
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
        $this->assertStringEndsWith('Hi', $result); // '   Hi' padded on left
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
        // Numeric ascending: 2, 10, 100. First data row is '2' padded to 5 chars
        // padRight pads on the right, so '2' becomes '2    ' (5 chars)
        $this->assertStringStartsWith('2', trim($lines[2] ?? ''));
    }

    public function testSortByDescending(): void
    {
        // Position pin (finding-007): the old contains-'3' assertion held
        // under EVERY permutation, so a full comparator inversion stayed
        // green. Descending must reverse the whole visible order, row by
        // row — assert each row's actual key.
        $t = Table::new()
            ->withColumn(Column::new('Num', 5))
            ->withRow(['1'])
            ->withRow(['2'])
            ->withRow(['3'])
            ->withSort(0, false); // descending

        $this->assertSame(['3', '2', '1'], $this->dataColumn($t));
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
            ->filter('lice'); // Only matches 'Alice'

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
        // Position pin (finding-007): every stage asserts the FULL ordered
        // column, not presence of one value. Insertion order is deliberately
        // not alphabetical so a table that never sorted also fails the pins.
        $t = Table::new()
            ->withColumn(Column::new('Name', 10))
            ->withRow(['Bob'])
            ->withRow(['Cara'])
            ->withRow(['Alice']);

        $ascending = $t->sortByNext(0); // fresh column starts ascending
        $this->assertSame(['Alice', 'Bob', 'Cara'], $this->dataColumn($ascending));

        $descending = $ascending->sortByNext(0); // same column toggles direction
        $this->assertSame(['Cara', 'Bob', 'Alice'], $this->dataColumn($descending));

        $again = $descending->sortByNext(0); // and toggles back
        $this->assertSame(['Alice', 'Bob', 'Cara'], $this->dataColumn($again));
    }

    public function testSortByNextSwitchesColumnToAscending(): void
    {
        // Position pin (finding-007): switching the sort column to B must
        // move the '1' row to the top of the frame. Pinning BOTH columns'
        // ordered values proves the row actually relocated — an A-order
        // illusion (no re-sort) cannot pass.
        $t = Table::new()
            ->withColumn(Column::new('A', 5))
            ->withColumn(Column::new('B', 5))
            ->withRow(['x', '2'])
            ->withRow(['y', '1'])
            ->withSort(0); // A ascending: x, y

        $t2 = $t->sortByNext(1); // switch to B ascending: (y,1) then (x,2)
        $this->assertSame(['1', '2'], $this->dataColumn($t2, 1));
        $this->assertSame(['y', 'x'], $this->dataColumn($t2, 0));
    }

    // Helper
    private function getCursorRow(Table $t): int
    {
        $reflection = new \ReflectionClass($t);
        $prop = $reflection->getProperty('cursorRow');
        $prop->setAccessible(true);
        return $prop->getValue($t);
    }

    /**
     * Ordered values of one column across the rendered data rows.
     *
     * Skips the header + ruler band (lines 0-1) and splits the default
     * ' │ ' separator, so tests pin ROW POSITIONS rather than substring
     * presence (finding-007: presence-only assertions survive any sort
     * permutation).
     */
    private function dataColumn(Table $t, int $col = 0): array
    {
        $values = [];
        foreach (array_slice($t->buildLines(), 2) as $line) {
            $cells = array_map('trim', explode(' │ ', $line));
            $values[] = $cells[$col] ?? '';
        }
        return $values;
    }

    // ---- Table family hardening (lib-fixes-1103) --------------------------

    public function testComputeTotalWidthCountsCellsNotBytesForWideSeparators(): void
    {
        $t = Table::new()
            ->addColumn(Column::new('A', 2))
            ->withSeparator('│')
            ->addColumn(Column::new('B', 2));
        // 2 + 2 + one joint of 1 cell. Bytes would report 2+2+3 = 7.
        $this->assertSame(5, $t->computeTotalWidth());
    }

    public function testAppendKeepsCursorWhileSortAndFilterResetIt(): void
    {
        $t = Table::new()
            ->addColumn(Column::new('Name', 4))
            ->addRow(['ann'])
            ->addRow(['bo'])
            ->setCursor(1);

        $t2 = $t->addRow(['cy']);
        $this->assertSame(3, $t2->rowCount());
        $this->assertSame(['bo'], $t2->currentRow(), 'a streaming append must not move the selection');
    }

    public function testSortByOutOfRangeIndexIsRefused(): void
    {
        $t = Table::new()->addColumn(Column::new('A', 4));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('out of range for a table with 1 column(s)');
        $t->sortBy(1);
    }

    public function testSortByNextOutOfRangeIndexIsRefused(): void
    {
        $t = Table::new()->addColumn(Column::new('A', 4));
        $this->expectException(\InvalidArgumentException::class);
        $t->sortByNext(5);
    }

    public function testAddRowRefusesArrayValueNamingTheColumnIndex(): void
    {
        $t = Table::new()->addColumn(Column::new('A', 4));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('column 0 must be a string or scalar; array given');
        $t->addRow([['nested']]);
    }

    public function testAddRowRefusesObjectWithoutStringCasting(): void
    {
        $t = Table::new()->addColumn(Column::new('A', 4));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stdClass without __toString given');
        $t->addRow([new \stdClass()]);
    }

    public function testAddRowAcceptsScalarsAndStringables(): void
    {
        $stringable = new class {
            public function __toString(): string { return 'typed'; }
        };
        $t = Table::new()
            ->addColumn(Column::new('A', 6))
            ->addRow([$stringable])
            ->addRow([42])
            ->addRow([true]);
        $this->assertSame(3, $t->rowCount());
        $this->assertSame(['typed'], $t->currentRow());
    }

    public function testHeaderTitlesCannotCarryTerminalControlSequences(): void
    {
        $t = Table::new()->addColumn(Column::new("Esc\x1b[31mX", 12));
        $rendered = \implode("\n", $t->buildLines());
        $this->assertStringNotContainsString("\x1b", $rendered);
        $this->assertStringContainsString('Esc', $rendered, 'the printable text survives; only control bytes are stripped');
    }

    public function testSeparatorIsSanitizedAtIngress(): void
    {
        $t = Table::new()
            ->addColumn(Column::new('A', 3))
            ->withSeparator("\x1b[2J|")
            ->addColumn(Column::new('B', 3));
        $this->assertStringNotContainsString("\x1b", $t->render());
    }

    public function testLazyViewKeepsDerivedStateCorrectAcrossMixedWriters(): void
    {
        // N appends settle once on read: filter + sort + appends in any
        // interleaving must agree with the canonical-rows derivation.
        $t = Table::new()
            ->addColumn(Column::new('N', 4))
            ->addRow(['10'])
            ->addRow(['2'])
            ->filter('1')
            ->addRow(['100'])
            ->sortBy(0, false);
        $this->assertSame(2, $t->rowCount());
        $this->assertSame(['100'], $t->currentRow());
    }

    public function testStringSortIsCaseInsensitiveAcrossTheBinaryOrderBoundary(): void
    {
        // Binary strcmp would put uppercase 'B' (0x42) before lowercase
        // 'a' (0x61); the documented strcasecmp comparison must not.
        $t = Table::new()
            ->addColumn(Column::new('Name', 8))
            ->addRow(['B'])
            ->addRow(['a'])
            ->sortBy(0, true);
                $lines = $t->buildLines();
        // header + ruler + two data rows; 'a' must be the first data line.
        $this->assertStringContainsString('a', $lines[2]);
        $this->assertStringNotContainsString('B', $lines[2]);
        $this->assertStringContainsString('B', $lines[3]);
    }
}

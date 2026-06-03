<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\{Column, Row, RowData, Table};
use PHPUnit\Framework\TestCase;

/**
 * Tests for frozen column behavior.
 *
 * Verifies that:
 * - Frozen columns stay visible when scrolling horizontally
 * - Non-frozen columns scroll based on scrollX offset
 * - Separators render between consecutive visible columns
 * - Visible content width is computed correctly
 */
final class TableFrozenColsTest extends TestCase
{
    /**
     * Helper to extract visible column data from rendered view.
     * Returns an array of the visible column titles in order.
     */
    private function extractVisibleHeaders(string $view): array
    {
        // The header row contains column titles between border chars
        // Find content between the first border pair (top border and header separator)
        $lines = \explode("\n", $view);
        if (\count($lines) < 2) {
            return [];
        }

        // Second line is the header row
        $headerLine = $lines[1] ?? '';

        // Extract content between │ ... │ characters
        // Header format: │ ID │ Name │ City │
        $headers = [];
        $parts = \explode('│', $headerLine);
        // Parts[0] is left border + leading space
        // Parts[1..n-1] are the cell contents
        // Parts[n] is right border
        \array_shift($parts); // remove left border
        \array_pop($parts);   // remove right border

        foreach ($parts as $part) {
            $trimmed = \trim($part);
            if ($trimmed !== '') {
                $headers[] = $trimmed;
            }
        }

        return $headers;
    }

    /**
     * Helper to check if a specific column title appears in the header.
     */
    private function headerContains(string $view, string $title): bool
    {
        return \str_contains($view, $title);
    }

    /**
     * Count occurrences of column separators (│) in a row line.
     * This helps verify separators between consecutive visible columns.
     */
    private function countSeparatorsInLine(string $line): int
    {
        return \substr_count($line, '│');
    }

    // =========================================================================
    // Basic frozen column tests
    // =========================================================================

    public function testSingleFrozenColumnStaysVisibleWithScrollX(): void
    {
        // Table with 3 columns, first column frozen, scrollX=1 should hide
        // the second column but keep the first (frozen) visible
        $t = Table::withColumns([
            Column::new('id',   'ID',     5),
            Column::new('name', 'Name',  10),
            Column::new('city', 'City',   8),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice',   'city' => 'NYC'])),
            Row::new(RowData::from(['id' => '2', 'name' => 'Bob',     'city' => 'LA'])),
        ])->withFrozenCols([0])
          ->withScrollX(1);

        $view = $t->View();

        // Frozen column ID must still appear
        $this->assertTrue(
            $this->headerContains($view, 'ID'),
            'Frozen column ID should remain visible when scrolling'
        );

        // Name column should be hidden (scrollX=1 skips first non-frozen column)
        $this->assertFalse(
            $this->headerContains($view, 'Name'),
            'Non-frozen column Name should be hidden with scrollX=1'
        );
    }

    public function testTwoFrozenColumnsStaysVisibleWithScrollX(): void
    {
        // Table with 3 columns, first two frozen, scrollX=1 should hide
        // the third column but keep first two (frozen) visible
        $t = Table::withColumns([
            Column::new('id',   'ID',     5),
            Column::new('name', 'Name',  10),
            Column::new('city', 'City',   8),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice',   'city' => 'NYC'])),
        ])->withFrozenCols([0, 1])
          ->withScrollX(1);

        $view = $t->View();

        // Both frozen columns must still appear
        $this->assertTrue(
            $this->headerContains($view, 'ID'),
            'Frozen column ID should remain visible'
        );
        $this->assertTrue(
            $this->headerContains($view, 'Name'),
            'Frozen column Name should remain visible'
        );

        // City column should be hidden
        $this->assertFalse(
            $this->headerContains($view, 'City'),
            'Non-frozen column City should be hidden with scrollX=1'
        );
    }

    public function testAllColumnsFrozenNoScrollEffect(): void
    {
        // When all columns are frozen, scrollX should have no effect
        $t = Table::withColumns([
            Column::new('id',   'ID',   5),
            Column::new('name', 'Name', 10),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice'])),
        ])->withFrozenCols([0, 1])
          ->withScrollX(100); // Excessive scroll - should be ignored since all frozen

        $view = $t->View();

        // All columns should still be visible
        $this->assertTrue(
            $this->headerContains($view, 'ID'),
            'ID column should be visible when all columns frozen'
        );
        $this->assertTrue(
            $this->headerContains($view, 'Name'),
            'Name column should be visible when all columns frozen'
        );
    }

    public function testNoFrozenColumnsScrollsNormally(): void
    {
        // Without frozen columns, scrollX should shift all columns
        $t = Table::withColumns([
            Column::new('id',   'ID',   5),
            Column::new('name', 'Name', 10),
            Column::new('city', 'City',  8),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice', 'city' => 'NYC'])),
        ])->withFrozenCols([])  // No frozen columns
          ->withScrollX(1);

        $view = $t->View();

        // First column should be hidden (scrollX=1 shifts all)
        $this->assertFalse(
            $this->headerContains($view, 'ID'),
            'First column should be hidden when scrollX=1 with no frozen cols'
        );
        $this->assertTrue(
            $this->headerContains($view, 'Name'),
            'Second column should be visible with scrollX=1'
        );
    }

    public function testScrollXAffectsOnlyNonFrozenColumns(): void
    {
        // With 1 frozen column and scrollX=2, columns 0 stays (frozen),
        // columns 1-2 should be hidden (scrollX=2 means skip 2 non-frozen)
        $t = Table::withColumns([
            Column::new('id',   'ID',     5),
            Column::new('col1', 'Col1',  8),
            Column::new('col2', 'Col2',  8),
            Column::new('col3', 'Col3',  8),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'col1' => 'A', 'col2' => 'B', 'col3' => 'C'])),
        ])->withFrozenCols([0])
          ->withScrollX(2);

        $view = $t->View();

        // Frozen column always visible
        $this->assertTrue(
            $this->headerContains($view, 'ID'),
            'Frozen column ID should always be visible'
        );

        // scrollX=2 means skip first 2 non-frozen columns (Col1, Col2)
        $this->assertFalse(
            $this->headerContains($view, 'Col1'),
            'Col1 should be hidden (first non-frozen skipped by scrollX=2)'
        );
        $this->assertFalse(
            $this->headerContains($view, 'Col2'),
            'Col2 should be hidden (second non-frozen skipped by scrollX=2)'
        );

        // Col3 should be visible (third non-frozen, not skipped)
        $this->assertTrue(
            $this->headerContains($view, 'Col3'),
            'Col3 should be visible (not skipped by scrollX=2)'
        );
    }

    // =========================================================================
    // Separator rendering tests
    // =========================================================================

    public function testSeparatorsBetweenVisibleColumns(): void
    {
        // When consecutive columns are visible, there should be separators between them
        $t = Table::withColumns([
            Column::new('id',   'ID',   5),
            Column::new('name', 'Name', 10),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice'])),
        ])->withFrozenCols([0])
          ->withScrollX(0);

        $view = $t->View();
        $lines = \explode("\n", $view);

        // Header line should have 3 separators: left border, between cols, right border
        $headerLine = $lines[1] ?? '';
        $separatorCount = $this->countSeparatorsInLine($headerLine);

        // With 2 columns we expect: │ (left) + │ (between) + │ (right) = 3
        $this->assertSame(3, $separatorCount,
            'Should have 3 separators: left border, between columns, right border'
        );
    }

    public function testSeparatorsWhenFirstColumnHidden(): void
    {
        // With scrollX=1 and no frozen cols, only second column visible
        // Should still have proper separators
        $t = Table::withColumns([
            Column::new('id',   'ID',   5),
            Column::new('name', 'Name', 10),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice'])),
        ])->withFrozenCols([])
          ->withScrollX(1);

        $view = $t->View();
        $lines = \explode("\n", $view);

        // Header line should have 2 separators: left border + right border
        // (no between-column separator since only 1 column visible)
        $headerLine = $lines[1] ?? '';
        $separatorCount = $this->countSeparatorsInLine($headerLine);

        $this->assertSame(2, $separatorCount,
            'Should have 2 separators when only one column visible: left + right border'
        );
    }

    public function testSeparatorsWithTwoVisibleNonConsecutiveColumns(): void
    {
        // With frozen=[0] and scrollX=1, columns 0 and 2 are visible (not consecutive indices)
        // But they ARE consecutive in the visible set, so 1 separator between them
        $t = Table::withColumns([
            Column::new('id',   'ID',     5),
            Column::new('name', 'Name',  10),
            Column::new('city', 'City',   8),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice', 'city' => 'NYC'])),
        ])->withFrozenCols([0])
          ->withScrollX(1);

        $view = $t->View();
        $lines = \explode("\n", $view);

        // Header line: ID visible, Name hidden, City visible
        // Should have 3 separators: │ ID │ City │ (left, between, right)
        $headerLine = $lines[1] ?? '';
        $separatorCount = $this->countSeparatorsInLine($headerLine);

        $this->assertSame(3, $separatorCount,
            'Should have 3 separators: left border, between visible cols, right border'
        );
    }

    // =========================================================================
    // Data row tests
    // =========================================================================

    public function testDataRowsRespectFrozenColumns(): void
    {
        $t = Table::withColumns([
            Column::new('id',   'ID',   5),
            Column::new('name', 'Name', 10),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice'])),
        ])->withFrozenCols([0])
          ->withScrollX(1);

        $view = $t->View();

        // Frozen column data should appear
        $this->assertTrue(
            $this->headerContains($view, '1'),
            'Frozen column data value should appear in output'
        );

        // Non-frozen column data should not appear (hidden by scrollX=1)
        $this->assertFalse(
            $this->headerContains($view, 'Alice'),
            'Non-frozen column data should not appear when hidden'
        );
    }

    public function testAllFrozenColumnsShowAllData(): void
    {
        $t = Table::withColumns([
            Column::new('id',   'ID',   5),
            Column::new('name', 'Name', 10),
            Column::new('city', 'City',  8),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice', 'city' => 'NYC'])),
        ])->withFrozenCols([0, 1, 2])  // All columns frozen
          ->withScrollX(100); // Excessive scroll - all should still show

        $view = $t->View();

        // All data values should appear
        $this->assertTrue(\str_contains($view, '1'), 'ID should appear');
        $this->assertTrue(\str_contains($view, 'Alice'), 'Name should appear');
        $this->assertTrue(\str_contains($view, 'NYC'), 'City should appear');
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testScrollXClampedToZero(): void
    {
        $t = Table::withColumns([
            Column::new('id', 'ID', 5),
        ])->withRows([
            Row::new(RowData::from(['id' => '1'])),
        ])->withFrozenCols([0])
          ->withScrollX(-5); // Negative value should be clamped to 0

        $view = $t->View();
        $this->assertTrue(
            $this->headerContains($view, 'ID'),
            'Column should be visible with negative scrollX (clamped to 0)'
        );
    }

    public function testExcessiveScrollXSkipsAllNonFrozen(): void
    {
        $t = Table::withColumns([
            Column::new('id',   'ID',     5),
            Column::new('col1', 'Col1',  8),
            Column::new('col2', 'Col2',  8),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'col1' => 'A', 'col2' => 'B'])),
        ])->withFrozenCols([0])
          ->withScrollX(999); // Excessive scroll

        $view = $t->View();

        // Only frozen column should be visible
        $this->assertTrue(
            $this->headerContains($view, 'ID'),
            'Frozen column should always be visible'
        );
        $this->assertFalse(
            $this->headerContains($view, 'Col1'),
            'Col1 should be hidden with excessive scrollX'
        );
        $this->assertFalse(
            $this->headerContains($view, 'Col2'),
            'Col2 should be hidden with excessive scrollX'
        );
    }

    public function testEmptyFrozenColsArrayEqualsNoFrozen(): void
    {
        // Empty frozenCols should behave the same as no frozen columns
        $t1 = Table::withColumns([
            Column::new('id',   'ID',   5),
            Column::new('name', 'Name', 10),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice'])),
        ])->withFrozenCols([])
          ->withScrollX(1);

        $t2 = Table::withColumns([
            Column::new('id',   'ID',   5),
            Column::new('name', 'Name', 10),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice'])),
        ])->withScrollX(1);

        $view1 = $t1->View();
        $view2 = $t2->View();

        // Both should behave identically (first column hidden)
        $this->assertFalse(
            $this->headerContains($view1, 'ID'),
            'Empty frozenCols with scrollX=1 should hide first column'
        );
        $this->assertEquals(
            $view1,
            $view2,
            'Empty frozenCols should behave same as default (no frozen)'
        );
    }

    public function testImmutabilityOfWithFrozenCols(): void
    {
        $a = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))]);

        $b = $a->withFrozenCols([0]);

        $this->assertNotSame($a, $b);
        // Original should not have frozen cols
        $viewA = $a->View();
        $this->assertFalse(
            $this->headerContains($viewA, 'withFrozenCols') // shouldn't contain method name
        );
    }

    public function testViewRendersWithoutCrashWithFrozenAndScroll(): void
    {
        // Regression test: ensure no exceptions or errors with various combinations
        $t = Table::withColumns([
            Column::new('id',   'ID',     5),
            Column::new('name', 'Name',  10),
            Column::new('city', 'City',   8),
            Column::new('note', 'Note',  12),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice', 'city' => 'NYC', 'note' => 'Test note'])),
            Row::new(RowData::from(['id' => '2', 'name' => 'Bob',   'city' => 'LA',   'note' => 'Another'])),
        ])->withFrozenCols([0, 2])  // Non-consecutive frozen columns
          ->withScrollX(1)
          ->withZebra();

        $view = $t->View();

        $this->assertIsString($view);
        $this->assertNotEmpty($view);
        // Should contain the frozen column ID and frozen column City
        $this->assertTrue(\str_contains($view, 'ID'));
        $this->assertTrue(\str_contains($view, 'City'));
    }

    // =========================================================================
    // computeVisibleContentWidth tests
    // =========================================================================

    public function testVisibleContentWidthWithFrozenColumns(): void
    {
        $t = Table::withColumns([
            Column::new('id',   'ID',     5),
            Column::new('name', 'Name',  10),
            Column::new('city', 'City',   8),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice', 'city' => 'NYC'])),
        ])->withFrozenCols([0])
          ->withScrollX(0);

        // Use reflection to access private method
        $reflection = new \ReflectionClass($t);
        $method = $reflection->getMethod('computeVisibleContentWidth');
        $method->setAccessible(true);

        // With all columns visible, width should be sum of all widths + separators
        $widths = $t->computeColumnWidths(50);
        $visibleWidth = $method->invoke($t, $widths);

        // 5 + 10 + 8 = 23 for columns, plus 2 separators between 3 columns = 25
        $this->assertSame(25, $visibleWidth,
            'Visible content width should include all columns plus separators'
        );
    }

    public function testVisibleContentWidthWithHiddenColumn(): void
    {
        $t = Table::withColumns([
            Column::new('id',   'ID',     5),
            Column::new('name', 'Name',  10),
            Column::new('city', 'City',   8),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice', 'city' => 'NYC'])),
        ])->withFrozenCols([0])
          ->withScrollX(1); // Hides 'Name' column

        $reflection = new \ReflectionClass($t);
        $method = $reflection->getMethod('computeVisibleContentWidth');
        $method->setAccessible(true);

        $widths = $t->computeColumnWidths(50);
        $visibleWidth = $method->invoke($t, $widths);

        // ID (5) + City (8) = 13 for columns, plus 1 separator between them = 14
        $this->assertSame(14, $visibleWidth,
            'Visible content width should only include visible columns plus separator'
        );
    }

    public function testVisibleContentWidthWithAllFrozenNoScroll(): void
    {
        $t = Table::withColumns([
            Column::new('id',   'ID',   5),
            Column::new('name', 'Name', 10),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice'])),
        ])->withFrozenCols([0, 1])
          ->withScrollX(100); // Excessive, but all columns are frozen

        $reflection = new \ReflectionClass($t);
        $method = $reflection->getMethod('computeVisibleContentWidth');
        $method->setAccessible(true);

        $widths = $t->computeColumnWidths(50);
        $visibleWidth = $method->invoke($t, $widths);

        // Both columns visible: 5 + 10 = 15, plus 1 separator = 16
        $this->assertSame(16, $visibleWidth,
            'All frozen columns should all be visible regardless of scrollX'
        );
    }
}

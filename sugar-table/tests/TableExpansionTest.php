<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\{Column, Row, RowData, Table};
use PHPUnit\Framework\TestCase;

final class TableExpansionTest extends TestCase
{
    private function makeTableWith20Rows(): Table
    {
        $rows = [];
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = Row::new(RowData::from([
                'id'   => (string) $i,
                'name' => "User{$i}",
                'city' => "City{$i}",
            ]));
        }
        return Table::withColumns([
            Column::new('id',   'ID',   5),
            Column::new('name', 'Name', 10),
            Column::new('city', 'City', 10),
        ])->withRows($rows);
    }

    // -------------------------------------------------------------------------
    // toggleExpanded tests
    // -------------------------------------------------------------------------

    public function testToggleExpandedAddsRow(): void
    {
        $t = $this->makeTableWith20Rows();
        $this->assertFalse($t->isExpanded(0));

        $t = $t->toggleExpanded(0);
        $this->assertTrue($t->isExpanded(0));
    }

    public function testToggleExpandedRemovesRow(): void
    {
        $t = $this->makeTableWith20Rows()
            ->toggleExpanded(0);
        $this->assertTrue($t->isExpanded(0));

        $t = $t->toggleExpanded(0);
        $this->assertFalse($t->isExpanded(0));
    }

    public function testToggleExpandedReturnsNewInstance(): void
    {
        $t = $this->makeTableWith20Rows();
        $result = $t->toggleExpanded(0);
        $this->assertNotSame($t, $result);
        $this->assertFalse($t->isExpanded(0));
        $this->assertTrue($result->isExpanded(0));
    }

    public function testToggleExpandedInvalidIndexThrows(): void
    {
        $t = $this->makeTableWith20Rows();

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('Invalid row index 99 for current page');
        $t->toggleExpanded(99);
    }

    public function testToggleExpandedInvalidNegativeIndexThrows(): void
    {
        $t = $this->makeTableWith20Rows();

        $this->expectException(\OutOfBoundsException::class);
        $t->toggleExpanded(-1);
    }

    // -------------------------------------------------------------------------
    // isExpanded tests
    // -------------------------------------------------------------------------

    public function testIsExpandedReturnsTrueForExpandedRow(): void
    {
        $t = $this->makeTableWith20Rows()
            ->toggleExpanded(5);
        $this->assertTrue($t->isExpanded(5));
    }

    public function testIsExpandedReturnsFalseForNonExpandedRow(): void
    {
        $t = $this->makeTableWith20Rows();
        $this->assertFalse($t->isExpanded(5));
    }

    public function testIsExpandedInvalidIndexThrows(): void
    {
        $t = $this->makeTableWith20Rows();

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('Invalid row index 99 for current page');
        $t->isExpanded(99);
    }

    // -------------------------------------------------------------------------
    // withExpandedRows tests
    // -------------------------------------------------------------------------

    public function testWithExpandedRowsSetsMultipleRows(): void
    {
        $t = $this->makeTableWith20Rows()
            ->withExpandedRows([1, 3, 5]);

        $this->assertFalse($t->isExpanded(0));
        $this->assertTrue($t->isExpanded(1));
        $this->assertFalse($t->isExpanded(2));
        $this->assertTrue($t->isExpanded(3));
        $this->assertFalse($t->isExpanded(4));
        $this->assertTrue($t->isExpanded(5));
    }

    public function testWithExpandedRowsReturnsNewInstance(): void
    {
        $t = $this->makeTableWith20Rows();
        $result = $t->withExpandedRows([2, 4]);

        $this->assertNotSame($t, $result);
        $this->assertFalse($t->isExpanded(2));
        $this->assertTrue($result->isExpanded(2));
    }

    public function testWithExpandedRowsClearsPreviousExpanded(): void
    {
        $t = $this->makeTableWith20Rows()
            ->toggleExpanded(1)
            ->toggleExpanded(3);
        $this->assertTrue($t->isExpanded(1));
        $this->assertTrue($t->isExpanded(3));

        $t = $t->withExpandedRows([5]);
        $this->assertFalse($t->isExpanded(1));
        $this->assertFalse($t->isExpanded(3));
        $this->assertTrue($t->isExpanded(5));
    }

    public function testWithExpandedRowsEmptyArrayClearsAll(): void
    {
        $t = $this->makeTableWith20Rows()
            ->toggleExpanded(1)
            ->toggleExpanded(3);

        $t = $t->withExpandedRows([]);
        $this->assertFalse($t->isExpanded(1));
        $this->assertFalse($t->isExpanded(3));
    }

    public function testWithExpandedRowsInvalidIndexThrows(): void
    {
        $t = $this->makeTableWith20Rows();

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('Invalid row index 99 for current page');
        $t->withExpandedRows([1, 99, 3]);
    }

    // -------------------------------------------------------------------------
    // Pagination integration tests (Issue 1)
    // -------------------------------------------------------------------------

    public function testToggleExpandedIsPageRelative(): void
    {
        // Create table with 20 rows, page size 10, on page 1 (rows 10-19)
        $t = $this->makeTableWith20Rows()
            ->withPageSize(10)
            ->withPage(1);

        // Verify we're on page 2
        $this->assertSame(1, $t->CurrentPage());
        $paged = $t->pagedRows();
        $this->assertSame('11', $paged[0]->data->get('id')); // First row on page 2

        // Toggle index 0 on page 2 - should expand row 11 (id='11'), not row 1 (id='1')
        $t = $t->toggleExpanded(0);

        // Row 0 on page 2 (global index 10) should be expanded
        $this->assertTrue($t->isExpanded(0));
        // Row 0 on page 1 (global index 0) should NOT be expanded
        $this->assertFalse($this->makeTableWith20Rows()
            ->withPageSize(10)
            ->withPage(0)
            ->isExpanded(0));
    }

    public function testIsExpandedIsPageRelative(): void
    {
        $t = $this->makeTableWith20Rows()
            ->withPageSize(10)
            ->withPage(1)
            ->toggleExpanded(0);

        // On page 2, index 0 should be expanded
        $this->assertTrue($t->isExpanded(0));

        // If we switch to page 1, index 0 should NOT be expanded (different row)
        $tPage1 = $t->withPage(0);
        $this->assertFalse($tPage1->isExpanded(0));
    }

    public function testWithExpandedRowsIsPageRelative(): void
    {
        $t = $this->makeTableWith20Rows()
            ->withPageSize(10)
            ->withPage(1)
            ->withExpandedRows([0, 2, 4]);

        // On page 2, indices 0, 2, 4 should be expanded
        $this->assertTrue($t->isExpanded(0));
        $this->assertTrue($t->isExpanded(2));
        $this->assertTrue($t->isExpanded(4));
        $this->assertFalse($t->isExpanded(1));

        // On page 1, same indices should NOT be expanded (different rows)
        $tPage1 = $t->withPage(0);
        $this->assertFalse($tPage1->isExpanded(0));
        $this->assertFalse($tPage1->isExpanded(2));
        $this->assertFalse($tPage1->isExpanded(4));
    }

    public function testExpansionStatePreservedAcrossPageNavigation(): void
    {
        $t = $this->makeTableWith20Rows()
            ->withPageSize(10)
            ->withPage(0)
            ->toggleExpanded(5);

        $this->assertTrue($t->isExpanded(5));

        // Navigate to page 2
        $t = $t->withPage(1);
        $this->assertFalse($t->isExpanded(5)); // Index 5 on page 2 is different row

        // Navigate back to page 1
        $t = $t->withPage(0);
        $this->assertTrue($t->isExpanded(5)); // Row expanded state preserved
    }

    public function testToggleExpandedOnPage2AffectsCorrectRow(): void
    {
        // Setup: 20 rows, page size 10, page 1 (rows 10-19 visible)
        $t = $this->makeTableWith20Rows()
            ->withPageSize(10)
            ->withPage(1);

        $pagedBefore = $t->pagedRows();
        $this->assertSame('11', $pagedBefore[0]->data->get('id')); // First visible row on page 2

        // Toggle index 0 on page 2 - should expand row with id='11'
        $t = $t->toggleExpanded(0);

        // Verify the expanded row is the one at paged index 0
        $paged = $t->pagedRows();
        $this->assertSame('11', $paged[0]->data->get('id'));

        // The row with id='1' (first row on page 1) should NOT be expanded
        $tPage1 = $t->withPage(0);
        $pagedPage1 = $tPage1->pagedRows();
        $this->assertSame('1', $pagedPage1[0]->data->get('id'));
        $this->assertFalse($tPage1->isExpanded(0));
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function testToggleExpandedOnEmptyPageThrows(): void
    {
        $t = $this->makeTableWith20Rows()
            ->withPageSize(10)
            ->withPage(10); // Beyond available pages

        $this->expectException(\OutOfBoundsException::class);
        $t->toggleExpanded(0);
    }

    public function testIsExpandedOnEmptyPageThrows(): void
    {
        $t = $this->makeTableWith20Rows()
            ->withPageSize(10)
            ->withPage(10); // Beyond available pages

        $this->expectException(\OutOfBoundsException::class);
        $t->isExpanded(0);
    }

    public function testWithExpandedRowsOnEmptyPageThrows(): void
    {
        $t = $this->makeTableWith20Rows()
            ->withPageSize(10)
            ->withPage(10); // Beyond available pages

        $this->expectException(\OutOfBoundsException::class);
        $t->withExpandedRows([0]);
    }

    public function testNoPaginationUsesAllRows(): void
    {
        // Without pagination (pageSize=0), pagedRows() returns all rows
        $t = $this->makeTableWith20Rows()
            ->toggleExpanded(15);

        $this->assertTrue($t->isExpanded(15));
        $this->assertFalse($t->isExpanded(0));
        $this->assertFalse($t->isExpanded(19));
    }

    // -------------------------------------------------------------------------
    // Multiple expansions
    // -------------------------------------------------------------------------

    public function testMultipleRowsCanBeExpanded(): void
    {
        $t = $this->makeTableWith20Rows()
            ->toggleExpanded(0)
            ->toggleExpanded(5)
            ->toggleExpanded(10);

        $this->assertTrue($t->isExpanded(0));
        $this->assertTrue($t->isExpanded(5));
        $this->assertTrue($t->isExpanded(10));
        $this->assertFalse($t->isExpanded(1));
        $this->assertFalse($t->isExpanded(15));
    }

    public function testExpandedRowsSurviveFilter(): void
    {
        // Expand row 5 (which is 'User6' after name sorting)
        $t = $this->makeTableWith20Rows()
            ->SortBy('name', ascending: true)
            ->toggleExpanded(5);

        $this->assertTrue($t->isExpanded(5));

        // Apply filter - should still track the correct row object
        $t = $t->Filter('name', 'User1'); // Should match User1, User10, User11

        // Row 0 (User1) should not be expanded
        $this->assertFalse($t->isExpanded(0));
    }
}

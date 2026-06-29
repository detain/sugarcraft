<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\{Column, Row, RowData, Table};
use PHPUnit\Framework\TestCase;

/**
 * Tests for global search feature.
 *
 * Verifies that:
 * - search() finds terms in any column (case-insensitive)
 * - search('') returns all rows (no filtering)
 * - ClearSearch() clears the search and shows all rows
 * - search combines with Filter() using AND logic
 * - selectedIndex resets to 0 on search change
 */
final class TableGlobalSearchTest extends TestCase
{
    /**
     * Helper to create a standard table with 3 rows for search tests.
     */
    private function makeSearchTable(): Table
    {
        return Table::fromColumns([
            Column::new('id',   'ID',     5),
            Column::new('name', 'Name',  20),
            Column::new('city', 'City',  15),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice',   'city' => 'New York'])),
            Row::new(RowData::from(['id' => '2', 'name' => 'Bob',     'city' => 'Denver'])),
            Row::new(RowData::from(['id' => '3', 'name' => 'Charlie', 'city' => 'Chicago'])),
        ]);
    }

    // =========================================================================
    // Basic search tests
    // =========================================================================

    /**
     * Verifies search finds a term in column 1 (id column).
     */
    public function testSearchFindsTermInColumn1(): void
    {
        $t = $this->makeSearchTable()->search('1');

        $this->assertSame(1, $t->TotalRows());
        $this->assertSame('Alice', $t->CurrentRowData()?->get('name'));
    }

    /**
     * Verifies search finds a term in column 2 (name column).
     */
    public function testSearchFindsTermInColumn2(): void
    {
        $t = $this->makeSearchTable()->search('Bob');

        $this->assertSame(1, $t->TotalRows());
        $this->assertSame('Bob', $t->CurrentRowData()?->get('name'));
    }

    /**
     * Verifies search finds a term in column 3 (city column).
     */
    public function testSearchFindsTermInColumn3(): void
    {
        $t = $this->makeSearchTable()->search('Chicago');

        $this->assertSame(1, $t->TotalRows());
        $this->assertSame('Charlie', $t->CurrentRowData()?->get('name'));
    }

    /**
     * Verifies search finds the same term across multiple rows.
     */
    public function testSearchFindsTermInMultipleRows(): void
    {
        // "Den" appears in "Denver" only
        // Search for "Den" should find only Bob
        $t = $this->makeSearchTable()->search('Den');

        $this->assertSame(1, $t->TotalRows());
        $this->assertSame('Bob', $t->CurrentRowData()?->get('name'));
    }

    /**
     * Verifies search with partial match works (stripos behavior).
     */
    public function testSearchPartialMatch(): void
    {
        // "ali" should match "Alice" (case-insensitive partial match)
        $t = $this->makeSearchTable()->search('ali');

        $this->assertSame(1, $t->TotalRows());
        $this->assertSame('Alice', $t->CurrentRowData()?->get('name'));
    }

    // =========================================================================
    // Case-insensitive search tests
    // =========================================================================

    /**
     * Verifies search is case-insensitive (uppercase search term).
     */
    public function testSearchCaseInsensitiveUppercase(): void
    {
        $t = $this->makeSearchTable()->search('ALICE');

        $this->assertSame(1, $t->TotalRows());
        $this->assertSame('Alice', $t->CurrentRowData()?->get('name'));
    }

    /**
     * Verifies search is case-insensitive (mixed case search term).
     */
    public function testSearchCaseInsensitiveMixedCase(): void
    {
        $t = $this->makeSearchTable()->search('AlIcE');

        $this->assertSame(1, $t->TotalRows());
        $this->assertSame('Alice', $t->CurrentRowData()?->get('name'));
    }

    /**
     * Verifies search is case-insensitive (lowercase search term with uppercase data).
     */
    public function testSearchCaseInsensitiveLowercase(): void
    {
        $t = $this->makeSearchTable()->search('bob');

        $this->assertSame(1, $t->TotalRows());
        $this->assertSame('Bob', $t->CurrentRowData()?->get('name'));
    }

    /**
     * Verifies search is case-insensitive in city column.
     */
    public function testSearchCaseInsensitiveCityColumn(): void
    {
        $t = $this->makeSearchTable()->search('NEW YORK');

        $this->assertSame(1, $t->TotalRows());
        $this->assertSame('Alice', $t->CurrentRowData()?->get('name'));
    }

    // =========================================================================
    // Empty search tests
    // =========================================================================

    /**
     * Verifies search('') returns all rows (no filtering).
     */
    public function testSearchEmptyStringReturnsAllRows(): void
    {
        $t = $this->makeSearchTable()->search('');

        $this->assertSame(3, $t->TotalRows());
    }

    /**
     * Verifies search('') preserves row order.
     */
    public function testSearchEmptyStringPreservesRowOrder(): void
    {
        $t = $this->makeSearchTable()->search('');

        $rows = $t->filteredSortedRows();
        $this->assertSame('Alice', $rows[0]->data->get('name'));
        $this->assertSame('Bob', $rows[1]->data->get('name'));
        $this->assertSame('Charlie', $rows[2]->data->get('name'));
    }

    // =========================================================================
    // ClearSearch tests
    // =========================================================================

    /**
     * Verifies ClearSearch() clears the search and returns all rows.
     */
    public function testClearSearchReturnsAllRows(): void
    {
        $t = $this->makeSearchTable()->search('Alice')->ClearSearch();

        $this->assertSame(3, $t->TotalRows());
    }

    /**
     * Verifies ClearSearch() works after searching multiple times.
     */
    public function testClearSearchAfterMultipleSearches(): void
    {
        $t = $this->makeSearchTable()
            ->search('Alice')
            ->search('Bob')
            ->ClearSearch();

        $this->assertSame(3, $t->TotalRows());
    }

    /**
     * Verifies ClearSearch() resets selectedIndex to 0.
     */
    public function testClearSearchResetsSelectedIndex(): void
    {
        $t = $this->makeSearchTable()
            ->search('Alice')
            ->SelectNext()  // selectedIndex = 1
            ->ClearSearch();

        $this->assertSame(0, $t->SelectedIndex());
    }

    // =========================================================================
    // Search + Filter combination tests
    // =========================================================================

    /**
     * Verifies search combines with Filter using AND logic.
     * Row must match BOTH the search term AND the column filter.
     */
    public function testSearchCombinedWithFilter(): void
    {
        $t = $this->makeSearchTable()
            ->Filter('city', 'New')  // Filter to rows where city contains "New"
            ->search('1');           // Then search across all columns for "1"

        // Only Alice (id=1, city=New York) should match both
        $this->assertSame(1, $t->TotalRows());
        $this->assertSame('Alice', $t->CurrentRowData()?->get('name'));
    }

    /**
     * Verifies search and filter combination returns no rows when no match.
     */
    public function testSearchCombinedWithFilterNoMatch(): void
    {
        $t = $this->makeSearchTable()
            ->Filter('city', 'Los')  // Only Bob (Los Angeles)
            ->search('1');           // But search for "1" (Alice only)

        $this->assertSame(0, $t->TotalRows());
    }

    /**
     * Verifies Filter after search works correctly.
     */
    public function testFilterAfterSearch(): void
    {
        $t = $this->makeSearchTable()
            ->search('a')            // Matches Alice and Charlie (both have 'a' in name)
            ->Filter('name', 'ali'); // Further filter to names containing 'ali'

        $this->assertSame(1, $t->TotalRows());
        $this->assertSame('Alice', $t->CurrentRowData()?->get('name'));
    }

    /**
     * Verifies search + filter + another filter all work together.
     */
    public function testSearchCombinedWithMultipleFilters(): void
    {
        $t = $this->makeSearchTable()
            ->Filter('city', 'New')  // Alice only (New York)
            ->Filter('city', 'York') // Still Alice (New York contains York)
            ->search('1');           // Search for "1" - still Alice

        $this->assertSame(1, $t->TotalRows());
        $this->assertSame('Alice', $t->CurrentRowData()?->get('name'));
    }

    // =========================================================================
    // Search with Sort tests
    // =========================================================================

    /**
     * Verifies search results can be sorted.
     */
    public function testSearchResultsAreSortable(): void
    {
        $t = $this->makeSearchTable()
            ->search('a')             // Matches Alice (name) and Charlie (name)
            ->SortBy('name', ascending: false);

        $rows = $t->filteredSortedRows();
        $this->assertSame('Charlie', $rows[0]->data->get('name')); // Descending: Charlie before Alice
        $this->assertSame('Alice', $rows[1]->data->get('name'));
    }

    // =========================================================================
    // Search immutability tests
    // =========================================================================

    /**
     * Verifies search() returns a new instance (immutable).
     */
    public function testSearchIsImmutable(): void
    {
        $original = $this->makeSearchTable();
        $searched = $original->search('Alice');

        $this->assertNotSame($original, $searched);
        $this->assertSame(3, $original->TotalRows());  // original unchanged
        $this->assertSame(1, $searched->TotalRows()); // new instance filtered
    }

    /**
     * Verifies ClearSearch() returns a new instance (immutable).
     */
    public function testClearSearchIsImmutable(): void
    {
        $original = $this->makeSearchTable();
        $cleared = $original->ClearSearch();

        $this->assertNotSame($original, $cleared);
    }

    // =========================================================================
    // Search selectedIndex tests
    // =========================================================================

    /**
     * Verifies search() resets selectedIndex to 0.
     */
    public function testSearchResetsSelectedIndex(): void
    {
        $t = $this->makeSearchTable()
            ->withSelectedIndex(2)  // Start at Charlie
            ->search('Alice');

        $this->assertSame(0, $t->SelectedIndex());
    }

    /**
     * Verifies search() after SelectNext() resets to 0.
     */
    public function testSearchAfterSelectNextResetsSelectedIndex(): void
    {
        $t = $this->makeSearchTable()
            ->SelectNext()  // selectedIndex = 1 (Bob)
            ->search('Charlie');

        $this->assertSame(0, $t->SelectedIndex());
        $this->assertSame('Charlie', $t->CurrentRowData()?->get('name'));
    }

    // =========================================================================
    // Search no match tests
    // =========================================================================

    /**
     * Verifies search with no matches returns 0 rows.
     */
    public function testSearchNoMatchReturnsZeroRows(): void
    {
        $t = $this->makeSearchTable()->search('xyz');

        $this->assertSame(0, $t->TotalRows());
    }

    /**
     * Verifies search with special characters returns 0 rows when no match.
     */
    public function testSearchSpecialCharsNoMatch(): void
    {
        $t = $this->makeSearchTable()->search('!!!');

        $this->assertSame(0, $t->TotalRows());
    }

    // =========================================================================
    // Search with numeric data tests
    // =========================================================================

    /**
     * Verifies search works on numeric id column.
     */
    public function testSearchNumericIdColumn(): void
    {
        $t = $this->makeSearchTable()->search('2');

        $this->assertSame(1, $t->TotalRows());
        $this->assertSame('Bob', $t->CurrentRowData()?->get('name'));
    }

    /**
     * Verifies search matches across all columns (OR logic within search).
     */
    public function testSearchMatchesAnyColumn(): void
    {
        // "1" matches Alice (id=1)
        // If search used AND logic, it would also need to match in name AND city
        $t = $this->makeSearchTable()->search('1');

        $this->assertSame(1, $t->TotalRows());
    }

    // =========================================================================
    // Search edge cases
    // =========================================================================

    /**
     * Verifies search on empty table returns 0 rows.
     */
    public function testSearchOnEmptyTable(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5),
        ])->withRows([]);

        $this->assertSame(0, $t->search('test')->TotalRows());
    }

    /**
     * Verifies search on single-row table works correctly.
     */
    public function testSearchOnSingleRowTable(): void
    {
        $t = Table::fromColumns([
            Column::new('name', 'Name', 10),
        ])->withRows([
            Row::new(RowData::from(['name' => 'Solo'])),
        ]);

        $this->assertSame(1, $t->search('solo')->TotalRows());
        $this->assertSame('Solo', $t->CurrentRowData()?->get('name'));
    }

    /**
     * Verifies search matches substring within longer strings.
     */
    public function testSearchMatchesSubstringInLongerStrings(): void
    {
        $t = $this->makeSearchTable()->search('New');

        $this->assertSame(1, $t->TotalRows());
        $this->assertSame('Alice', $t->CurrentRowData()?->get('name'));
        $this->assertSame('New York', $t->CurrentRowData()?->get('city'));
    }
}

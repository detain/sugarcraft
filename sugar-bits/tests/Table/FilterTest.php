<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Table;

use SugarCraft\Bits\Table\Table;
use PHPUnit\Framework\TestCase;

final class FilterTest extends TestCase
{
    private function table(): Table
    {
        return Table::new(
            ['Name', 'Age', 'City'],
            [
                ['Alice', '30', 'Boston'],
                ['Bob', '25', 'Austin'],
                ['Carol', '40', 'Chicago'],
                ['Dave', '35', 'Dallas'],
                ['alice', '28', 'Denver'],   // lowercase alice
            ],
            0,
            10,
        );
    }

    public function testFilterDisabledByDefault(): void
    {
        $t = $this->table();
        $this->assertFalse($t->getFilterable());
        $this->assertSame('', $t->getFilter());
        $this->assertNull($t->getFilterPredicate());
    }

    public function testWithFilterableEnablesFiltering(): void
    {
        $t = $this->table();
        $t2 = $t->withFilterable(true);
        $this->assertNotSame($t, $t2);
        $this->assertTrue($t2->getFilterable());
        // Original unchanged
        $this->assertFalse($t->getFilterable());
    }

    public function testWithFilterSetsQuery(): void
    {
        $t = $this->table();
        $t2 = $t->withFilter('Alice');
        $this->assertNotSame($t, $t2);
        $this->assertSame('Alice', $t2->getFilter());
        // Original unchanged
        $this->assertSame('', $t->getFilter());
    }

    public function testEmptyFilterShowsAllRowsWhenFilterable(): void
    {
        $t = $this->table()
            ->withFilterable(true)
            ->withFilter('');

        $view = $t->view();
        // All rows should be visible
        $this->assertStringContainsString('Alice', $view);
        $this->assertStringContainsString('Bob', $view);
        $this->assertStringContainsString('Carol', $view);
        $this->assertStringContainsString('Dave', $view);
    }

    public function testDisabledFilteringIgnoresFilter(): void
    {
        $t = $this->table()
            ->withFilter('Alice')
            ->withFilterable(false);   // filterable after filter but disabled

        $view = $t->view();
        // All rows should still be visible (filtering is disabled)
        $this->assertStringContainsString('Alice', $view);
        $this->assertStringContainsString('Bob', $view);
        $this->assertStringContainsString('Carol', $view);
        $this->assertStringContainsString('Dave', $view);
    }

    public function testBasicSubstringMatch(): void
    {
        $t = $this->table()
            ->withFilterable(true)
            ->withFilter('Alice');

        $view = $t->view();
        // Only matching row should appear
        $this->assertStringContainsString('Alice', $view);
        $this->assertStringNotContainsString('Bob', $view);
        $this->assertStringNotContainsString('Carol', $view);
        $this->assertStringNotContainsString('Dave', $view);
    }

    public function testCaseInsensitiveMatch(): void
    {
        $t = $this->table()
            ->withFilterable(true)
            ->withFilter('alice');   // lowercase query

        $view = $t->view();
        // Both 'Alice' and 'alice' rows should match (case-insensitive)
        $this->assertStringContainsString('Alice', $view);
        $this->assertStringContainsString('alice', $view);
        $this->assertStringNotContainsString('Bob', $view);
    }

    public function testFilterMatchesAcrossAnyColumn(): void
    {
        // Query matches City column, not Name
        $t = $this->table()
            ->withFilterable(true)
            ->withFilter('Boston');

        $view = $t->view();
        $this->assertStringContainsString('Alice', $view);   // Alice + Boston
        $this->assertStringContainsString('30', $view);
        $this->assertStringNotContainsString('Bob', $view);
        $this->assertStringNotContainsString('Carol', $view);
    }

    public function testFilterPredicateOverride(): void
    {
        // Custom predicate: only rows where Age > 30
        $t = $this->table()
            ->withFilterable(true)
            ->withFilterPredicate(static fn(array $row): bool => (int) ($row[1]) > 30);

        $view = $t->view();
        // Carol (40) and Dave (35) match, Alice (30) and Bob (25) don't
        $this->assertStringContainsString('Carol', $view);
        $this->assertStringContainsString('Dave', $view);
        $this->assertStringNotContainsString('Alice', $view);
        $this->assertStringNotContainsString('Bob', $view);
    }

    public function testFilterPredicateWithFilterQueryIgnored(): void
    {
        // When predicate is set, filter query should be ignored
        $t = $this->table()
            ->withFilter('Alice')   // this would match Alice
            ->withFilterable(true)
            ->withFilterPredicate(static fn(array $row): bool => ($row[1] ?? '') === '25');  // only Bob

        $view = $t->view();
        // Only Bob (age 25) matches via predicate
        $this->assertStringContainsString('Bob', $view);
        $this->assertStringNotContainsString('Alice', $view);
        $this->assertStringNotContainsString('Carol', $view);
    }

    public function testClearFilterPredicateWithNull(): void
    {
        $t = $this->table()
            ->withFilterable(true)
            ->withFilterPredicate(static fn(array $row): bool => true);

        $t2 = $t->withFilterPredicate(null);
        $this->assertNull($t2->getFilterPredicate());
        // Still filterable but predicate is cleared
    }

    public function testFilterChainsWithSort(): void
    {
        $t = $this->table()
            ->withFilterable(true)
            ->withFilter('a')
            ->withSort('Age');

        $view = $t->view();
        // Bob (25, Austin) should come before Dave (35, Dallas)
        // since both contain 'a' (case-insensitive): Bob matches 'a' in name & city,
        // Dave matches 'a' in name. After sort by Age asc: Bob < Dave.
        $this->assertStringContainsString('Bob', $view);
        $this->assertStringContainsString('Dave', $view);
        $this->assertLessThan(
            strpos($view, 'Dave'),
            strpos($view, 'Bob'),
        );
    }

    public function testFilterOnEmptyTable(): void
    {
        $t = Table::new(['Name'], [])
            ->withFilterable(true)
            ->withFilter('x');
        // Empty rows — header row still rendered (underline escape), no data rows
        $view = $t->view();
        $this->assertStringContainsString('Name', $view);
        $this->assertStringNotContainsString("\n", $view);
    }

    public function testFilterWithNoMatches(): void
    {
        $t = $this->table()
            ->withFilterable(true)
            ->withFilter('zebra');

        $view = $t->view();
        // No rows match 'zebra' - only header should appear
        $this->assertStringContainsString('Name', $view);
        $this->assertStringNotContainsString('Alice', $view);
        $this->assertStringNotContainsString('Bob', $view);
    }

    public function testRowsListReturnsUnfilteredRows(): void
    {
        $t = $this->table()
            ->withFilterable(true)
            ->withFilter('Alice');

        // rowsList is the raw data source — always unfiltered
        $this->assertCount(5, $t->rowsList());
    }
}

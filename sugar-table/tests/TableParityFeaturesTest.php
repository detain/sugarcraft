<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Table\{Column, Row, RowData, SortType, StyledCell, Table};

/** OG-5 build arms: ST-36 sort type/hook, ST-37 filter predicate, ST-68 wrap, ST-69 static footer + format string. */
final class TableParityFeaturesTest extends TestCase
{
    /** @param list<string> $vals */
    private function table(array $vals, ?Column $col = null): Table
    {
        return Table::fromColumns([$col ?? Column::new('a', 'A', 10)])
            ->withRows(\array_map(static fn (string $v): Row => Row::new(RowData::from(['a' => $v])), $vals));
    }

    /** @return list<string> */
    private function order(Table $t): array
    {
        return \array_map(static fn (Row $r): string => (string) $r->data->get('a'), $t->filteredSortedRows());
    }

    public function testNaturalSortOrdersDigitRuns(): void
    {
        $vals = ['item10', 'item2', 'item1'];
        $this->assertSame(['item1', 'item10', 'item2'], $this->order($this->table($vals)->sortBy('a')));
        $nat = Column::new('a', 'A', 10)->withSortType(SortType::Natural);
        $this->assertSame(['item1', 'item2', 'item10'], $this->order($this->table($vals, $nat)->sortBy('a')));
        $this->assertSame(['item10', 'item2', 'item1'], $this->order($this->table($vals, $nat)->sortBy('a', false)));
    }

    public function testTextSortTypeSkipsNumericCompare(): void
    {
        $text = Column::new('a', 'A', 10)->withSortType(SortType::Text);
        $this->assertSame(['10', '9'], $this->order($this->table(['9', '10'], $text)->sortBy('a')));
        $this->assertSame(['9', '10'], $this->order($this->table(['10', '9'])->sortBy('a')));
    }

    public function testSortFuncHookGetsRawValuesAndFlipsForDescending(): void
    {
        $byLen = Column::new('a', 'A', 10)->withSortFunc(static fn ($x, $y): int => \strlen((string) $x) <=> \strlen((string) $y));
        $t = $this->table(['ccc', 'a', 'bb'], $byLen);
        $this->assertSame(['a', 'bb', 'ccc'], $this->order($t->sortBy('a')));
        $this->assertSame(['ccc', 'bb', 'a'], $this->order($t->sortBy('a', false)));
        $this->assertNull($byLen->withSortFunc(null)->sortFunc);
    }

    public function testSortFuncUnwrapsStyledCells(): void
    {
        $seen = [];
        $col = Column::new('a', 'A', 10)->withSortFunc(static function ($x, $y) use (&$seen): int {
            $seen[] = $x;
            return 0;
        });
        Table::fromColumns([$col])->withRows([
            Row::new(RowData::from(['a' => StyledCell::new('x', '31')])),
            Row::new(RowData::from(['a' => 'y'])),
        ])->sortBy('a')->filteredSortedRows();
        $this->assertNotContains(true, \array_map(static fn ($v): bool => $v instanceof StyledCell, $seen));
    }

    public function testFilterFuncReplacesSubstringSearch(): void
    {
        $t = $this->table(['apple', 'banana', 'cherry'])
            ->withFilterFunc(static fn (Row $r, string $q): bool => \strlen((string) $r->data->get('a')) === (int) $q)
            ->search('6');
        $this->assertSame(['banana', 'cherry'], $this->order($t));
        $this->assertSame(['apple'], $this->order($t->withFilterFunc(null)->search('app')));
    }

    public function testFilterFuncIsIgnoredWithoutSearchText(): void
    {
        $t = $this->table(['x', 'y'])->withFilterFunc(static fn (): bool => false);
        $this->assertCount(2, $t->filteredSortedRows());
    }

    public function testPaginationWrapCyclesCursorAndPages(): void
    {
        $t = $this->table(['a', 'b', 'c', 'd', 'e'])->withPageSize(2)->withPaginationWrap();
        $this->assertSame(4, $t->withSelectedIndex(3)->selectNext()->selectedIndex());
        $this->assertSame(0, $t->withSelectedIndex(4)->selectNext()->selectedIndex());
        $this->assertSame(4, $t->selectPrevious()->selectedIndex());
        $this->assertSame(0, $t->withPage(2)->nextPage()->currentPage());
        $this->assertSame(2, $t->previousPage()->currentPage());
    }

    public function testClampsByDefault(): void
    {
        $t = $this->table(['a', 'b', 'c'])->withPageSize(2);
        $this->assertSame(0, $t->selectPrevious()->selectedIndex());
        $this->assertSame(1, $t->withPage(1)->nextPage()->currentPage());
        $this->assertSame(0, $t->previousPage()->currentPage());
    }

    public function testStaticFooterShowsWithoutPaginationAndReplacesLabel(): void
    {
        $t = $this->table(['a'])->withStaticFooter('3 items');
        $this->assertStringContainsString('3 items', $t->view());
        $paged = $this->table(['a', 'b', 'c'])->withPageSize(2);
        $this->assertStringContainsString('Page 1', $paged->view());
        $this->assertStringNotContainsString('Page 1', $paged->withStaticFooter('hello')->view());
        $this->assertStringNotContainsString('3 items', $t->withShowFooter(false)->view());
        $this->assertSame($this->table(['a'])->view(), $t->withStaticFooter('')->view());
    }

    public function testFormatStringIsDisplayOnly(): void
    {
        $col = Column::new('a', 'A', 10)->withFormatString('$%.2f');
        $t = $this->table(['3.14159', '10'], $col)->withSelectable(false);
        $view = $t->view();
        $this->assertStringContainsString('$3.14', $view);
        $this->assertStringContainsString('$10.00', $view);
        $this->assertSame(['10', '3.14159'], $this->order($t->sortBy('a', false)), 'sort reads raw values');
        $this->assertSame('', Column::new('a', 'A', 1)->withFormatString('%s')->withFormatString('')->formatString);
    }

    public function testFormatStringAppliesInMultilineAndSkipsMissing(): void
    {
        $col = Column::new('a', 'A', 10)->withFormatString('<%s>');
        $t = Table::fromColumns([$col])->withRows([
            Row::new(RowData::from(['a' => 'x'])),
            Row::new(RowData::from(['a' => null])),
        ])->withSelectable(false);
        foreach ([false, true] as $multi) {
            $view = $t->withMultilineMode($multi)->view();
            $this->assertStringContainsString('<x>', $view);
            $this->assertStringNotContainsString('<->', $view, 'missing indicator stays unformatted');
        }
    }

    public function testInvalidFormatStringThrowsAtConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Column::new('a', 'A', 5)->withFormatString('%d %d');
    }
}

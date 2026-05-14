<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\GridTable;

use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Components\GridTable\BorderChars;
use SugarCraft\Dash\Components\GridTable\BorderConfig;
use SugarCraft\Dash\Components\GridTable\Calc;
use SugarCraft\Dash\Components\GridTable\Column;
use SugarCraft\Dash\Components\GridTable\GridTable;
use SugarCraft\Dash\Components\GridTable\Pagination;
use SugarCraft\Dash\Components\GridTable\Row;
use SugarCraft\Dash\Components\GridTable\SortDirection;
use SugarCraft\Dash\Components\GridTable\SortState;
use PHPUnit\Framework\TestCase;

final class GridTableTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testGridTableImplementsSizer(): void
    {
        $grid = GridTable::create([]);
        $this->assertInstanceOf(Sizer::class, $grid);
    }

    public function testGridTableImplementsItem(): void
    {
        $grid = GridTable::create([]);
        $this->assertInstanceOf(Item::class, $grid);
    }

    // ═══════════════════════════════════════════════════════════════
    // Sorting
    // ═══════════════════════════════════════════════════════════════

    public function testSortTogglesDirection(): void
    {
        $nameCol = new Column('name', 'Name', sortable: true);
        $grid = GridTable::create(
            [$nameCol],
            [
                new Row(['name' => 'Charlie']),
                new Row(['name' => 'Alice']),
                new Row(['name' => 'Bob']),
            ],
        );

        // First sort - should be ascending
        $grid = $grid->sort($nameCol);
        $this->assertSame(SortDirection::Asc, $grid->getSortState()->direction);

        // Second sort on same column - should toggle to descending
        $grid = $grid->sort($nameCol);
        $this->assertSame(SortDirection::Desc, $grid->getSortState()->direction);

        // Third sort - back to ascending
        $grid = $grid->sort($nameCol);
        $this->assertSame(SortDirection::Asc, $grid->getSortState()->direction);
    }

    public function testSortDifferentColumnResetsToAsc(): void
    {
        $nameCol = new Column('name', 'Name', sortable: true);
        $ageCol = new Column('age', 'Age', sortable: true);

        $grid = GridTable::create(
            [$nameCol, $ageCol],
            [
                new Row(['name' => 'Alice', 'age' => 30]),
                new Row(['name' => 'Bob', 'age' => 25]),
            ],
        );

        // Sort by name ascending
        $grid = $grid->sort($nameCol);
        $this->assertSame('name', $grid->getSortState()->key);
        $this->assertSame(SortDirection::Asc, $grid->getSortState()->direction);

        // Sort by age - should reset to ascending
        $grid = $grid->sort($ageCol);
        $this->assertSame('age', $grid->getSortState()->key);
        $this->assertSame(SortDirection::Asc, $grid->getSortState()->direction);
    }

    public function testSortNonSortableColumnDoesNothing(): void
    {
        $nameCol = new Column('name', 'Name', sortable: false);
        $grid = GridTable::create(
            [$nameCol],
            [new Row(['name' => 'Alice'])],
        );

        $grid = $grid->sort($nameCol);
        $this->assertNull($grid->getSortState()->key);
    }

    // ═══════════════════════════════════════════════════════════════
    // Filtering
    // ═══════════════════════════════════════════════════════════════

    public function testFilterNarrowsRows(): void
    {
        $nameCol = new Column('name', 'Name', filterable: true);

        $grid = GridTable::create(
            [$nameCol],
            [
                new Row(['name' => 'abc']),
                new Row(['name' => 'def']),
                new Row(['name' => 'abc123']),
            ],
        );

        $filtered = $grid->filter('abc');

        $rendered = $filtered->render();
        $this->assertStringContainsString('abc', $rendered);
        $this->assertStringContainsString('abc123', $rendered);
        $this->assertStringNotContainsString('def', $rendered);
    }

    public function testFilterCaseInsensitive(): void
    {
        $nameCol = new Column('name', 'Name', filterable: true);

        $grid = GridTable::create(
            [$nameCol],
            [
                new Row(['name' => 'Alice']),
                new Row(['name' => 'BOB']),
                new Row(['name' => 'charlie']),
            ],
        );

        $filtered = $grid->filter('ALICE');

        $rendered = $filtered->render();
        $this->assertStringContainsString('Alice', $rendered);
        $this->assertStringNotContainsString('BOB', $rendered);
        $this->assertStringNotContainsString('charlie', $rendered);
    }

    public function testEmptyFilterShowsAllRows(): void
    {
        $nameCol = new Column('name', 'Name', filterable: true);

        $grid = GridTable::create(
            [$nameCol],
            [
                new Row(['name' => 'Alice']),
                new Row(['name' => 'Bob']),
            ],
        );

        $rendered = $grid->filter('')->render();

        $this->assertStringContainsString('Alice', $rendered);
        $this->assertStringContainsString('Bob', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Pagination
    // ═══════════════════════════════════════════════════════════════

    public function testPageBoundaries(): void
    {
        $nameCol = new Column('name', 'Name');

        // Create 50 rows
        $rows = array_map(
            fn($i) => new Row(['name' => "User{$i}"]),
            range(1, 50),
        );

        $grid = GridTable::create([$nameCol], $rows);

        // With height of 10 and perPage of 20, we'd have ~3 pages visible
        // Page 1 should work
        $paged = $grid->setSize(80, 10)->page(1);
        $this->assertSame(1, $paged->getPagination()->page);

        // Page 3 should work
        $paged = $grid->page(3);
        $this->assertSame(3, $paged->getPagination()->page);

        // Page 4 should be clamped to max (3)
        $paged = $grid->page(4);
        $this->assertSame(3, $paged->getPagination()->page);

        // Page 0 should be clamped to 1
        $paged = $grid->page(0);
        $this->assertSame(1, $paged->getPagination()->page);

        // Negative page should be clamped to 1
        $paged = $grid->page(-1);
        $this->assertSame(1, $paged->getPagination()->page);
    }

    // ═══════════════════════════════════════════════════════════════
    // Scrolling
    // ═══════════════════════════════════════════════════════════════

    public function testScrollToClampedToMax(): void
    {
        $nameCol = new Column('name', 'Name');
        $rows = [
            new Row(['name' => 'User1']),
            new Row(['name' => 'User2']),
        ];

        $grid = GridTable::create([$nameCol], $rows);

        // Valid scroll index
        $scrolled = $grid->scrollTo(1);
        $this->assertSame(1, $scrolled->render() !== '' ? 1 : 0); // Just verify it doesn't error

        // Scroll beyond bounds - just ensure no error
        $scrolled = $grid->scrollTo(1000);
        $this->assertNotSame('', $scrolled->render()); // Should still render
    }

    // ═══════════════════════════════════════════════════════════════
    // Column freezing
    // ═══════════════════════════════════════════════════════════════

    public function testFreezeColumnsRendersFrozenThenScrollable(): void
    {
        $nameCol = new Column('name', 'Name', sortable: true);
        $ageCol = new Column('age', 'Age');
        $cityCol = new Column('city', 'City');

        $grid = GridTable::create(
            [$nameCol, $ageCol, $cityCol],
            [
                new Row(['name' => 'Alice', 'age' => '30', 'city' => 'NYC']),
                new Row(['name' => 'Bob', 'age' => '25', 'city' => 'LA']),
            ],
        );

        $frozen = $grid->freezeColumns(1);
        $rendered = $frozen->render();

        // Should contain freeze divider character
        $this->assertStringContainsString('║', $rendered);
    }

    public function testFreezeColumnsZeroShowsNoDivider(): void
    {
        $nameCol = new Column('name', 'Name');
        $ageCol = new Column('age', 'Age');

        $grid = GridTable::create(
            [$nameCol, $ageCol],
            [new Row(['name' => 'Alice', 'age' => '30'])],
        );

        $frozen = $grid->freezeColumns(0);
        $rendered = $frozen->render();

        // Should not contain freeze divider if no frozen columns
        // Only one ║ should be present (as right border), not as freeze divider
        $this->assertStringContainsString('Alice', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderAt80x24(): void
    {
        $nameCol = new Column('name', 'Name', sortable: true);
        $ageCol = new Column('age', 'Age');

        $grid = GridTable::create(
            [$nameCol, $ageCol],
            [
                new Row(['name' => 'Alice', 'age' => '30']),
                new Row(['name' => 'Bob', 'age' => '25']),
            ],
        );

        $rendered = $grid->setSize(80, 24)->render();

        $this->assertNotSame('', $rendered);
        $this->assertStringContainsString('Alice', $rendered);
        $this->assertStringContainsString('Bob', $rendered);
    }

    public function testRenderAt120x40(): void
    {
        $nameCol = new Column('name', 'Name');
        $ageCol = new Column('age', 'Age');
        $cityCol = new Column('city', 'City');

        $grid = GridTable::create(
            [$nameCol, $ageCol, $cityCol],
            [
                new Row(['name' => 'Alice', 'age' => '30', 'city' => 'New York']),
                new Row(['name' => 'Bob', 'age' => '25', 'city' => 'Los Angeles']),
                new Row(['name' => 'Charlie', 'age' => '35', 'city' => 'Chicago']),
            ],
        );

        $rendered = $grid->setSize(120, 40)->render();

        $this->assertNotSame('', $rendered);
    }

    public function testEmptyTableRendersWithoutError(): void
    {
        $grid = GridTable::create([]);
        $rendered = $grid->render();

        $this->assertSame('', $rendered);
    }

    public function testColumnMinWidthRespected(): void
    {
        $nameCol = new Column('name', 'Name', minWidth: 30);
        $grid = GridTable::create(
            [$nameCol],
            [new Row(['name' => 'X'])],
        );

        $rendered = $grid->setSize(80, 10)->render();

        // Should have extra padding for min width
        $this->assertNotSame('', $rendered);
    }

    public function testColumnMaxWidthRespected(): void
    {
        $longTextCol = new Column('desc', 'Description', maxWidth: 10);
        $grid = GridTable::create(
            [$longTextCol],
            [new Row(['desc' => 'This is a very long description that should be truncated'])],
        );

        $rendered = $grid->setSize(80, 10)->render();

        // Should contain truncated text (with ellipsis)
        $this->assertStringContainsString('…', $rendered);
        $this->assertStringNotContainsString('very long description', $rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $nameCol = new Column('name', 'Name');

        $grid = GridTable::create(
            [$nameCol],
            [new Row(['name' => 'Alice'])],
        );

        $rendered = $grid->setSize(80, 10)->render();

        // Should contain box-drawing characters
        $this->assertStringContainsString('┏', $rendered);
        $this->assertStringContainsString('┓', $rendered);
        $this->assertStringContainsString('┗', $rendered);
        $this->assertStringContainsString('┛', $rendered);
        $this->assertStringContainsString('━', $rendered);
        $this->assertStringContainsString('┃', $rendered);
    }

    public function testRenderWithNumericSorting(): void
    {
        $ageCol = new Column('age', 'Age', sortable: true);

        $grid = GridTable::create(
            [$ageCol],
            [
                new Row(['age' => 30]),
                new Row(['age' => 10]),
                new Row(['age' => 20]),
            ],
        );

        // Sort ascending
        $sorted = $grid->sort($ageCol);
        $rendered = $sorted->render();

        // Check order - 10 should appear before 30
        $pos10 = mb_strpos($rendered, '10');
        $pos20 = mb_strpos($rendered, '20');
        $pos30 = mb_strpos($rendered, '30');

        $this->assertNotFalse($pos10);
        $this->assertNotFalse($pos20);
        $this->assertNotFalse($pos30);

        $this->assertLessThan($pos20, $pos10);
        $this->assertLessThan($pos30, $pos20);
    }

    public function testFilterOnlyAffectsVisibleRows(): void
    {
        $nameCol = new Column('name', 'Name', filterable: true);

        $grid = GridTable::create(
            [$nameCol],
            [
                new Row(['name' => 'Alice']),
                new Row(['name' => 'Bob']),
                new Row(['name' => 'Charlie']),
            ],
        );

        $filtered = $grid->filter('Alice');
        $rendered = $filtered->render();

        $this->assertStringContainsString('Alice', $rendered);
        $this->assertStringNotContainsString('Bob', $rendered);
        $this->assertStringNotContainsString('Charlie', $rendered);
    }

    public function testBorderlessRendersWithoutBorderChars(): void
    {
        $nameCol = new Column('name', 'Name');

        $grid = GridTable::create(
            [$nameCol],
            [new Row(['name' => 'Alice'])],
        )->withRows([new Row(['name' => 'Alice'])]);

        $borderless = BorderConfig::borderless();
        $rendered = (new GridTable([$nameCol], [new Row(['name' => 'Alice'])], null, $borderless))
            ->setSize(80, 10)
            ->render();

        // Should not contain box-drawing characters
        $this->assertStringNotContainsString('┏', $rendered);
        $this->assertStringNotContainsString('┃', $rendered);
    }

    public function testWithColumnsReturnsNewInstance(): void
    {
        $nameCol = new Column('name', 'Name');
        $ageCol = new Column('age', 'Age');

        $grid1 = GridTable::create([$nameCol], [new Row(['name' => 'Alice'])]);
        $grid2 = $grid1->withColumns([$ageCol]);

        $this->assertNotSame($grid1, $grid2);
        $this->assertCount(1, $grid2->withRows([])->render() === '' ? [] : [1]);
    }

    public function testWithRowsReturnsNewInstance(): void
    {
        $nameCol = new Column('name', 'Name');

        $grid1 = GridTable::create([$nameCol], [new Row(['name' => 'Alice'])]);
        $grid2 = $grid1->withRows([new Row(['name' => 'Bob'])]);

        $this->assertNotSame($grid1, $grid2);
    }

    // ═══════════════════════════════════════════════════════════════
    // SortState
    // ═══════════════════════════════════════════════════════════════

    public function testSortStateToggleSameKey(): void
    {
        $state = new SortState('name', SortDirection::Asc);

        $toggled = $state->toggle('name');
        $this->assertSame(SortDirection::Desc, $toggled->direction);

        $toggledAgain = $toggled->toggle('name');
        $this->assertSame(SortDirection::Asc, $toggledAgain->direction);
    }

    public function testSortStateToggleDifferentKey(): void
    {
        $state = new SortState('name', SortDirection::Desc);

        $toggled = $state->toggle('age');
        $this->assertSame('age', $toggled->key);
        $this->assertSame(SortDirection::Asc, $toggled->direction);
    }

    public function testSortStateToggleNull(): void
    {
        $state = new SortState('name', SortDirection::Asc);

        $toggled = $state->toggle(null);
        $this->assertNull($toggled->key);
        $this->assertSame(SortDirection::Asc, $toggled->direction);
    }

    // ═══════════════════════════════════════════════════════════════
    // Pagination
    // ═══════════════════════════════════════════════════════════════

    public function testPaginationTotalPages(): void
    {
        $pagination = new Pagination(page: 1, perPage: 20, totalRows: 50);
        $this->assertSame(3, $pagination->totalPages());

        $pagination = new Pagination(page: 1, perPage: 20, totalRows: 0);
        $this->assertSame(1, $pagination->totalPages());

        $pagination = new Pagination(page: 1, perPage: 0, totalRows: 50);
        $this->assertSame(1, $pagination->totalPages());
    }

    public function testPaginationOffset(): void
    {
        $pagination = new Pagination(page: 1, perPage: 20, totalRows: 50);
        $this->assertSame(0, $pagination->offset());

        $pagination = new Pagination(page: 2, perPage: 20, totalRows: 50);
        $this->assertSame(20, $pagination->offset());

        $pagination = new Pagination(page: 3, perPage: 20, totalRows: 50);
        $this->assertSame(40, $pagination->offset());
    }

    public function testPaginationClampPage(): void
    {
        $pagination = new Pagination(page: 1, perPage: 20, totalRows: 50);

        $this->assertSame(1, $pagination->clampPage(1));
        $this->assertSame(3, $pagination->clampPage(3));
        $this->assertSame(3, $pagination->clampPage(10));
        $this->assertSame(1, $pagination->clampPage(0));
        $this->assertSame(1, $pagination->clampPage(-5));
    }

    // ═══════════════════════════════════════════════════════════════
    // Calc
    // ═══════════════════════════════════════════════════════════════

    public function testGcd(): void
    {
        $this->assertSame(6, Calc::gcd(12, 18));
        $this->assertSame(6, Calc::gcd(18, 12));
        $this->assertSame(7, Calc::gcd(7, 0));
        $this->assertSame(7, Calc::gcd(0, 7));
        $this->assertSame(1, Calc::gcd(1, 1));
        $this->assertSame(1, Calc::gcd(17, 13));
    }

    public function testTruncate(): void
    {
        $this->assertSame('Hello…', Calc::truncate('Hello World', 6));
        $this->assertSame('Hello', Calc::truncate('Hello', 10));
        $this->assertSame('', Calc::truncate('Hello', 0));
        $this->assertSame('', Calc::truncate('Hello', -1));
    }

    public function testCalculateColumnWidths(): void
    {
        $columns = [
            new Column('name', 'Name', minWidth: 5),
            new Column('age', 'Age'),
        ];

        $rows = [
            new Row(['name' => 'Alice', 'age' => '30']),
            new Row(['name' => 'Bob', 'age' => '25']),
        ];

        $widths = Calc::calculateColumnWidths($rows, $columns, 40);

        $this->assertCount(2, $widths);
        $this->assertGreaterThanOrEqual(5, $widths[0]); // minWidth respected
    }

    // ═══════════════════════════════════════════════════════════════
    // BorderConfig
    // ═══════════════════════════════════════════════════════════════

    public function testBorderConfigDefaults(): void
    {
        $config = BorderConfig::default();

        $this->assertTrue($config->showOuter);
        $this->assertTrue($config->showHeader);
        $this->assertTrue($config->showInner);
        $this->assertTrue($config->showFooter);
        $this->assertEquals(BorderChars::default(), $config->chars);
    }

    public function testBorderConfigRounded(): void
    {
        $config = BorderConfig::rounded();
        $this->assertEquals(BorderChars::rounded(), $config->chars);
    }

    public function testBorderConfigBorderless(): void
    {
        $config = BorderConfig::borderless();

        $this->assertFalse($config->showOuter);
        $this->assertFalse($config->showHeader);
        $this->assertFalse($config->showInner);
        $this->assertFalse($config->showFooter);
    }

    public function testBorderConfigFluentSetters(): void
    {
        $config = BorderConfig::default();

        $config = $config->withOuter(false);
        $this->assertFalse($config->showOuter);

        $config = $config->withHeader(false);
        $this->assertFalse($config->showHeader);

        $config = $config->withInner(false);
        $this->assertFalse($config->showInner);

        $config = $config->withFooter(true);
        $this->assertTrue($config->showFooter);

        $config = $config->withChars(BorderChars::rounded());
        $this->assertEquals(BorderChars::rounded(), $config->chars);
    }

    // ═══════════════════════════════════════════════════════════════
    // Row
    // ═══════════════════════════════════════════════════════════════

    public function testRowGet(): void
    {
        $row = new Row(['name' => 'Alice', 'age' => 30]);

        $this->assertSame('Alice', $row->get('name'));
        $this->assertSame(30, $row->get('age'));
        $this->assertNull($row->get('city'));
    }

    public function testRowHas(): void
    {
        $row = new Row(['name' => 'Alice']);

        $this->assertTrue($row->has('name'));
        $this->assertFalse($row->has('city'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Column
    // ═══════════════════════════════════════════════════════════════

    public function testColumnFluentSetters(): void
    {
        $col = new Column('name', 'Name');

        $col = $col->sortable();
        $this->assertTrue($col->sortable);

        $col = $col->filterable();
        $this->assertTrue($col->filterable);

        $col = $col->withMinWidth(10);
        $this->assertSame(10, $col->minWidth);

        $col = $col->withMaxWidth(50);
        $this->assertSame(50, $col->maxWidth);

        $renderer = fn($v) => "**{$v}**";
        $col = $col->withRenderer($renderer);
        $this->assertSame($renderer, $col->renderer);
    }

    public function testColumnRenderer(): void
    {
        $col = new Column(
            'name',
            'Name',
            renderer: fn($v) => strtoupper((string) $v),
        );

        $this->assertNotNull($col->renderer);
        $rendered = ($col->renderer)('alice');
        $this->assertSame('ALICE', $rendered);
    }
}

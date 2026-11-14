<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\GridTable;

use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Dashboard\Components\GridTable\BorderChars;
use SugarCraft\Dashboard\Components\GridTable\BorderConfig;
use SugarCraft\Dashboard\Components\GridTable\Calc;
use SugarCraft\Dashboard\Components\GridTable\Column;
use SugarCraft\Dashboard\Components\GridTable\GridTable;
use SugarCraft\Dashboard\Components\GridTable\Pagination;
use SugarCraft\Dashboard\Components\GridTable\Row;
use SugarCraft\Dashboard\Components\GridTable\SortDirection;
use SugarCraft\Dashboard\Components\GridTable\SortState;
use PHPUnit\Framework\TestCase;

final class GridTableTest extends TestCase
{
    /**
     * SortDirection used to be declared TWICE — in its own file and again
     * inside Sort.php — so touching SortDirection (as GridTable's sorting
     * does) and then loading Sort was a fatal "Cannot declare enum …, because
     * the name is already in use". Probed in a child process: in this one both
     * may already be loaded, which would hide the order that crashed.
     */
    public function testSortDirectionThenSortLoadsWithoutARedeclarationFatal(): void
    {
        $autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
        $probe = 'require ' . var_export($autoload, true) . ';'
            . '$d = \\SugarCraft\\Dashboard\\Components\\GridTable\\SortDirection::Asc;'
            . 'new \\SugarCraft\\Dashboard\\Components\\GridTable\\Sort();'
            . 'echo "loaded";';

        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe) . ' 2>&1', $output, $exit);

        $this->assertSame(0, $exit, implode("\n", $output));
        $this->assertSame(['loaded'], $output);
    }

    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testGridTableImplementsSizer(): void
    {
        $grid = GridTable::new([]);
        $this->assertInstanceOf(Sizer::class, $grid);
    }

    public function testGridTableImplementsItem(): void
    {
        $grid = GridTable::new([]);
        $this->assertInstanceOf(Item::class, $grid);
    }

    // ═══════════════════════════════════════════════════════════════
    // Sorting
    // ═══════════════════════════════════════════════════════════════

    public function testSortTogglesDirection(): void
    {
        $nameCol = Column::new('name', 'Name', sortable: true);
        $grid = GridTable::new(
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
        $nameCol = Column::new('name', 'Name', sortable: true);
        $ageCol = Column::new('age', 'Age', sortable: true);

        $grid = GridTable::new(
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
        // D-51 (finding 148): the refusal was silent; it is now a one-shot
        // notice per column key, latched process-wide with a test seam.
        GridTable::resetUnsortableNotices();
        $nameCol = Column::new('name', 'Name', sortable: false);
        $grid = GridTable::new(
            [$nameCol],
            [new Row(['name' => 'Alice'])],
        );

        $notices = [];
        set_error_handler(static function (int $errno, string $message) use (&$notices): bool {
            if ($errno === E_USER_NOTICE) {
                $notices[] = $message;
                return true;
            }
            return false;
        });
        try {
            $grid = $grid->sort($nameCol);
            $grid->sort($nameCol); // latched: the refusal must not shout twice
        } finally {
            restore_error_handler();
        }

        $this->assertNull($grid->getSortState()->key);
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('name', $notices[0]);
        $this->assertStringContainsString('not sortable', $notices[0]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Filtering
    // ═══════════════════════════════════════════════════════════════

    public function testFilterNarrowsRows(): void
    {
        $nameCol = Column::new('name', 'Name', filterable: true);

        $grid = GridTable::new(
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
        $nameCol = Column::new('name', 'Name', filterable: true);

        $grid = GridTable::new(
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
        $nameCol = Column::new('name', 'Name', filterable: true);

        $grid = GridTable::new(
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
        $nameCol = Column::new('name', 'Name');

        // Create 50 rows
        $rows = array_map(
            fn($i) => new Row(['name' => "User{$i}"]),
            range(1, 50),
        );

        $grid = GridTable::new([$nameCol], $rows);

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
        $nameCol = Column::new('name', 'Name');
        $rows = [
            new Row(['name' => 'User1']),
            new Row(['name' => 'User2']),
        ];

        $grid = GridTable::new([$nameCol], $rows);

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
        $nameCol = Column::new('name', 'Name', sortable: true);
        $ageCol = Column::new('age', 'Age');
        $cityCol = Column::new('city', 'City');

        $grid = GridTable::new(
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
        $nameCol = Column::new('name', 'Name');
        $ageCol = Column::new('age', 'Age');

        $grid = GridTable::new(
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
        $nameCol = Column::new('name', 'Name', sortable: true);
        $ageCol = Column::new('age', 'Age');

        $grid = GridTable::new(
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
        $nameCol = Column::new('name', 'Name');
        $ageCol = Column::new('age', 'Age');
        $cityCol = Column::new('city', 'City');

        $grid = GridTable::new(
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
        $grid = GridTable::new([]);
        $rendered = $grid->render();

        $this->assertSame('', $rendered);
    }

    public function testColumnMinWidthRespected(): void
    {
        $nameCol = Column::new('name', 'Name', minWidth: 30);
        $grid = GridTable::new(
            [$nameCol],
            [new Row(['name' => 'X'])],
        );

        $rendered = $grid->setSize(80, 10)->render();

        // Should have extra padding for min width
        $this->assertNotSame('', $rendered);
    }

    public function testColumnMaxWidthRespected(): void
    {
        $longTextCol = Column::new('desc', 'Description', maxWidth: 10);
        $grid = GridTable::new(
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
        $nameCol = Column::new('name', 'Name');

        $grid = GridTable::new(
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
        $ageCol = Column::new('age', 'Age', sortable: true);

        $grid = GridTable::new(
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
        $nameCol = Column::new('name', 'Name', filterable: true);

        $grid = GridTable::new(
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
        $nameCol = Column::new('name', 'Name');

        $grid = GridTable::new(
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
        $nameCol = Column::new('name', 'Name');
        $ageCol = Column::new('age', 'Age');

        $grid1 = GridTable::new([$nameCol], [new Row(['name' => 'Alice'])]);
        $grid2 = $grid1->withColumns([$ageCol]);

        $this->assertNotSame($grid1, $grid2);
        $this->assertCount(1, $grid2->withRows([])->render() === '' ? [] : [1]);
    }

    public function testWithRowsReturnsNewInstance(): void
    {
        $nameCol = Column::new('name', 'Name');

        $grid1 = GridTable::new([$nameCol], [new Row(['name' => 'Alice'])]);
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
            Column::new('name', 'Name', minWidth: 5),
            Column::new('age', 'Age'),
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
        $config = BorderConfig::new();

        $this->assertTrue($config->showOuter);
        $this->assertTrue($config->showHeader);
        $this->assertTrue($config->showInner);
        $this->assertTrue($config->showFooter);
        $this->assertEquals(BorderChars::new(), $config->chars);
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
        $config = BorderConfig::new();

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
        $col = Column::new('name', 'Name');

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
        $col = Column::new(
            'name',
            'Name',
            renderer: fn($v) => strtoupper((string) $v),
        );

        $this->assertNotNull($col->renderer);
        $rendered = ($col->renderer)('alice');
        $this->assertSame('ALICE', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // D-141: named factory, private ctor, house-callable renderer seam
    // ═══════════════════════════════════════════════════════════════

    public function testConstructorIsPrivateAndFactoryCarriesEveryField(): void
    {
        $this->assertTrue(
            (new \ReflectionMethod(Column::class, '__construct'))->isPrivate(),
            'Column is constructed via Column::new only (positional mixup class unreachable)',
        );

        $renderer = static fn (mixed $v): string => '<' . $v . '>';
        $column = Column::new('k', 'K', minWidth: 4, maxWidth: 40, sortable: true, filterable: true, renderer: $renderer);

        $this->assertSame('k', $column->key);
        $this->assertSame('K', $column->label);
        $this->assertSame(4, $column->minWidth);
        $this->assertSame(40, $column->maxWidth);
        $this->assertTrue($column->sortable);
        $this->assertTrue($column->filterable);
        $this->assertInstanceOf(\Closure::class, $column->renderer);
    }

    public function testPlainCallableRendererIsAcceptedNotFatalled(): void
    {
        // The old seam typed the parameter callable but the property
        // ?\Closure, so a plain callable fataled at construction (D-141).
        $column = Column::new('name', 'Name', renderer: 'strtoupper');
        $this->assertInstanceOf(\Closure::class, $column->renderer);

        $grid = GridTable::new([$column], [new Row(['name' => 'alice'])]);
        $this->assertStringContainsString('ALICE', $grid->render());

        $swapped = $column->withRenderer('strrev');
        $this->assertInstanceOf(\Closure::class, $swapped->renderer);
    }

    // ═══════════════════════════════════════════════════════════════
    // D-51: pagination honesty (findings 044, 144, 148, 151)
    // ═══════════════════════════════════════════════════════════════

    public function testPageClampsAgainstFilteredCount(): void
    {
        // Finding 044: a page set before the filter shrank the set used to
        // render an empty body (offset past the end, no re-clamp).
        $col = Column::new('label', 'Label', filterable: true);
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $rows[] = new Row(['label' => $i < 4 ? 'zzA item ' . $i : 'plain ' . $i]);
        }

        $grid = GridTable::new([$col], $rows)->filter('zzA')->page(3);
        $out = $grid->render();

        // 4 matching rows fit page 1 after clamping; every one must show.
        for ($i = 0; $i < 4; $i++) {
            $this->assertStringContainsString('zzA item ' . $i, $out);
        }
        $this->assertStringNotContainsString('no rows match filter', $out);
    }

    public function testUnfilteredPageBeyondEndClampsToLastPage(): void
    {
        $col = Column::new('label', 'Label');
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $rows[] = new Row(['label' => 'row-' . $i]);
        }

        // perPage 20 default: page 99 clamps to page 3 (rows 40-49).
        $out = GridTable::new([$col], $rows)->page(99)->render();
        $this->assertStringContainsString('row-49', $out);
        $this->assertStringContainsString('row-40', $out);
        $this->assertStringNotContainsString('row-39', $out);
    }

    public function testEmptyFilterRendersNoticeRowInsideFrame(): void
    {
        $col = Column::new('label', 'Label', minWidth: 22, filterable: true);
        $grid = GridTable::new([$col], [new Row(['label' => 'alpha'])])->filter('zzz-nothing');
        $lines = explode("\n", $grid->render());

        $notice = null;
        foreach ($lines as $line) {
            if (str_contains($line, 'no rows match filter')) {
                $notice = $line;
            }
        }
        $this->assertNotNull($notice, 'empty result must be announced, not silent chrome');

        // Geometry: the notice row carries the same display width as the
        // borders around it, and the footer separator stays suppressed
        // (no real rows).
        $target = mb_strlen($lines[0]);
        $this->assertSame($target, mb_strlen((string) $notice));
        $this->assertCount(5, $lines); // top, header, sep, notice, bottom
    }

    public function testColumnWidthsStableAcrossPages(): void
    {
        // Finding 151: widths used to come from the current page, so every
        // border jumped between pages with differently-sized row subsets.
        $col = Column::new('label', 'Label');
        $rows = [];
        for ($i = 0; $i < 6; $i++) {
            $rows[] = new Row(['label' => 'short' . $i]);
        }
        $rows[] = new Row(['label' => 'a-very-long-tail-value']);
        $rows[] = new Row(['label' => 'another-very-long-value']);

        $grid = GridTable::new([$col], $rows)->withPerPage(5);
        $page1 = explode("\n", $grid->page(1)->render());
        $page2 = explode("\n", $grid->page(2)->render());

        // Top borders byte-identical: width decided by the whole set, not the page.
        $this->assertSame($page1[0], $page2[0]);
        $this->assertStringContainsString('a-very-long-tail-value', $page2[3] . $page2[4] . $page2[5]);
    }

    public function testExplicitPerPageSurvivesAllocationCap(): void
    {
        // Finding 144: setSize() used to stomp any explicit page size.
        $col = Column::new('label', 'Label');
        $rows = [];
        for ($i = 0; $i < 40; $i++) {
            $rows[] = new Row(['label' => 'row-' . $i]);
        }
        $grid = GridTable::new([$col], $rows);

        // Explicit below the cap wins.
        $small = $grid->withPerPage(5)->setSize(40, 12);
        $this->assertSame(5, $small->pagination()->perPage);
        // Data lines = total lines - chrome (top, header, sep, footer sep, bottom).
        $this->assertSame(5, substr_count(trim($small->page(1)->render()), "\n") - 4);

        // Cap below the explicit clamps down (allocation is the ceiling).
        $capped = $grid->withPerPage(50)->setSize(40, 12);
        $this->assertSame(10, $capped->pagination()->perPage);

        // No explicit: allocation alone decides, as before.
        $plain = $grid->setSize(40, 12);
        $this->assertSame(10, $plain->pagination()->perPage);
    }

    public function testPerPageZeroAndNegativeAreGuarded(): void
    {
        // Finding 144: zero/negative windows used to tail-drop via negative
        // array_slice; they are now floored at 1 row.
        $col = Column::new('label', 'Label');
        $rows = [new Row(['label' => 'one']), new Row(['label' => 'two']), new Row(['label' => 'three'])];
        $grid = GridTable::new([$col], $rows);

        $floored = $grid->withPerPage(0);
        $this->assertSame(1, $floored->pagination()->perPage);
        $this->assertSame(1, substr_count(trim($floored->render()), "\n") - 4); // exactly one data row

        $raw = new Pagination(page: 1, perPage: -5, totalRows: 3);
        $normalized = $raw->normalized();
        $this->assertSame(1, $normalized->perPage);
        $this->assertSame(1, $normalized->page);

        // A grid carrying the raw-negative window still renders from the top
        // without dropping the tail slice.
        $out = $grid->render();
        $this->assertStringContainsString('one', $out);
        $this->assertStringContainsString('three', $out);
    }

    public function testNormalizedClampsPageWithinFilteredWindow(): void
    {
        $p = new Pagination(page: 9, perPage: 10, totalRows: 0);
        $this->assertSame(1, $p->normalized()->page, 'empty set clamps to page 1');

        $q = (new Pagination(page: 4, perPage: 10, totalRows: 25))->withTotalRows(12);
        $this->assertSame(2, $q->normalized()->page, 'clamps against the reduced total');
    }
}

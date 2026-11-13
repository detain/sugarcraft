<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Table;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Style\Style;
use SugarCraft\Widgets\Table\SortDirection;
use SugarCraft\Widgets\Table\Styles;
use SugarCraft\Widgets\Table\Table;

/**
 * Pins for the 2026-11 review fixes on {@see Table}: cursor/page
 * reconciliation (M1/M2), sort-state revalidation (M4), the visible sort
 * indicator (M5), per-slot style defaults (M6), the page-size error message
 * (m11), list-extreme navigation (m15) and cell-ingress sanitisation
 * (C3/M20).
 */
final class TableReviewPinsTest extends TestCase
{
    private function table(): Table
    {
        return Table::new(
            ['Name', 'Age'],
            [
                ['Alice', '30'],
                ['Bob', '25'],
                ['Carol', '40'],
                ['Dave', '35'],
                ['Eve', '28'],
                ['Frank', '45'],
                ['Grace', '32'],
                ['Henry', '27'],
            ],
            0,
            10,
        );
    }

    /** M2: the cursor carries its page, so the highlight and selectedRow() agree. */
    public function testMovingTheCursorAcrossPageBorderFollowsThePage(): void
    {
        $table = $this->table()->withPageSize(3);

        $moved = $table->setCursor(5);

        $this->assertSame(1, $moved->currentPage(), 'row 5 of size-3 pages is page 1');
        $this->assertSame(['Frank', '45'], $moved->selectedRow());

        // The rendered window must contain the highlighted row — the page
        // the view shows is the page the cursor lives on.
        $view = $moved->view();
        $this->assertStringContainsString('Frank', $view);
        $this->assertStringNotContainsString('Alice', $view, 'page 0 rows are no longer rendered');
    }

    /** M1: a shrinking projection re-anchors the page instead of rendering a header-only frame. */
    public function testFilterShrinkReconcilesTheStalePage(): void
    {
        $table = $this->table()
            ->withFilterable(true)
            ->withPageSize(3)
            ->withPage(2)
            ->withFilter('Alice');

        $this->assertSame(1, $table->paginator()->totalPages());
        $this->assertSame(0, $table->currentPage());
        $this->assertStringContainsString('Alice', $table->view(), 'the surviving row must render');
    }

    /** M4: dropping a sorted-away column removes its criterion instead of re-targeting another. */
    public function testShrinkingHeadersDropsTheVanishedSortCriterion(): void
    {
        $sorted = $this->table()->withSort('Age', SortDirection::Desc);
        $this->assertFalse($sorted->sortState()->isEmpty());

        $shrunk = $sorted->setHeaders(['Name']);

        $this->assertTrue($shrunk->sortState()->isEmpty(), 'a sort on a removed column must be dropped');
        $this->assertStringNotContainsString("\u{2193}", $shrunk->view());
        $this->assertStringNotContainsString("\u{2191}", $shrunk->view());
    }

    /** M4 (keep-half): criteria still inside the new column count survive. */
    public function testShrinkingHeadersKeepsStillReachableCriteria(): void
    {
        $sorted = $this->table()
            ->withSort('Name', SortDirection::Asc)
            ->thenSortBy('Age', SortDirection::Desc);

        $kept = $sorted->setHeaders(['Name', 'Age', 'Extra']);

        $this->assertCount(2, $kept->sortState()->criteria);
        $this->assertStringContainsString("\u{2191}", $kept->view());
        $this->assertStringContainsString("\u{2193}", $kept->view());
    }

    /** M5: sorting is visible in the header, and the direction glyph matches. */
    public function testSortIndicatorRendersOnTheSortedColumn(): void
    {
        $ascending  = $this->table()->withSort('Name', SortDirection::Asc)->view();
        $descending = $this->table()->withSort('Name', SortDirection::Desc)->view();

        $this->assertStringContainsString("Name \u{2191}", $ascending);
        $this->assertStringNotContainsString("\u{2193}", $ascending);
        $this->assertStringNotContainsString("Age \u{2191}", $ascending, 'only the sorted column is marked');
        $this->assertStringContainsString("Name \u{2193}", $descending);
    }

    /** M5 width-safety: the indicator is priced into the column layout. */
    public function testSortIndicatorDoesNotOverflowTheHeaderCell(): void
    {
        $rows = [
            ['Al', '1'],
            ['Bo', '2'],
        ];
        $table = Table::new(['X', 'Y'], $rows, 0, 10)->withSort('X');

        // Column 0 fits 'X ↑' (3 cells) without truncating the glyph.
        $this->assertStringContainsString("X \u{2191}", $table->view());
    }

    /** M6: a partial Styles keeps the selection cursor (default reverse). */
    public function testPartialStylesKeepsTheSelectionCursor(): void
    {
        $table = $this->table()->withStyles(new Styles(header: Style::new()->bold()));
        $focused = $table->focus();

        $view = $focused->view();
        $this->assertStringContainsString("\x1b[7mAlice", $view, 'selection must stay visible');
        // The supplied header slot still wins.
        $this->assertStringContainsString("\x1b[1mName", $view);
    }

    /** M6: a partial Styles keeps the header affordance (default underline). */
    public function testPartialStylesKeepsTheHeaderUnderline(): void
    {
        $table = $this->table()->withStyles(new Styles(cell: Style::new()->italic()));

        $view = $table->view();
        $this->assertStringContainsString("\x1b[4mName", $view);
        $this->assertStringContainsString("\x1b[3mAlice", $view);
    }

    /** m11: the page-size refusal names the page size, not the table dimensions. */
    public function testNegativePageSizeThrowsItsOwnMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('page size must be >= 0');

        $this->table()->withPageSize(-1);
    }

    /** m15: pageFirst/pageLast target the list extremes (Bubbles GotoStart/GotoEnd). */
    public function testPageFirstAndPageLastLandOnListExtremes(): void
    {
        $table = $this->table()->withPageSize(3);

        $last = $table->pageLast();
        $this->assertSame(7, $last->cursor());
        $this->assertSame(['Henry', '27'], $last->selectedRow());
        $this->assertSame(2, $last->currentPage());

        $first = $last->pageFirst();
        $this->assertSame(0, $first->cursor());
        $this->assertSame(['Alice', '30'], $first->selectedRow());
        $this->assertSame(0, $first->currentPage());
    }

    /** C3/M20: forged zone sentinels and 8-bit C1 intros cannot ride a cell into the frame. */
    public function testCellIngressStripsSentinelsAndC1Intros(): void
    {
        $table = Table::new(['Name'], [["A\u{E000}B\u{009B}C"]], 0, 10);

        $view = $table->view();
        $this->assertStringNotContainsString("\u{E000}", $view);
        $this->assertStringNotContainsString("\u{E001}", $view);
        $this->assertStringNotContainsString("\u{009B}", $view);
        $this->assertStringContainsString('ABC', $view);
    }
}

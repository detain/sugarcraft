<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Gallery\PosterCard;
use SugarCraft\Gallery\PosterGrid;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Zone\Manager as ZoneManager;

final class PosterGridTest extends TestCase
{
    /**
     * A standard grid: cardWidth 10, posterHeight 3 (→ cellHeight 5), spacing
     * 2/1, viewport 48×18 → 4 columns × 3 visible rows.
     */
    private function grid(int $total): PosterGrid
    {
        return PosterGrid::new(10, 3, 2, 1)->withViewport(48, 18)->reset($total);
    }

    /** @param int[] $indices @return array<int, PosterCard> */
    private function cardsAt(array $indices): array
    {
        $items = [];
        foreach ($indices as $i) {
            $items[$i] = new PosterCard((string) $i, 'Item ' . $i);
        }

        return $items;
    }

    public function testGeometry(): void
    {
        $g = $this->grid(50);

        self::assertSame(4, $g->columns());
        self::assertSame(3, $g->visibleRows());
        self::assertSame(5, $g->cellHeight());
        self::assertSame(13, $g->totalRows(), 'ceil(50/4) = 13');
        self::assertSame(50, $g->total());
    }

    public function testEmptyGrid(): void
    {
        $g = $this->grid(0);

        self::assertTrue($g->isEmpty());
        self::assertSame(0, $g->totalRows());
        self::assertSame('', $g->render());
        self::assertSame([0, -1], $g->visibleRange());
        // Navigation is a safe no-op on an empty grid.
        self::assertSame($g, $g->right());
        self::assertSame($g, $g->down());
        self::assertSame($g, $g->end());
    }

    public function testResetReturnsToTop(): void
    {
        $g = $this->grid(50)->moveTo(40);
        self::assertGreaterThan(0, $g->scrollRow());

        $g = $g->reset(20);
        self::assertSame(20, $g->total());
        self::assertSame(0, $g->cursorIndex());
        self::assertSame(0, $g->scrollRow());
        self::assertSame(0, $g->loadedCount());
    }

    public function testHorizontalNavigationClamps(): void
    {
        $g = $this->grid(50);

        self::assertSame($g, $g->left(), 'left at index 0 is a no-op');
        $g = $g->right()->right()->right();
        self::assertSame(3, $g->cursorIndex());

        $end = $this->grid(50)->end();
        self::assertSame(49, $end->cursorIndex());
        self::assertSame($end, $end->right(), 'right at the last item is a no-op');
    }

    public function testVerticalNavigation(): void
    {
        $g = $this->grid(50);

        self::assertSame($g, $g->up(), 'up in the top row is a no-op');

        $g = $g->down(); // 0 → 4 (one full row)
        self::assertSame(4, $g->cursorIndex());
        $g = $g->up();   // 4 → 0
        self::assertSame(0, $g->cursorIndex());
    }

    public function testDownStepsOntoLastItemOfAPartialRow(): void
    {
        // total 10, 4 cols → rows [0-3][4-7][8-9].
        $g = $this->grid(10)->moveTo(6); // row 1, col 2
        $g = $g->down();
        self::assertSame(9, $g->cursorIndex(), 'no full row below → jump to the last item');

        self::assertSame($g, $g->down(), 'already on the last row → no-op');
    }

    public function testScrollFollowsCursorDownAndUp(): void
    {
        $g = $this->grid(50); // visibleRows 3, scroll 0
        $g = $g->down()->down()->down(); // 0→4→8→12 (row 3)
        self::assertSame(12, $g->cursorIndex());
        self::assertSame(1, $g->scrollRow(), 'row 3 pushed the viewport down by one');

        $g = $g->up()->up()->up(); // back to row 0
        self::assertSame(0, $g->cursorIndex());
        self::assertSame(0, $g->scrollRow(), 'viewport scrolled back to the top');
    }

    public function testEndAndHomeScroll(): void
    {
        $g = $this->grid(50)->end();
        self::assertSame(49, $g->cursorIndex());
        self::assertSame(10, $g->scrollRow(), 'max scroll = totalRows(13) - visibleRows(3)');

        $g = $g->home();
        self::assertSame(0, $g->cursorIndex());
        self::assertSame(0, $g->scrollRow());
    }

    public function testPageDownAndPageUp(): void
    {
        $g = $this->grid(50);
        $g = $g->pageDown(); // + columns*visibleRows = +12
        self::assertSame(12, $g->cursorIndex());

        $g = $g->pageUp();
        self::assertSame(0, $g->cursorIndex());

        // page down past the end clamps to the last item.
        $g = $this->grid(50)->pageDown()->pageDown()->pageDown()->pageDown()->pageDown();
        self::assertSame(49, $g->cursorIndex());
    }

    public function testMoveToJumpsAndScrolls(): void
    {
        $g = $this->grid(50)->moveTo(26); // row 6
        self::assertSame(26, $g->cursorIndex());
        self::assertSame(4, $g->scrollRow(), 'row 6 with 3 visible rows → scroll 6-3+1 = 4');

        // Out-of-range jumps clamp.
        self::assertSame(49, $g->moveTo(999)->cursorIndex());
        self::assertSame(0, $g->moveTo(-5)->cursorIndex());
    }

    public function testVisibleRange(): void
    {
        $g = $this->grid(50);
        self::assertSame([0, 11], $g->visibleRange(), 'rows 0-2 × 4 cols = indices 0-11');

        self::assertSame([0, 15], $g->visibleRange(1), 'one overscan row adds 4 more');

        $g = $g->end();
        self::assertSame([40, 49], $g->visibleRange(), 'clamped to total at the bottom');
    }

    public function testVisibleRangeClampsOverscanAtTop(): void
    {
        $g = $this->grid(50);
        // Overscan above row 0 cannot go negative.
        self::assertSame(0, $g->visibleRange(5)[0]);
    }

    public function testWithItemsSplicesAtAbsoluteIndex(): void
    {
        $g = $this->grid(50)->withItems($this->cardsAt([20, 21, 22]));

        self::assertSame(3, $g->loadedCount());
        self::assertSame('20', $g->item(20)?->id);
        self::assertNull($g->item(0), 'untouched indices stay empty');
        self::assertSame(50, $g->total(), 'splicing does not change the total');
    }

    public function testWithItemsMergesPages(): void
    {
        $g = $this->grid(50)
            ->withItems($this->cardsAt([0, 1]))
            ->withItems($this->cardsAt([10, 11]));

        self::assertSame(4, $g->loadedCount());
        self::assertNotNull($g->item(1));
        self::assertNotNull($g->item(10));
    }

    public function testWithItemAndCursorCard(): void
    {
        $g = $this->grid(50)->withItem(0, new PosterCard('0', 'First'));
        self::assertSame('First', $g->cursorCard()?->title);
        self::assertSame($g, $g->withItem(-1, new PosterCard('x', 'bad')), 'negative index ignored');
    }

    public function testWithTotalClampsCursor(): void
    {
        $g = $this->grid(50)->end(); // cursor 49
        $g = $g->withTotal(20);

        self::assertSame(20, $g->total());
        self::assertSame(19, $g->cursorIndex(), 'cursor clamped into the smaller set');
    }

    public function testWithViewportReclampsScroll(): void
    {
        $g = $this->grid(50)->end(); // scroll 10 with 3 visible rows
        // Grow the viewport so more rows fit → scroll must shrink.
        $g = $g->withViewport(48, 60); // visibleRows = (60+1)/6 = 10
        self::assertSame(10, $g->visibleRows());
        self::assertSame(3, $g->scrollRow(), 'max scroll = 13 - 10 = 3');
    }

    public function testRenderSkeletonForMissingItems(): void
    {
        $out = $this->grid(8)->render();

        self::assertStringContainsString('░', $out, 'unloaded cells render as skeletons');
    }

    public function testRenderShowsFocusedCardOnlyWhenFocused(): void
    {
        $g = $this->grid(4)->withItems($this->cardsAt([0, 1, 2, 3]));

        self::assertStringContainsString('▸', $g->render(true));
        self::assertStringNotContainsString('▸', $g->render(false), 'an unfocused grid draws no cursor');
        self::assertStringContainsString('Item 0', $g->render(true));
    }

    public function testRenderHeightMatchesVisibleRows(): void
    {
        // 3 visible rows × cellHeight 5 + 2 vertical gaps = 17.
        self::assertSame(17, Layout::height($this->grid(50)->render()));

        // Only two rows of content (total 6, 4 cols) → 5 + 1 + 5 = 11.
        self::assertSame(11, Layout::height($this->grid(6)->render()));
    }

    public function testRenderMarksZonesForMouseHitTesting(): void
    {
        $zones = ZoneManager::newGlobal();
        $rendered = $this->grid(8)->render(true, $zones);
        $zones->scan($rendered);

        self::assertNotNull($zones->get('cell:0'), 'each cell is a hit-testable zone');
        self::assertNotNull($zones->get('cell:5'));
    }

    public function testPopulatedGridIsNotEmptyAndCursorCardIsNullUntilLoaded(): void
    {
        $g = $this->grid(50);
        self::assertFalse($g->isEmpty());
        self::assertNull($g->cursorCard(), 'no page loaded yet');
        self::assertNotNull($g->withItem(0, new PosterCard('0', 'First'))->cursorCard());
    }

    public function testCursorRowTracksTheCursor(): void
    {
        $g = $this->grid(50);
        self::assertSame(0, $g->cursorRow());
        self::assertSame(6, $g->moveTo(26)->cursorRow(), 'index 26 / 4 cols = row 6');
    }

    public function testWithItemsSkipsNegativeKeys(): void
    {
        $g = $this->grid(50)->withItems([-1 => new PosterCard('x', 'bad'), 2 => new PosterCard('2', 'ok')]);

        self::assertSame(1, $g->loadedCount());
        self::assertNotNull($g->item(2));
    }

    public function testOversizedPosterIsBoxedSoColumnsStayAligned(): void
    {
        // A poster line far wider than the 10-cell card must not inflate its
        // column: box() clips every line to cardWidth so all rows match width.
        $wide = (new PosterCard('0', 'Wide'))->withPoster(str_repeat('X', 40));
        $rendered = $this->grid(4)->withItems([0 => $wide])->render(true);

        $widths = array_map(static fn (string $l): int => Layout::width($l), explode("\n", $rendered));
        self::assertCount(1, array_unique($widths), 'every grid row is the same width despite the oversized poster');
    }

    public function testImmutabilityNoOpReturnsSameInstance(): void
    {
        $g = $this->grid(50);
        $moved = $g->right();

        self::assertNotSame($g, $moved);
        self::assertSame(0, $g->cursorIndex(), 'the original grid is unchanged');
    }
}

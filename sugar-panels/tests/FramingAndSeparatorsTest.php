<?php

declare(strict_types=1);

namespace SugarCraft\Panels\Tests;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Panels\Node;
use SugarCraft\Panels\SugarPanels;
use SugarCraft\Panels\Tests\Support\ReadsFrames;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;
use PHPUnit\Framework\TestCase;

/**
 * Framing, separators and the caps that decide where they land.
 *
 * @see SugarPanels::renderHorizontal()
 */
final class FramingAndSeparatorsTest extends TestCase
{
    use ReadsFrames;

    /**
     * A spacing-0 divider used to overwrite the last cell of its neighbour,
     * which is exactly the cell the renderer parks an auto-reset in when a line
     * leaves a style open — so the neighbour's span bled through the divider and
     * into the rest of the row. The divider closes it instead.
     */
    public function testTheDividerClosesTheNeighboursSpan(): void
    {
        $style = Style::new()->fg(Color::ansi(1));
        $layout = Node::horizontal(
            Node::leaf('AA')->withBorder(false)->withStyle($style)->withMinWidth(3),
            Node::leaf('BB')->withBorder(false)->withMinWidth(3),
        )->withBorder(false)->withSpacing(0);

        $frame = self::body(SugarPanels::new()->render($layout, 7, 1));

        // The divider sits at the boundary and carries the reset itself.
        $this->assertStringContainsString("\x1b[0m│", $frame);
        // Nothing after the divider is still inside the first child's span: the
        // painted row must contain a reset at or before the divider, and the
        // second child's cells must not re-open anything.
        $after = \explode('│', $frame, 2);
        $this->assertCount(2, $after);
        $this->assertStringNotContainsString("\x1b[", \str_replace("\x1b[0m", '', $after[1]));
    }

    /**
     * The divider landed on a bordered neighbour's own edge column and shaved the
     * corner glyphs off the frame it had just drawn: ╮/╯ became │ runs and the
     * stacked case wiped ╰───╯ into a bare dash. A neighbour that paints its own
     * edge IS the divider, so it is left alone.
     */
    public function testABorderedNeighboursCornersSurviveASpacingZeroDivider(): void
    {
        $layout = Node::horizontal(
            Node::leaf('AA'),
            Node::leaf('BB'),
        )->withBorder(false)->withSpacing(0);

        $rows = \explode("\n", Ansi::strip(self::body(SugarPanels::new()->render($layout, 16, 3))));

        // Each child fills the half of the row it was given; the point is that the
        // boundary column keeps the ╮/╯ it was drawn with instead of a │.
        $this->assertSame('╭──────╮╭──────╮', $rows[0]);
        $this->assertSame('│AA    ││BB    │', $rows[1]);
        $this->assertSame('╰──────╯╰──────╯', $rows[2]);
    }

    /** @see testABorderedNeighboursCornersSurviveASpacingZeroDivider() stacked. */
    public function testAStackedBorderedNeighboursEdgeSurvives(): void
    {
        $layout = Node::vertical(
            Node::leaf('TOP'),
            Node::leaf('BOT'),
        )->withBorder(false)->withSpacing(0);

        $rows = \explode("\n", Ansi::strip(self::body(SugarPanels::new()->render($layout, 9, 8))));

        // Two full frames stacked: the lower box's top corners are intact, and
        // the upper box's bottom corners survived where the divider would sit.
        $this->assertSame('╭───────╮', $rows[0]);
        $this->assertSame('│TOP    │', $rows[1]);
        $this->assertSame('╰───────╯', $rows[3]);
        $this->assertSame('╭───────╮', $rows[4]);
        $this->assertSame('│BOT    │', $rows[5]);
        $this->assertSame('╰───────╯', $rows[7]);
    }

    /**
     * A borderless neighbour has no edge of its own, so the divider is still the
     * only thing separating the two — the skip must not swallow it.
     */
    public function testABorderlessNeighbourStillGetsItsDivider(): void
    {
        $layout = Node::horizontal(
            Node::leaf('AAAA')->withBorder(false),
            Node::leaf('BBBB')->withBorder(false),
        )->withBorder(false)->withSpacing(0);

        $row = Ansi::strip(self::body(SugarPanels::new()->render($layout, 10, 1)));

        $this->assertStringContainsString('│', $row);
    }

    /**
     * noBorder() is documented as "children rendered flat without vertical
     * separators", but the wrapper handed the region straight to its child, so
     * wrapping a bordered panel changed nothing at all. Inside the wrapper every
     * frame and every spacing-0 divider is now suppressed.
     */
    public function testTheNoBorderWrapperActuallyFlattensItsSubtree(): void
    {
        $column = Node::vertical(
            Node::leaf('one'),
            Node::leaf('two'),
        );

        $wrapped = Ansi::strip(self::body(SugarPanels::new()->render(Node::noBorder($column), 9, 8)));
        $plain   = Ansi::strip(self::body(SugarPanels::new()->render($column, 9, 8)));

        // Unwrapped: the stacked leaves each paint a frame.
        $this->assertStringContainsString('╭', $plain);
        // Wrapped: no frame glyphs anywhere, and the text is still there.
        $this->assertStringNotContainsString('╭', $wrapped);
        $this->assertStringNotContainsString('│', $wrapped);
        $this->assertStringContainsString('one', $wrapped);
        $this->assertStringContainsString('two', $wrapped);
    }

    /**
     * A frameless subtree must not charge for the frames it cannot paint: the
     * wrapper used to add its own default border to the flex basis and pass the
     * children's border allowance through untouched, so it reserved four columns
     * that never appeared.
     */
    public function testASuppressedSubtreeChargesNoFrameColumns(): void
    {
        $width = Node::noBorder(Node::leaf('text'))->totalWidth();

        // 'text' is four columns and nothing else: no wrapper border, no child border.
        $this->assertSame(4, $width);
        $this->assertSame(1, Node::noBorder(Node::leaf('text'))->totalHeight());
    }

    /**
     * maxWidth capped the CONTENT box of a leaf while capping the TOTAL region of
     * a child inside a layout, so the same setter meant two things: a capped leaf
     * still painted a full-size frame around narrower text, and a vertical stack
     * ignored the width cap entirely. It now caps the footprint everywhere.
     */
    public function testMaxWidthCapsTheWholeFootprintOfALeaf(): void
    {
        $leaf = Node::leaf('ABCDEFGHIJ')->withMaxWidth(6);

        $frame = self::body(SugarPanels::new()->render($leaf, 20, 2));

        // The frame is six columns wide, not the twenty it was handed; the rest of
        // the row is the viewport's own blank.
        $rows = \explode("\n", $frame);
        $this->assertSame('╭────╮' . \str_repeat(' ', 14), Ansi::strip($rows[0]));
        $this->assertSame(6, $leaf->totalWidth());
    }

    /** @see testMaxWidthCapsTheWholeFootprintOfALeaf() — the stacked leg. */
    public function testMaxWidthCapsAStackedChildToo(): void
    {
        $layout = Node::vertical(
            Node::leaf('ABCDEFGHIJ')->withBorder(false)->withMaxWidth(4),
        )->withBorder(false);

        $row = Ansi::strip(self::body(SugarPanels::new()->render($layout, 10, 1)));

        // Four columns of content, then the viewport's blank: the stacked child no
        // longer paints past its own cap.
        $this->assertSame('ABCD', \rtrim($row));
    }

    /**
     * Titles went through none of the content path's safety machinery: the
     * trailing escape run was dropped on the floor, so a styled title left the
     * terminal in the title's colour for the rest of the row and beyond, and a
     * single grapheme cluster could carry an unbounded payload into one cell.
     */
    public function testAStyledTitleClosesItsOwnSpan(): void
    {
        $style = Style::new()->fg(Color::ansi(1));
        $node  = Node::leaf('body')->withTitle('T')->withStyle($style);

        $frame = self::body(SugarPanels::new()->render($node, 12, 3));
        $row   = Ansi::strip($frame);

        // The title glyph is painted on the border row…
        $this->assertStringContainsString('T', $row);
        // …and the row it shares with plain frame characters is neutral again:
        // a reset has been placed at the last title cell, so nothing after the
        // title inherits the colour.
        $this->assertStringContainsString("\x1b[0m", $frame);
        $boundary = \strpos($frame, 'T');
        $this->assertIsInt($boundary);
        $this->assertStringContainsString("\x1b[0m", \substr($frame, $boundary));

        // The visible shape is unchanged: exactly one title cell, corners intact.
        $this->assertSame('╭T─────────╮', \explode("\n", $row)[0]);
    }

    /**
     * The earlier skip rule asked the neighbouring NODE whether it was bordered,
     * which only saw the direct child: a borderless container of bordered panels
     * still ran its divider across the grandchildren's corners. Asking the grid
     * fixes every depth, and the README's own sketches are pinned here so the
     * documented output stays the measured output.
     */
    public function testTheDocumentedLayoutsPaintWhatTheReadmeShows(): void
    {
        $panels = SugarPanels::new();
        $nested = $panels->vertical(
            $panels->horizontal($panels->leaf('Left panel'), $panels->leaf('Right panel')),
            $panels->leaf('Bottom bar'),
        );
        $rows = $this->rows($panels->render($nested, 40, 5));
        $this->assertSame([
            '╭──────────────────────────────────────╮',
            '│──────────────────────────────────────│',
            '│╭────────────────────────────────────╮│',
            '│╰────────────────────────────────────╯│',
            '╰──────────────────────────────────────╯',
        ], $rows, 'the Quick Start sketch is a promise');

        $fresh = SugarPanels::new();
        $flat = $fresh->vertical(
            $fresh->horizontal($fresh->leaf('Left panel'), $fresh->leaf('Right panel'))->withBorder(false),
            $fresh->leaf('Bottom bar')->withBorder(false),
        )->withBorder(false);
        $rows = $this->rows($fresh->render($flat, 60, 7));
        $this->assertSame([
            '╭────────────────────────────╮╭────────────────────────────╮',
            '│Left panel                  ││Right panel                 │',
            '│                            ││                            │',
            '╰────────────────────────────╯╰────────────────────────────╯',
            'Bottom bar',
            '',
            '',
        ], $rows, 'the flattened Quick Start sketch is a promise (rows carry the viewport height)');

        $third = SugarPanels::new();
        $dashboard = $third->horizontal(
            $third->leaf("nav\nhome\nabout")->withBorder(false),
            $third->leaf('main content area')->withBorder(false)->withGrow(),
        )->withBorder(false)->withSpacing(1);
        $rows = $this->rows($third->render($dashboard, 40, 3));
        $this->assertSame([
            'nav   main content area',
            'home',
            'about',
        ], $rows, 'the natural-size sketch is a promise');
    }

    /**
     * At spacing 0 the divider has no gap of its own. A free column still gets
     * it; a column that already shows a glyph does not, which is what keeps a
     * neighbour's text and corners intact.
     */
    /**
     * A padded tail is not an invitation. At spacing 0 the divider lands on the
     * previous child's last row, where a short line has left blank cells behind;
     * cell-by-cell it cut a rule through the middle of the neighbour's text row.
     * The verdict is per span, so a row that carries text is left intact while a
     * genuinely free row still gets its separator.
     */
    public function testAPaddedTailDoesNotReceiveHalfADivider(): void
    {
        $rows = self::rows(SugarPanels::new()->render(
            Node::vertical(
                Node::leaf('HEADER')->withBorder(false),
                Node::leaf('body')->withBorder(false)->withGrow(),
                Node::leaf('READY')->withBorder(false),
            )->withBorder(false),
            20,
            4,
        ));

        // The free row between two children keeps its divider - that is what
        // spacing(0) promises - but no row is ever half text, half rule.
        self::assertSame(['HEADER', 'body', str_repeat('─', 20), 'READY'], $rows);
        foreach ($rows as $row) {
            $visible = trim(str_replace('─', '', $row));
            $hasRule = str_contains($row, '─');
            self::assertFalse(
                $hasRule && $visible !== '',
                'a divider cut through a neighbour\'s text row: ' . $row,
            );
        }
    }

    public function testTheDividerTakesAFreeColumnAndLeavesAPaintedOne(): void
    {
        $panels = SugarPanels::new();
        $free = $panels->horizontal(
            $panels->leaf('AA')->withBorder(false)->withMinWidth(3),
            $panels->leaf('BB')->withBorder(false),
        )->withBorder(false)->withSpacing(0);
        $this->assertSame('AA  │B', \rtrim($this->rows($panels->render($free, 6, 1))[0]),
            'a free boundary column still gets the divider');

        $fresh = SugarPanels::new();
        $occupied = $fresh->horizontal(
            $fresh->leaf('AA')->withBorder(false),
            $fresh->leaf('BB')->withBorder(false),
        )->withBorder(false)->withSpacing(0);
        // Four columns split two-and-two: the divider's column belongs to the
        // left leaf, so nothing is painted over and both words survive.
        $this->assertSame('AABB', \rtrim($this->rows($fresh->render($occupied, 4, 1))[0]),
            'a divider may not eat a neighbour\'s glyph');
    }

    /**
     * sugar-panels-020: a stacked divider spans the container's FULL inner width,
     * not the previous child's (possibly maxWidth-capped) width. The vertical
     * divider between columns already spans the full inner height, so a short
     * horizontal seam under a narrow child read as an unfinished rule.
     */
    public function testAStackedDividerSpansTheContainerNotTheCappedChild(): void
    {
        // A maxWidth-6 child whose last row is free (minHeight 3 leaves it blank),
        // stacked over a grown child in a 12-wide borderless container.
        $rows = self::rows(SugarPanels::new()->render(
            Node::vertical(
                Node::leaf('TOP')->withBorder(false)->withMaxWidth(6)->withMinHeight(3),
                Node::leaf('bottom')->withBorder(false)->withGrow(),
            )->withBorder(false),
            12,
            5,
        ));

        // The seam is the full 12-column width, not the child's capped 6.
        $this->assertSame('────────────', $rows[2], 'a stacked seam spans the container, mirroring the full-height column divider');

        // And the free-span rule still stands down a seam that would cut painted
        // cells: cap the child's row so it carries text on the divider row.
        $occupied = self::rows(SugarPanels::new()->render(
            Node::vertical(
                Node::leaf('TOP')->withBorder(false)->withMaxWidth(6),
                Node::leaf('bottom')->withBorder(false)->withGrow(),
            )->withBorder(false),
            12,
            2,
        ));
        // Two rows, no blank divider row to claim — no half rule is painted.
        $this->assertSame('TOP', $occupied[0]);
        $this->assertSame('bottom', \rtrim($occupied[1], '─ '));
    }

    /** Strip the frame protocol and trailing blanks, keeping empty rows. */
    private function rows(string $frame): array
    {
        return self::rowsOf($frame, strip: true, rtrim: true);
    }

    /**
     * A title can be hostile without being long: one grapheme cluster of thousands
     * of combining marks used to be stored verbatim in a single border cell,
     * because the byte budget lives in the content path and titles never visited
     * it.
     */
    public function testATitlePayloadIsByteBoundedLikeContent(): void
    {
        $marks  = \str_repeat("\xcc\x81", 20_000); // U+0301: combining, so one cluster
        $node   = Node::leaf('x')->withTitle('T' . $marks);
        $rendered = SugarPanels::new()->render($node, 12, 3);

        $this->assertLessThan(40_000, \strlen($rendered), 'a title must not carry its whole payload into one cell');
        // The frame is still there, still the right visible width.
        // The border row keeps its visible width: the marks ride one cell instead
        // of inflating the row, and the frame still closes on the right column.
        $this->assertSame(12, Width::string(Ansi::strip(\explode("\n", $rendered)[0])));
    }
}

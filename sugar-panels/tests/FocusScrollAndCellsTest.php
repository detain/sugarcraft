<?php

declare(strict_types=1);

namespace SugarCraft\Panels\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Hyperlink;
use SugarCraft\CellBuffer\Style as CellStyle;
use SugarCraft\Panels\Node;
use SugarCraft\Panels\SugarPanels;
use SugarCraft\Panels\Tests\Support\ReadsFrames;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;

/**
 * The focus frame, the leaf scroll offset, and leaves that paint cells.
 */
final class FocusScrollAndCellsTest extends TestCase
{
    use ReadsFrames;

    public function testAFocusedFrameUsesTheThickSetByDefault(): void
    {
        $rows = self::rowsOf(SugarPanels::new()->render(Node::leaf('hi')->withFocused(true), 6, 3));

        $this->assertSame(['┏━━━━┓', '┃hi  ┃', '┗━━━━┛'], $rows);
    }

    public function testAnUnfocusedFrameIsUnchanged(): void
    {
        $plain = SugarPanels::new()->render(Node::leaf('hi'), 6, 3);
        $off   = SugarPanels::new()->render(Node::leaf('hi')->withFocused(true)->withFocused(false), 6, 3);

        $this->assertSame($plain, $off);
        $this->assertSame(['╭────╮', '│hi  │', '╰────╯'], self::rowsOf($plain));
    }

    public function testTheFocusFrameTakesAChosenBorderAndPen(): void
    {
        $node = Node::leaf('x')
            ->withFocused(true)
            ->withFocusBorderStyle(Border::double())
            ->withFocusStyle(Style::new()->bold());

        $rows = self::rowsOf(SugarPanels::new()->render($node, 3, 3));

        $this->assertSame("\x1b[1m╔\x1b[0m\x1b[1m═\x1b[0m\x1b[1m╗\x1b[0m", $rows[0]);
        $this->assertSame("\x1b[1m║\x1b[0mx\x1b[1m║\x1b[0m", $rows[1]);
    }

    public function testTheFocusFrameKeepsTheTitle(): void
    {
        $rows = self::rowsOf(SugarPanels::new()->render(Node::leaf('')->withTitle('ab')->withFocused(true), 6, 2));

        $this->assertSame('┏ab━━┓', $rows[0]);
    }

    public function testFocusSettersAreImmutable(): void
    {
        $node = Node::leaf('x');
        $focused = $node->withFocused(true);

        $this->assertNotSame($node, $focused);
        $this->assertFalse($node->focused);
        $this->assertTrue($focused->focused);
        $this->assertNull($focused->withFocusStyle(Style::new())->withFocusStyle(null)->focusStyle);
        $this->assertNull($focused->withFocusBorderStyle(Border::double())->withFocusBorderStyle(null)->focusBorderStyle);
    }

    public function testScrollOffsetSkipsRowsFromTheTop(): void
    {
        $node = Node::leaf("one\ntwo\nthree\nfour\nfive")->withBorder(false)->withScrollOffset(2);

        $rows = self::rowsOf(SugarPanels::new()->render($node, 5, 2), rtrim: true);

        $this->assertSame(['three', 'four'], $rows);
    }

    public function testScrollOffsetClampsToTheLastPage(): void
    {
        $node = Node::leaf("one\ntwo\nthree\nfour\nfive")->withBorder(false)->withScrollOffset(99);

        $rows = self::rowsOf(SugarPanels::new()->render($node, 5, 2), rtrim: true);

        $this->assertSame(['four', 'five'], $rows);
    }

    public function testScrollOffsetCountsWrappedRows(): void
    {
        $node = Node::leaf('aa bb cc dd')->withBorder(false)->withScrollOffset(1);

        $rows = self::rowsOf(SugarPanels::new()->render($node, 2, 2), rtrim: true);

        $this->assertSame(['bb', 'cc'], $rows);
    }

    public function testNegativeScrollOffsetClampsToZero(): void
    {
        $this->assertSame(0, Node::leaf('x')->withScrollOffset(-3)->scrollOffset);
    }

    public function testALeafPaintsCellsWithTheirOwnPen(): void
    {
        $red = CellStyle::new(0xff0000);
        $buffer = Buffer::new(3, 1)
            ->withCellAt(0, 0, Cell::new('o', $red))
            ->withCellAt(1, 0, Cell::new('k'));

        $rows = self::rowsOf(SugarPanels::new()->render(Node::leaf($buffer)->withBorder(false), 4, 1));

        $this->assertSame($red->toAnsi() . "o\x1b[0mk  ", $rows[0]);
    }

    public function testCellsLeafMeasuresItsBuffer(): void
    {
        $node = Node::leaf(Buffer::new(7, 3));

        $this->assertNull(Node::leaf('x')->cells);
        $this->assertSame(9, $node->totalWidth());
        $this->assertSame(5, $node->totalHeight());
    }

    public function testCellsLeafKeepsWideCellsAndClipsAtTheEdge(): void
    {
        $buffer = Buffer::new(5, 1)
            ->withCellAt(0, 0, Cell::new('a'))
            ->withCellAt(1, 0, Cell::new('語', null, null, 2))
            ->withCellAt(3, 0, Cell::new('語', null, null, 2));

        $rows = self::rowsOf(SugarPanels::new()->render(Node::leaf($buffer)->withBorder(false), 4, 1));

        $this->assertSame('a語 ', $rows[0], 'the second wide cell would straddle the edge, so it is dropped');
    }

    public function testCellsLeafScrollsByRowAndCarriesLinks(): void
    {
        $link = Hyperlink::new('https://example.com');
        $buffer = Buffer::new(2, 3)
            ->withCellAt(0, 0, Cell::new('1'))
            ->withCellAt(0, 1, Cell::new('2'))
            ->withCellAt(0, 2, Cell::new('3', null, $link));

        $rows = self::rowsOf(SugarPanels::new()->render(Node::leaf($buffer)->withBorder(false)->withScrollOffset(2), 2, 1));

        $this->assertSame($link->toAnsi() . '3' . Hyperlink::CLOSE . ' ', $rows[0]);
    }

    public function testCellRunesPassThePaintBoundary(): void
    {
        $buffer = Buffer::new(2, 1)->withCellAt(0, 0, Cell::new("\x1b[2J"));

        $rows = self::rowsOf(SugarPanels::new()->render(Node::leaf($buffer)->withBorder(false), 2, 1));

        $this->assertStringNotContainsString("\x1b[2J", $rows[0]);
    }

    public function testWithCellsSwapsBackToText(): void
    {
        $node = Node::leaf('text')->withBorder(false)->withCells(Buffer::new(2, 1))->withCells(null);

        $this->assertSame('text', self::rowsOf(SugarPanels::new()->render($node, 4, 1))[0]);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Panels\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Panels\Node;
use SugarCraft\Panels\Splitter;
use SugarCraft\Panels\SugarPanels;
use SugarCraft\Panels\Tests\Support\ReadsFrames;

/**
 * Splitter: sizes along one axis, divider hit test, and divider drag driven
 * through update() with mouse messages.
 */
final class SplitterTest extends TestCase
{
    use ReadsFrames;

    private static function mouse(int $x, int $y, MouseAction $action, MouseButton $button = MouseButton::Left): MouseMsg
    {
        return new MouseMsg($x, $y, $button, $action);
    }

    public function testBoundsShareTheSpanEquallyWithTheRemainderOnTheLastPane(): void
    {
        $split = Splitter::horizontal(Node::leaf('a'), Node::leaf('b'), Node::leaf('c'))->withBounds(0, 0, 10, 4);

        $this->assertSame([3, 3, 4], $split->sizes);
        $this->assertSame([2, 5], $split->dividerPositions());
    }

    public function testDividerAtUsesScreenCoordinatesAcrossTheCrossExtent(): void
    {
        $split = Splitter::horizontal(Node::leaf('a'), Node::leaf('b'))->withBounds(10, 5, 20, 4);

        $this->assertSame(0, $split->dividerAt(19, 5));
        $this->assertSame(0, $split->dividerAt(19, 8));
        $this->assertNull($split->dividerAt(19, 9), 'below the splitter');
        $this->assertNull($split->dividerAt(18, 6), 'inside pane 0');
        $this->assertNull($split->dividerAt(29, 6), 'the last pane edge is not a divider');
    }

    public function testDividerDragThroughUpdatePressMotionRelease(): void
    {
        $split = Splitter::horizontal(Node::leaf('left'), Node::leaf('right'))->withBounds(0, 0, 40, 10);

        [$split, $cmd] = $split->update(self::mouse(19, 3, MouseAction::Press));
        $this->assertNull($cmd);
        $this->assertTrue($split->isDragging());
        $this->assertSame(0, $split->dragging);

        [$split, $cmd] = $split->update(self::mouse(9, 3, MouseAction::Motion));
        $this->assertNull($cmd);
        $this->assertSame([10, 30], $split->sizes);
        $this->assertTrue($split->isDragging());

        [$split] = $split->update(self::mouse(14, 7, MouseAction::Release));
        $this->assertSame([15, 25], $split->sizes, 'the release lands the divider where the pointer let go');
        $this->assertFalse($split->isDragging());

        [$after] = $split->update(self::mouse(30, 3, MouseAction::Motion));
        $this->assertSame($split, $after, 'motion with nothing grabbed is ignored');
    }

    public function testVerticalSplitterDragsByRow(): void
    {
        $split = Splitter::vertical(Node::leaf('top'), Node::leaf('bottom'))->withBounds(0, 2, 20, 10);
        $this->assertSame([6], $split->dividerPositions());

        [$split] = $split->update(self::mouse(4, 6, MouseAction::Press));
        [$split] = $split->update(self::mouse(4, 9, MouseAction::Motion));

        $this->assertSame([8, 2], $split->sizes);
    }

    public function testPressOffADividerAndOtherMessagesLeaveTheSplitterAlone(): void
    {
        $split = Splitter::horizontal(Node::leaf('a'), Node::leaf('b'))->withBounds(0, 0, 20, 4);

        [$pressed] = $split->update(self::mouse(3, 1, MouseAction::Press));
        [$right] = $split->update(self::mouse(9, 1, MouseAction::Press, MouseButton::Right));
        [$key] = $split->update(new KeyMsg(KeyType::Enter));

        $this->assertSame($split, $pressed);
        $this->assertSame($split, $right);
        $this->assertSame($split, $key);
    }

    public function testDragClampsBothNeighboursToTheMinimumAndTouchesOnlyThem(): void
    {
        $split = Splitter::horizontal(Node::leaf('a'), Node::leaf('b'), Node::leaf('c'))
            ->withBounds(0, 0, 30, 4)
            ->withMinPaneSize(4);

        $left = $split->withDividerDrag(0, 0);
        $this->assertSame([4, 16, 10], $left->sizes);

        $right = $split->withDividerDrag(28, 0);
        $this->assertSame([16, 4, 10], $right->sizes);
    }

    public function testDividerDragWithoutAGrabMovesTheNearestDivider(): void
    {
        $split = Splitter::horizontal(Node::leaf('a'), Node::leaf('b'), Node::leaf('c'))->withBounds(0, 0, 30, 4);

        $this->assertSame([10, 13, 7], $split->withDividerDrag(22)->sizes);
    }

    public function testWithDividerDragIsImmutable(): void
    {
        $split = Splitter::horizontal(Node::leaf('a'), Node::leaf('b'))->withBounds(0, 0, 20, 4);
        $moved = $split->withDividerDrag(4);

        $this->assertNotSame($split, $moved);
        $this->assertSame([10, 10], $split->sizes);
        $this->assertSame([5, 15], $moved->sizes);
    }

    public function testResizeKeepsProportions(): void
    {
        $split = Splitter::horizontal(Node::leaf('a'), Node::leaf('b'))->withBounds(0, 0, 40, 4)->withSizes(10, 30);

        $this->assertSame([20, 60], $split->withBounds(0, 0, 80, 4)->sizes);
    }

    public function testSizesSetBeforeBoundsAreFittedWhenBoundsArrive(): void
    {
        $before = Splitter::horizontal(Node::leaf('a'), Node::leaf('b'))->withSizes(30, 70);
        $this->assertSame([], $before->sizes, 'no span yet, so nothing is fitted');

        $this->assertSame([30, 70], $before->withBounds(0, 0, 100, 5)->sizes);
        $this->assertSame([15, 35], $before->withBounds(0, 0, 50, 5)->sizes);
        $this->assertSame([3, 7], $before->withPanes(Node::leaf('x'), Node::leaf('y'))->withBounds(0, 0, 10, 1)->sizes);
        $this->assertSame([3, 3, 4], $before->withPanes(Node::leaf('x'), Node::leaf('y'), Node::leaf('z'))->withBounds(0, 0, 10, 1)->sizes, 'a new pane count drops the request');

        $bounded = $before->withBounds(0, 0, 100, 5)->withDividerDrag(49);
        $this->assertSame([50, 50], $bounded->withBounds(0, 0, 100, 5)->sizes, 'a fitted request is not replayed by a later withBounds()');
    }

    public function testNodePaintsEachPaneAtItsSize(): void
    {
        $split = Splitter::horizontal(
            Node::leaf(\str_repeat('A', 40))->withBorder(false),
            Node::leaf(\str_repeat('B', 40))->withBorder(false),
            Node::leaf(\str_repeat('C', 40))->withBorder(false),
        )->withBounds(0, 0, 40, 1)->withSizes(7, 13, 20);

        $rows = self::rowsOf(SugarPanels::new()->render($split->node(), 40, 1), strip: true);

        $this->assertSame(\str_repeat('A', 7) . \str_repeat('B', 13) . \str_repeat('C', 20), $rows[0]);
    }

    public function testDraggedDividerMovesThePaintedSeparator(): void
    {
        $split = Splitter::horizontal(Node::leaf('')->withBorder(false), Node::leaf('')->withBorder(false))
            ->withBounds(0, 0, 12, 2);
        [$split] = $split->update(self::mouse(5, 0, MouseAction::Press));
        [$split] = $split->update(self::mouse(2, 0, MouseAction::Release));

        $rows = self::rowsOf(SugarPanels::new()->render($split->node(), 12, 2), strip: true);

        $this->assertSame('  │         ', $rows[0], 'divider 0 is painted on its new column');
        $this->assertSame(0, $split->dividerAt(2, 1));
    }

    public function testWithPanesKeepsSizesForTheSameCountAndDropsTheGrab(): void
    {
        $split = Splitter::horizontal(Node::leaf('a'), Node::leaf('b'))->withBounds(0, 0, 20, 4)->withSizes(5, 15);
        [$split] = $split->update(self::mouse(4, 0, MouseAction::Press));

        $same = $split->withPanes(Node::leaf('x'), Node::leaf('y'));
        $more = $split->withPanes(Node::leaf('x'), Node::leaf('y'), Node::leaf('z'));

        $this->assertSame([5, 15], $same->sizes);
        $this->assertFalse($same->isDragging());
        $this->assertSame([6, 6, 8], $more->sizes);
    }
}

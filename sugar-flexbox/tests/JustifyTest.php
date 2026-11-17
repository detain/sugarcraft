<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Flexbox\Flex\{Align, Direction, FlexBox, FlexItem, Justify, Wrap};

/**
 * Pins {@see Justify}: how a FlexBox places leftover main-axis cells. Replaces
 * the justify cases in sugar-dashboard's FlexLayoutTest, which only asserted
 * non-empty output; these assert the exact cells. The WP asks for the dashboard
 * tests to move here, but the move is deferred: sugar-dashboard's FlexLayout
 * still calls JustifyContent (FlexLayout.php:433-575), so its tests cover live
 * code. They are removed with the dashboard retirement in P3-12, and this branch
 * deletes none.
 */
final class JustifyTest extends TestCase
{
    /** Three fixed two-cell items: 6 cells used, so a width of 10 leaves 4. */
    private static function abc(): array
    {
        return [
            FlexItem::new('a')->withBasis(2),
            FlexItem::new('b')->withBasis(2),
            FlexItem::new('c')->withBasis(2),
        ];
    }

    public function testTheDefaultIsStartAndLeavesTheLineUnpadded(): void
    {
        $box = FlexBox::row(...self::abc());

        self::assertSame(Justify::Start, $box->justify);
        self::assertSame('a b c ', $box->render(10, 1));
    }

    public function testEndPacksTheItemsToTheRight(): void
    {
        $out = FlexBox::row(...self::abc())->withJustify(Justify::End)->render(10, 1);

        self::assertSame('    a b c ', $out);
    }

    public function testCenterSplitsTheLeftoverWithTheOddCellTrailing(): void
    {
        // Leftover 3 at width 7: one leading cell, two trailing (floor on the leading side).
        $out = FlexBox::row(
            FlexItem::new('a')->withBasis(2),
            FlexItem::new('b')->withBasis(2),
        )->withJustify(Justify::Center)->render(7, 1);

        self::assertSame(' a b   ', $out);
    }

    public function testSpaceBetweenPutsTheLeftoverOnlyBetweenItems(): void
    {
        $out = FlexBox::row(...self::abc())->withJustify(Justify::SpaceBetween)->render(10, 1);

        // Two interior slots of two cells each: a at 0, b at 4, c at 8.
        self::assertSame('a   b   c ', $out);
    }

    public function testSpaceAroundGivesEachItemEqualSpaceWithHalfSizeEdges(): void
    {
        // Half-slot weights 1,2,2,1: leftover 4 floors to 0,1,1,0, and the
        // two remainder cells go to the trailing slots first, giving 0,1,2,1.
        $out = FlexBox::row(...self::abc())->withJustify(Justify::SpaceAround)->render(10, 1);

        self::assertSame('a  b   c  ', $out);
    }

    public function testAnOddCellOfOneItemTrailsUnderSpaceEvenlyAndSpaceAroundAsUnderCenter(): void
    {
        // Leftover 7 at width 9: the odd cell trails, so the single item sits
        // exactly where Center puts it, not one cell right.
        $item = static fn (): FlexItem => FlexItem::new('a')->withBasis(2);
        $center = FlexBox::row($item())->withJustify(Justify::Center)->render(9, 1);

        self::assertSame('   a     ', $center);
        self::assertSame($center, FlexBox::row($item())->withJustify(Justify::SpaceEvenly)->render(9, 1));
        self::assertSame($center, FlexBox::row($item())->withJustify(Justify::SpaceAround)->render(9, 1));
    }

    public function testSpaceEvenlyGivesEveryGapIncludingEdgesTheSameSize(): void
    {
        $out = FlexBox::row(...self::abc())->withJustify(Justify::SpaceEvenly)->render(10, 1);

        self::assertSame(' a  b  c  ', $out);
    }

    public function testSpaceBetweenWithOneItemBehavesAsStart(): void
    {
        $out = FlexBox::row(FlexItem::new('a')->withBasis(2))
            ->withJustify(Justify::SpaceBetween)
            ->render(10, 1);

        self::assertSame('a ', $out);
    }

    public function testSpaceAroundWithOneItemCentresIt(): void
    {
        $out = FlexBox::row(FlexItem::new('a')->withBasis(2))
            ->withJustify(Justify::SpaceAround)
            ->render(10, 1);

        self::assertSame('    a     ', $out);
    }

    public function testTheGapIsAMinimumAndTheJustifiedShareAddsOnTop(): void
    {
        $start = FlexBox::row(...self::abc())->withGap(1)->render(10, 1);
        $between = FlexBox::row(...self::abc())
            ->withGap(1)
            ->withJustify(Justify::SpaceBetween)
            ->render(10, 1);

        // Start keeps only the gap; SpaceBetween adds one cell to each of the two gaps.
        self::assertSame('a  b  c ', $start);
        self::assertSame('a   b   c ', $between);
    }

    public function testEveryJustifyFillsTheWidthExactlyWhenThereIsLeftover(): void
    {
        foreach ([Justify::End, Justify::Center, Justify::SpaceBetween, Justify::SpaceAround, Justify::SpaceEvenly] as $justify) {
            foreach ([7, 8, 9, 10, 11, 13] as $width) {
                $line = FlexBox::row(...self::abc())->withJustify($justify)->render($width, 1);

                self::assertSame(
                    $width,
                    Width::string($line),
                    "{$justify->name} at width {$width} must fill the line exactly",
                );
            }
        }
    }

    public function testColumnSpaceBetweenSpreadsRowsAlongTheHeight(): void
    {
        // Leftover 3 over two interior slots: the remainder cell lands in the last one.
        $out = FlexBox::column(
            FlexItem::new('a')->withBasis(1),
            FlexItem::new('b')->withBasis(1),
            FlexItem::new('c')->withBasis(1),
        )->withJustify(Justify::SpaceBetween)->render(5, 6);

        self::assertSame(
            "a    \n     \nb    \n     \n     \nc    ",
            $out,
        );
    }

    public function testColumnEndMovesTheRowsToTheBottom(): void
    {
        $out = FlexBox::column(
            FlexItem::new('a')->withBasis(1),
            FlexItem::new('b')->withBasis(1),
            FlexItem::new('c')->withBasis(1),
        )->withJustify(Justify::End)->render(5, 6);

        self::assertSame("     \n     \n     \na    \nb    \nc    ", $out);
    }

    public function testRatioItemsAbsorbTheLeftoverSoJustifyHasNoEffect(): void
    {
        // CSS flex-grow: ratio items fill the free space before justify sees it.
        $items = static fn(): array => [FlexItem::new('a'), FlexItem::new('b')];

        self::assertSame(
            FlexBox::row(...$items())->withAlign(Align::Start)->render(6, 1),
            FlexBox::row(...$items())->withAlign(Align::Start)->withJustify(Justify::SpaceBetween)->render(6, 1),
        );
    }

    public function testWithJustifyReturnsANewInstanceAndKeepsEveryOtherSetting(): void
    {
        $base = FlexBox::row(...self::abc())->withWrap(Wrap::Wrap)->withGap(1);
        $changed = $base->withJustify(Justify::SpaceEvenly);

        self::assertNotSame($base, $changed);
        self::assertSame(Justify::Start, $base->justify);
        self::assertSame(Justify::SpaceEvenly, $changed->justify);
        self::assertSame(Wrap::Wrap, $changed->wrap);
        self::assertSame(1, $changed->gap);
        self::assertSame(Direction::Row, $changed->direction);
    }
}

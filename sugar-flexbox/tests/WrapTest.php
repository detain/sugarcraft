<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flexbox\Flex\{Align, Direction, FlexBox, FlexItem, Justify, Wrap};

/**
 * Pins {@see Wrap}: greedy line breaking for row-direction boxes. Replaces the
 * sugar-dashboard FlexWrap surface, whose source tests stay in place until the
 * P3-12 lane removes them; the dashboard composes wrapped rows the same way (a
 * line's full height, not one flat string per child).
 */
final class WrapTest extends TestCase
{
    /** Four two-cell items: three fit a width of six, the fourth wraps. */
    private static function four(): array
    {
        return [
            FlexItem::new('a')->withBasis(2),
            FlexItem::new('b')->withBasis(2),
            FlexItem::new('c')->withBasis(2),
            FlexItem::new('d')->withBasis(2),
        ];
    }

    public function testNoWrapIsTheDefaultAndClipsTheOverflow(): void
    {
        $box = FlexBox::row(...self::four());

        self::assertSame(Wrap::NoWrap, $box->wrap);
        self::assertSame('a b c ', $box->render(6, 1));
    }

    public function testWrapBreaksItemsOntoAFollowingLine(): void
    {
        $out = FlexBox::row(...self::four())
            ->withWrap(Wrap::Wrap)
            ->withAlign(Align::Start)
            ->render(6, 3);

        self::assertSame("a b c \nd     ", $out);
    }

    public function testWrappedLinesArePaddedToTheFullWidth(): void
    {
        $out = FlexBox::row(...self::four())
            ->withWrap(Wrap::Wrap)
            ->withAlign(Align::Start)
            ->render(6, 3);

        foreach (explode("\n", $out) as $line) {
            self::assertSame(6, \SugarCraft\Core\Util\Width::string($line));
        }
    }

    public function testStretchPadsTheWrappedBoxToTheRequestedHeight(): void
    {
        $out = FlexBox::row(...self::four())
            ->withWrap(Wrap::Wrap)
            ->render(6, 3);

        self::assertSame("a b c \nd     \n      ", $out);
    }

    public function testWrapUsesNaturalContentWidthWhenNoBasisIsSet(): void
    {
        // Each 'hello' measures 5 cells: two fit a width of 12, the third wraps.
        $out = FlexBox::row(
            FlexItem::new('hello'),
            FlexItem::new('hello'),
            FlexItem::new('hello'),
        )->withWrap(Wrap::Wrap)->withAlign(Align::Start)->render(12, 2);

        self::assertSame("hello hello \nhello       ", $out);
    }

    public function testAnOversizedBasisTakesItsOwnLineAtTheContainerWidth(): void
    {
        $out = FlexBox::row(
            FlexItem::new('a')->withBasis(9),
            FlexItem::new('b')->withBasis(2),
        )->withWrap(Wrap::Wrap)->withAlign(Align::Start)->render(6, 2);

        self::assertSame("a     \nb     ", $out);
    }

    public function testWrappedLinesAreAsTallAsTheirTallestCell(): void
    {
        $out = FlexBox::row(
            FlexItem::new("AA\nBB")->withBasis(2),
            FlexItem::new("CC\nDD")->withBasis(2),
            FlexItem::new("EE\nFF")->withBasis(2),
        )->withWrap(Wrap::Wrap)->withAlign(Align::Start)->render(4, 6);

        self::assertSame("AACC\nBBDD\nEE  \nFF  ", $out);
    }

    public function testTheRenderedHeightClampsTheWrappedLines(): void
    {
        $out = FlexBox::row(...self::four())
            ->withWrap(Wrap::Wrap)
            ->withAlign(Align::Start)
            ->render(6, 1);

        self::assertSame('a b c ', $out);
    }

    public function testTheConfiguredGapSpacesItemsOnlyWithinALine(): void
    {
        $out = FlexBox::row(
            FlexItem::new('a')->withBasis(2),
            FlexItem::new('b')->withBasis(2),
            FlexItem::new('c')->withBasis(2),
        )->withGap(1)->withWrap(Wrap::Wrap)->withAlign(Align::Start)->render(6, 3);

        // a and b share a line with one gap cell; c starts the next line with no row gap.
        self::assertSame("a  b  \nc     ", $out);
    }

    public function testJustifyAppliesToEachWrappedLineSeparately(): void
    {
        $five = [
            FlexItem::new('a')->withBasis(2),
            FlexItem::new('b')->withBasis(2),
            FlexItem::new('c')->withBasis(2),
            FlexItem::new('d')->withBasis(2),
            FlexItem::new('e')->withBasis(2),
        ];

        $out = FlexBox::row(...$five)
            ->withWrap(Wrap::Wrap)
            ->withJustify(Justify::SpaceBetween)
            ->withAlign(Align::Start)
            ->render(7, 2);

        // Line one leaves one cell over (a b c, 6 cells); line two leaves three (d e).
        // Line one's odd cell goes to the later gap, as Center's odd cell trails.
        self::assertSame("a b  c \nd    e ", $out);
    }

    public function testRatioZeroItemsWrapAtTheirOneCellFloor(): void
    {
        // Each ratio-0 item is drawn at 1 cell, so the break decision must use
        // 1 cell too: all three share one line at width 6 and break at width 2.
        $items = static fn(): array => [
            FlexItem::new('hello')->withRatio(0),
            FlexItem::new('world')->withRatio(0),
            FlexItem::new('x')->withRatio(0),
        ];

        $wide = FlexBox::row(...$items())
            ->withWrap(Wrap::Wrap)
            ->withAlign(Align::Start)
            ->render(6, 3);
        $narrow = FlexBox::row(...$items())
            ->withWrap(Wrap::Wrap)
            ->withAlign(Align::Start)
            ->render(2, 3);

        self::assertSame('hwx   ', $wide);
        self::assertSame("hw\nx ", $narrow);
    }

    public function testColumnBoxesIgnoreWrap(): void
    {
        $items = static fn(): array => [
            FlexItem::new('a')->withBasis(1),
            FlexItem::new('b')->withBasis(1),
            FlexItem::new('c')->withBasis(1),
        ];

        $plain = FlexBox::column(...$items())->render(5, 3);
        $wrapped = FlexBox::column(...$items())->withWrap(Wrap::Wrap)->render(5, 3);

        self::assertSame($plain, $wrapped);
        self::assertSame(Direction::Column, FlexBox::column(...$items())->direction);
    }

    public function testWithWrapReturnsANewInstance(): void
    {
        $base = FlexBox::row(...self::four());
        $changed = $base->withWrap(Wrap::Wrap);

        self::assertNotSame($base, $changed);
        self::assertSame(Wrap::NoWrap, $base->wrap);
        self::assertSame(Wrap::Wrap, $changed->wrap);
    }
}

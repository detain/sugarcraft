<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;

/**
 * Frame-size accessors: the cells a style adds around its content. Each
 * accessor must agree with what render() actually emits, so an app can
 * budget a content box as outer size minus frame.
 */
final class FrameSizeTest extends TestCase
{
    public function testABareStyleHasNoFrame(): void
    {
        $style = Style::new();

        self::assertSame(0, $style->horizontalPadding());
        self::assertSame(0, $style->verticalPadding());
        self::assertSame(0, $style->horizontalMargin());
        self::assertSame(0, $style->verticalMargin());
        self::assertSame(0, $style->horizontalBorderSize());
        self::assertSame(0, $style->verticalBorderSize());
        self::assertSame(0, $style->horizontalFrameSize());
        self::assertSame(0, $style->verticalFrameSize());
    }

    public function testPaddingSumsEachAxis(): void
    {
        // top, right, bottom, left
        $style = Style::new()->padding(1, 2, 3, 4);

        self::assertSame(6, $style->horizontalPadding(), 'right 2 + left 4');
        self::assertSame(4, $style->verticalPadding(), 'top 1 + bottom 3');
        self::assertSame(6, $style->horizontalFrameSize());
        self::assertSame(4, $style->verticalFrameSize());
    }

    public function testMarginSumsEachAxis(): void
    {
        $style = Style::new()->margin(1, 2, 3, 4);

        self::assertSame(6, $style->horizontalMargin());
        self::assertSame(4, $style->verticalMargin());
        self::assertSame(6, $style->horizontalFrameSize());
        self::assertSame(4, $style->verticalFrameSize());
    }

    public function testBorderCountsEachEnabledSide(): void
    {
        $all = Style::new()->border(Border::normal());
        self::assertSame(2, $all->horizontalBorderSize(), 'left and right');
        self::assertSame(2, $all->verticalBorderSize(), 'top and bottom');

        // border(Border, top, right, bottom, left)
        $topBottom = Style::new()->border(Border::normal(), true, false, true, false);
        self::assertSame(0, $topBottom->horizontalBorderSize());
        self::assertSame(2, $topBottom->verticalBorderSize());

        $leftOnly = Style::new()->border(Border::normal(), false, false, false, true);
        self::assertSame(1, $leftOnly->horizontalBorderSize());
        self::assertSame(0, $leftOnly->verticalBorderSize());
    }

    public function testBorderSidesWithoutABorderAddNothing(): void
    {
        // borderTop() records the side, but render() draws nothing without a border().
        $style = Style::new()->borderTop(true)->borderLeft(true);

        self::assertSame(0, $style->horizontalBorderSize());
        self::assertSame(0, $style->verticalBorderSize());
    }

    public function testInlineZeroesVerticalPaddingAndMargin(): void
    {
        $style = Style::new()->padding(1, 2, 3, 4)->margin(1, 2, 3, 4)->inline();

        self::assertSame(0, $style->verticalPadding());
        self::assertSame(0, $style->verticalMargin());
        self::assertSame(6, $style->horizontalPadding(), 'inline keeps the horizontal sides');
        self::assertSame(6, $style->horizontalMargin());
    }

    public function testInlineKeepsTheVerticalBorder(): void
    {
        $style = Style::new()->border(Border::normal())->padding(1)->margin(1)->inline();

        self::assertSame(2, $style->verticalBorderSize());
        self::assertSame(2, $style->verticalFrameSize());
    }

    public function testTheFrameTotalsAreTheSumOfTheirParts(): void
    {
        $style = Style::new()->border(Border::rounded())->padding(1, 2, 0, 3)->margin(2, 1, 0, 1);

        self::assertSame(
            $style->horizontalPadding() + $style->horizontalBorderSize() + $style->horizontalMargin(),
            $style->horizontalFrameSize(),
        );
        self::assertSame(
            $style->verticalPadding() + $style->verticalBorderSize() + $style->verticalMargin(),
            $style->verticalFrameSize(),
        );
    }

    /**
     * @return array<string, array{Style}>
     */
    public static function renderedStyles(): array
    {
        return [
            'bare' => [Style::new()],
            'padded' => [Style::new()->padding(1, 2)],
            'bordered' => [Style::new()->border(Border::rounded())],
            'margined' => [Style::new()->margin(1, 2, 3, 4)],
            'partial border' => [Style::new()->border(Border::normal(), true, false, true, false)->padding(1, 2, 0, 3)],
            'everything' => [Style::new()->border(Border::normal())->padding(1, 2, 0, 3)->margin(2, 1, 0, 1)],
            'inline' => [Style::new()->padding(1, 1)->margin(1)->inline()],
        ];
    }

    /**
     * With a width() and a single content line, render() adds exactly the
     * frame: width + horizontal frame columns, and one row plus the vertical
     * frame rows.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('renderedStyles')]
    public function testFrameMatchesWhatRenderEmits(Style $frame): void
    {
        $style = $frame->width(6);
        $lines = explode("\n", $style->render('x'));

        self::assertSame(6 + $style->horizontalFrameSize(), Width::string($lines[0]), 'rendered width');
        self::assertSame(1 + $style->verticalFrameSize(), count($lines), 'rendered rows');
    }
}

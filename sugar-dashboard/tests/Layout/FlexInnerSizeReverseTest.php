<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Layout\FlexDirection;
use SugarCraft\Dashboard\Layout\FlexLayout;

/**
 * Regression pins for finding 038 (candy-dashboard-038): the reverse flex
 * directions were declared in the enum but had no render arms, so any use of
 * RowReverse/ColumnReverse died with an UnhandledMatchError; and innerSize()
 * on an empty unsized layout fed max() an empty array (ValueError).
 */
final class FlexInnerSizeReverseTest extends TestCase
{
    /**
     * A plain text item: renders exactly what it holds, measures naturally.
     */
    private static function text(string $content): Item
    {
        return new class ($content) implements Item {
            public function __construct(private readonly string $content)
            {
            }

            public function render(): string
            {
                return $this->content;
            }
        };
    }

    public function testEmptyUnsizedInnerSizeIsTheZeroPair(): void
    {
        self::assertSame([0, 0], FlexLayout::row([])->innerSize());
        self::assertSame([0, 0], FlexLayout::column([])->innerSize());
    }

    public function testRowReverseRendersTheMirroredBytes(): void
    {
        $a = self::text('AAAA');
        $b = self::text('BB');

        $reversed = FlexLayout::row([$a, $b])
            ->withGap(1)
            ->withDirection(FlexDirection::RowReverse)
            ->setSize(9, 1)
            ->render();

        $mirror = FlexLayout::row([$b, $a])
            ->withGap(1)
            ->setSize(9, 1)
            ->render();

        self::assertSame($mirror, $reversed, 'row-reverse must lay the same list backwards');
    }

    public function testColumnReverseRendersTheMirroredBytes(): void
    {
        $a = self::text('AAA');
        $b = self::text('BB');

        $reversed = FlexLayout::column([$a, $b])
            ->withDirection(FlexDirection::ColumnReverse)
            ->setSize(3, 2)
            ->render();

        $mirror = FlexLayout::column([$b, $a])
            ->setSize(3, 2)
            ->render();

        self::assertSame($mirror, $reversed, 'column-reverse must stack the same list upside-down');
    }

    public function testReverseDirectionsReachEveryRenderPathWithoutThrowing(): void
    {
        $items = [self::text('AA'), self::text('BB')];

        // Natural (unsized) path: the old match died before producing a byte.
        $naturalRow = FlexLayout::row($items)->withDirection(FlexDirection::RowReverse)->render();
        $naturalColumn = FlexLayout::column($items)->withDirection(FlexDirection::ColumnReverse)->render();
        self::assertNotSame('', $naturalRow);
        self::assertNotSame('', $naturalColumn);

        // Sized path plus the axis extents.
        $row = FlexLayout::row($items)->withDirection(FlexDirection::RowReverse)->setSize(8, 1);
        self::assertCount(1, explode("\n", $row->render()));

        // Unsized axis extents pair through the reverse arms onto the forward
        // extents: same multiset of children, same natural box.
        self::assertSame(
            [4, 1],
            FlexLayout::row($items)->withDirection(FlexDirection::RowReverse)->innerSize()
        );
        self::assertSame(
            [2, 2],
            FlexLayout::column($items)->withDirection(FlexDirection::ColumnReverse)->innerSize()
        );
    }
}

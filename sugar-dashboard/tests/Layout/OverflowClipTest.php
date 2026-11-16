<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Layout\FlexLayout;
use SugarCraft\Dashboard\Layout\RatioGrid\GridItem;
use SugarCraft\Dashboard\Layout\RatioGrid\NewColRow;
use SugarCraft\Dashboard\Layout\RatioGrid\RatioGrid;

/**
 * Regression pins for finding 031 (candy-dashboard-031): render exits must
 * clip to their allocation — pad-OR-truncate, never pad-only — so neither a
 * wide cell nor an overflowing NoWrap row spills past the budget its parent
 * measured, and a natural join honours a declared zero gap.
 */
final class OverflowClipTest extends TestCase
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

    public function testRatioGridFixedColumnsSpillIsClippedToFrame(): void
    {
        $grid = new RatioGrid(
            [[GridItem::full(self::text(str_repeat('X', 30))), GridItem::full(self::text(str_repeat('Y', 30)))]],
            [NewColRow::fixed(30), NewColRow::fixed(30)],
            [NewColRow::fixed(1)],
        );

        $rendered = $grid->setSize(50, 1)->render();

        self::assertSame(
            [str_repeat('X', 30) . str_repeat('Y', 20)],
            explode("\n", $rendered),
            'columns that sum past the frame must clip at the allocation, not spill 60 cells'
        );
    }

    public function testRatioGridWideCellContentIsCutToItsColumn(): void
    {
        $grid = new RatioGrid(
            [[GridItem::full(self::text(str_repeat('A', 20))), GridItem::full(self::text('Z'))]],
            [NewColRow::fixed(10), NewColRow::fixed(20)],
            [NewColRow::fixed(1)],
        );

        $rendered = $grid->setSize(30, 1)->render();

        self::assertSame(
            [str_repeat('A', 10) . 'Z' . str_repeat(' ', 19)],
            explode("\n", $rendered),
            'a cell wider than its column is truncated to the column, keeping the next cell aligned'
        );
    }

    public function testFlexNoWrapSpillIsClippedToAllocation(): void
    {
        $layout = FlexLayout::row([
            self::text(str_repeat('A', 10)),
            self::text(str_repeat('B', 10)),
        ])->setSize(12, 1);

        self::assertSame(
            ['AAAAAAAAAABB'],
            explode("\n", $layout->render()),
            'a NoWrap row wider than its allocation must end at the allocation, not run 20 cells'
        );
    }

    public function testFlexNaturalJoinHonoursDeclaredZeroGap(): void
    {
        $layout = FlexLayout::row([self::text('AA'), self::text('BB')]);

        self::assertSame(
            'AABB',
            $layout->render(),
            'a declared zero gap must not be padded into a phantom one-cell separator'
        );
    }
}

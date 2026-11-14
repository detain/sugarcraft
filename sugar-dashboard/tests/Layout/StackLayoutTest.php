<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Layout\LayoutDirection;
use SugarCraft\Dashboard\Components\Card\Text;
use SugarCraft\Dashboard\Layout\StackLayout;

/**
 * D-157 (finding 219): StackLayout had no direct suite; the two legs this
 * row fixed are the ones pinned here — the natural path's spacing must
 * agree with the vertical path's, and withSpacing clamps like its siblings.
 */
final class StackLayoutTest extends TestCase
{
    public function testNaturalSpacingMatchesVerticalSpacing(): void
    {
        $layout = new StackLayout([new Text("alpha"), new Text("beta")], 2, LayoutDirection::Vertical);

        $natural = explode("\n", $layout->render());
        $vertical = explode("\n", $layout->setSize(12, 6)->render());

        self::assertCount(4, $natural);          // 2 content rows + 2 blank rows
        self::assertSame('', trim($natural[1]));
        self::assertSame('', trim($natural[2]));
        self::assertStringContainsString('beta', $natural[3]);
        self::assertSame(trim($vertical[0]), $natural[0]);
        // Both paths must agree on where the second item lands.
        self::assertStringContainsString('beta', $vertical[3]);
    }

    public function testZeroSpacingKeepsItemsAdjacent(): void
    {
        $rendered = explode("\n", (new StackLayout([new Text("a"), new Text("b")], 0, LayoutDirection::Vertical))->render());

        self::assertCount(2, $rendered);
        self::assertSame('a', $rendered[0]);
        self::assertSame('b', $rendered[1]);
    }

    public function testNegativeSpacingIsClampedLikeTheSiblings(): void
    {
        $clamped = (new StackLayout([new Text("a"), new Text("b")], 0, LayoutDirection::Vertical))->withSpacing(-3);

        $zero = new StackLayout([new Text("a"), new Text("b")], 0, LayoutDirection::Vertical);

        self::assertSame($zero->render(), $clamped->render());
    }
}

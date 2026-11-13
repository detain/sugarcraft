<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tui\SplitDirection;
use SugarCraft\Code\Tui\SplitLayout;
use SugarCraft\Core\Util\Width;

/**
 * S-U04: the split's row composition rides sugar-style Layout's divider-aware
 * join; the rational width algebra stays in SplitLayout. Pinned here: the
 * divider column lands on the pane boundary for narrow, wide and styled
 * content, and the horizontal rule spans the full available width.
 */
final class SplitLayoutBlockAlgebraTest extends TestCase
{
    public function testTheDividerColumnHoldsForAccentedAndWideLeftContent(): void
    {
        $out = SplitLayout::vertical("éé\n日本", "right")->render(21, 5);
        $divider = SplitDirection::Vertical->divider();
        [$leftWidth] = SplitLayout::vertical('', '')->paneSizes(21);

        foreach (explode("\n", $out) as $row) {
            self::assertSame($leftWidth, Width::string(explode($divider, $row)[0]), $row);
        }
        self::assertStringContainsString($divider, $out);
    }

    public function testAStyledLeftRowIsNotCutMidEscape(): void
    {
        $styled = "\x1b[31m" . str_repeat('x', 40) . "\x1b[0m";
        $out = SplitLayout::vertical($styled, 'r')->render(21, 3);

        self::assertStringContainsString('…', $out);
        self::assertStringNotContainsString("\x1b[3\n", $out);
    }

    public function testTheHorizontalRuleSpansTheAvailableWidth(): void
    {
        $out = SplitLayout::horizontal('top', 'bottom')->render(12, 6);

        self::assertContains(str_repeat(SplitDirection::Horizontal->divider(), 12), explode("\n", $out));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Dash\Grid\TabsVertical;
use SugarCraft\Dash\Grid\Text;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class TabsVerticalTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testTabsVerticalImplementsSizer(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
        ]);
        $this->assertInstanceOf(Sizer::class, $tabs);
    }

    public function testTabsVerticalImplementsItem(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
        ]);
        $this->assertInstanceOf(Item::class, $tabs);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
        ]);
        $rendered = $tabs->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsTabLabel(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Home', 'content' => Text::new('Home Content')],
        ]);
        $rendered = $tabs->render();

        $this->assertStringContainsString('Home', $rendered);
    }

    public function testRenderContainsContent(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Hello World')],
        ]);
        $rendered = $tabs->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderEmptyTabsReturnsEmpty(): void
    {
        $tabs = TabsVertical::new([]);
        $rendered = $tabs->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Multiple tabs
    // ═══════════════════════════════════════════════════════════════

    public function testRenderMultipleTabs(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
            ['label' => 'Tab2', 'content' => Text::new('Content 2')],
            ['label' => 'Tab3', 'content' => Text::new('Content 3')],
        ]);
        $rendered = $tabs->render();

        $this->assertStringContainsString('Tab1', $rendered);
        $this->assertStringContainsString('Tab2', $rendered);
        $this->assertStringContainsString('Tab3', $rendered);
    }

    public function testSelectedTabContentRendered(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('First Content')],
            ['label' => 'Tab2', 'content' => Text::new('Second Content')],
        ])->withSelectedIndex(1);

        $rendered = $tabs->render();

        $this->assertStringContainsString('Second Content', $rendered);
        $this->assertStringNotContainsString('First Content', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Tab index bounds
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeIndexClampedToZero(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
            ['label' => 'Tab2', 'content' => Text::new('Content 2')],
        ])->withSelectedIndex(-5);

        $this->assertStringContainsString('Tab1', $tabs->render());
    }

    public function testOversizedIndexClampedToLast(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
            ['label' => 'Tab2', 'content' => Text::new('Content 2')],
        ])->withSelectedIndex(100);

        $rendered = $tabs->render();
        $this->assertStringContainsString('Tab2', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Vertical layout structure
    // ═══════════════════════════════════════════════════════════════

    public function testVerticalLayoutHasNewlines(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
            ['label' => 'Tab2', 'content' => Text::new('Content')],
        ]);
        $rendered = $tabs->render();

        // Vertical tabs should have newlines between labels
        $this->assertStringContainsString("\n", $rendered);
    }

    public function testActiveTabMarker(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
            ['label' => 'Tab2', 'content' => Text::new('Content 2')],
        ])->withSelectedIndex(0);

        $rendered = $tabs->render();
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered ?? '');

        // Active tab should have > marker
        $this->assertStringContainsString('>', $stripped);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testActiveColorAddsAnsiCodes(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ])->withActiveColor(Color::ansi(9));

        $rendered = $tabs->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testInactiveColorAddsAnsiCodes(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
            ['label' => 'Tab2', 'content' => Text::new('Content')],
        ])->withInactiveColor(Color::ansi(8));

        $rendered = $tabs->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ])->withActiveColor(Color::ansi(9));

        $rendered = $tabs->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Separator customization
    // ═══════════════════════════════════════════════════════════════

    public function testCustomSeparator(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
            ['label' => 'Tab2', 'content' => Text::new('Content')],
        ])->withSeparator(':');

        $rendered = $tabs->render();

        $this->assertStringContainsString(':', $rendered);
        $this->assertStringNotContainsString('│', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Label width customization
    // ═══════════════════════════════════════════════════════════════

    public function testCustomLabelWidth(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ])->withLabelWidth(20);

        $rendered = $tabs->render();

        // Should render without error and contain the label
        $this->assertStringContainsString('Tab1', $rendered);
    }

    public function testLabelWidthMinimum(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ])->withLabelWidth(2); // Less than minimum

        $rendered = $tabs->render();

        // Should still render with minimum width (4)
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ]);
        $resized = $original->setSize(50, 10);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithSelectedIndexReturnsNewInstance(): void
    {
        $original = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
            ['label' => 'Tab2', 'content' => Text::new('Content 2')],
        ]);
        $updated = $original->withSelectedIndex(1);

        $this->assertNotSame($original, $updated);
    }

    public function testWithActiveColorReturnsNewInstance(): void
    {
        $original = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ]);
        $updated = $original->withActiveColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithInactiveColorReturnsNewInstance(): void
    {
        $original = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ]);
        $updated = $original->withInactiveColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testWithTabsReturnsNewInstance(): void
    {
        $original = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ]);
        $updated = $original->withTabs([
            ['label' => 'New', 'content' => Text::new('New Content')],
        ]);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithSelectedIndex(): void
    {
        $original = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('First')],
            ['label' => 'Tab2', 'content' => Text::new('Second')],
        ]);
        $original->withSelectedIndex(1);
        $rendered = $original->render();

        // Original should still show Tab1 as active
        $this->assertStringContainsString('First', $rendered);
        $this->assertStringNotContainsString('Second', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ]);
        [$w, $h] = $tabs->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithMultipleTabs(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
            ['label' => 'Tab2', 'content' => Text::new('Content 2')],
            ['label' => 'Tab3', 'content' => Text::new('Content 3')],
        ]);
        [$w, $h] = $tabs->getInnerSize();

        // Height should be at least 3 (number of tabs)
        $this->assertGreaterThanOrEqual(3, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testSingleTabNoSeparatorNeeded(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Only', 'content' => Text::new('Content')],
        ]);
        $rendered = $tabs->render();

        $this->assertNotSame('', $rendered);
    }

    public function testLongLabelTruncation(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'This Is A Very Long Tab Label', 'content' => Text::new('Content')],
        ]);
        $rendered = $tabs->render();

        // Should render without error and contain truncated label or ".."
        $this->assertNotSame('', $rendered);
    }

    public function testTabSwitchingShowsCorrectContent(): void
    {
        $tabs = TabsVertical::new([
            ['label' => 'Tab1', 'content' => Text::new('ONE')],
            ['label' => 'Tab2', 'content' => Text::new('TWO')],
            ['label' => 'Tab3', 'content' => Text::new('THREE')],
        ]);

        // Tab 0
        $this->assertStringContainsString('ONE', $tabs->render());

        // Tab 1
        $tabs1 = $tabs->withSelectedIndex(1);
        $this->assertStringContainsString('TWO', $tabs1->render());

        // Tab 2
        $tabs2 = $tabs->withSelectedIndex(2);
        $this->assertStringContainsString('THREE', $tabs2->render());
    }
}

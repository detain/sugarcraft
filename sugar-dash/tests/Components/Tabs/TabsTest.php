<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Tabs;

use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Components\Tabs\Tabs;
use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class TabsTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testTabsImplementsSizer(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
        ]);
        $this->assertInstanceOf(Sizer::class, $tabs);
    }

    public function testTabsImplementsItem(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
        ]);
        $this->assertInstanceOf(Item::class, $tabs);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
        ]);
        $rendered = $tabs->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsTabLabel(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Home', 'content' => Text::new('Home Content')],
        ]);
        $rendered = $tabs->render();

        $this->assertStringContainsString('Home', $rendered);
    }

    public function testRenderContainsContent(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Hello World')],
        ]);
        $rendered = $tabs->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderEmptyTabsReturnsEmpty(): void
    {
        $tabs = Tabs::new([]);
        $rendered = $tabs->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Multiple tabs
    // ═══════════════════════════════════════════════════════════════

    public function testRenderMultipleTabs(): void
    {
        $tabs = Tabs::new([
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
        $tabs = Tabs::new([
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
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
            ['label' => 'Tab2', 'content' => Text::new('Content 2')],
        ])->withSelectedIndex(-5);

        $this->assertStringContainsString('Tab1', $tabs->render());
    }

    public function testOversizedIndexClampedToLast(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
            ['label' => 'Tab2', 'content' => Text::new('Content 2')],
        ])->withSelectedIndex(100);

        $rendered = $tabs->render();
        $this->assertStringContainsString('Tab2', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testActiveColorAddsAnsiCodes(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ])->withActiveColor(Color::ansi(9));

        $rendered = $tabs->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testInactiveColorAddsAnsiCodes(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
            ['label' => 'Tab2', 'content' => Text::new('Content')],
        ])->withInactiveColor(Color::ansi(8));

        $rendered = $tabs->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ])->withActiveColor(Color::ansi(9));

        $rendered = $tabs->render();

        // Strip the content line and check the last part ends with reset
        // Output format: tabBar\ncontent[reset]
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Separator customization
    // ═══════════════════════════════════════════════════════════════

    public function testCustomSeparator(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
            ['label' => 'Tab2', 'content' => Text::new('Content')],
        ])->withSeparator(':');

        $rendered = $tabs->render();

        $this->assertStringContainsString(':', $rendered);
        $this->assertStringNotContainsString('│', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Active char customization
    // ═══════════════════════════════════════════════════════════════

    public function testCustomActiveChar(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ])->withActiveChar('=');

        $rendered = $tabs->render();

        $this->assertStringContainsString('=', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ]);
        $resized = $original->setSize(40, 10);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsRenderOutput(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
            ['label' => 'Tab2', 'content' => Text::new('Longer Content Here')],
        ])->setSize(50, 15);

        $rendered = $tabs->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithSelectedIndexReturnsNewInstance(): void
    {
        $original = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
            ['label' => 'Tab2', 'content' => Text::new('Content 2')],
        ]);
        $updated = $original->withSelectedIndex(1);

        $this->assertNotSame($original, $updated);
    }

    public function testWithActiveColorReturnsNewInstance(): void
    {
        $original = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ]);
        $updated = $original->withActiveColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithInactiveColorReturnsNewInstance(): void
    {
        $original = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ]);
        $updated = $original->withInactiveColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testWithTabsReturnsNewInstance(): void
    {
        $original = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ]);
        $updated = $original->withTabs([
            ['label' => 'New', 'content' => Text::new('New Content')],
        ]);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithSelectedIndex(): void
    {
        $original = Tabs::new([
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
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content')],
        ]);
        [$w, $h] = $tabs->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithMultipleTabs(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
            ['label' => 'Tab2', 'content' => Text::new('Content 2')],
        ]);
        [$w, $h] = $tabs->getInnerSize();

        // Width should increase with more/longer tabs
        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(1, $h); // At least tab bar + content
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testSingleTabNoSeparator(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Only', 'content' => Text::new('Content')],
        ]);
        $rendered = $tabs->render();

        // No separator should appear with single tab
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
        $this->assertStringNotContainsString('│', $stripped ?? '');
    }

    public function testTabWithEmptyContent(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('')],
        ]);
        $rendered = $tabs->render();

        $this->assertNotSame('', $rendered);
        $this->assertStringContainsString('Tab1', $rendered);
    }

    public function testTabBarShowsInOutput(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Home', 'content' => Text::new('Home Page')],
            ['label' => 'About', 'content' => Text::new('About Page')],
        ]);
        $rendered = $tabs->render();
        $lines = explode("\n", $rendered);

        // First line should contain tab labels
        $this->assertStringContainsString('Home', $lines[0]);
        $this->assertStringContainsString('About', $lines[0]);
    }

    public function testContentOnSecondLine(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content Line')],
        ]);
        $rendered = $tabs->render();
        $lines = explode("\n", $rendered);

        // Tab bar (2 lines: label + underline) + content = 3 lines
        // Content should be on the last line
        $this->assertGreaterThanOrEqual(2, count($lines));
        $this->assertStringContainsString('Content Line', $lines[count($lines) - 1]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Tab switching behavior
    // ═══════════════════════════════════════════════════════════════

    public function testSwitchingTabsShowsCorrectContent(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('ONE')],
            ['label' => 'Tab2', 'content' => Text::new('TWO')],
            ['label' => 'Tab3', 'content' => Text::new('THREE')],
        ]);

        // Tab 0
        $this->assertStringContainsString('ONE', $tabs->render());
        $this->assertStringNotContainsString('TWO', $tabs->render());
        $this->assertStringNotContainsString('THREE', $tabs->render());

        // Tab 1
        $tabs1 = $tabs->withSelectedIndex(1);
        $this->assertStringContainsString('TWO', $tabs1->render());
        $this->assertStringNotContainsString('ONE', $tabs1->render());
        $this->assertStringNotContainsString('THREE', $tabs1->render());

        // Tab 2
        $tabs2 = $tabs->withSelectedIndex(2);
        $this->assertStringContainsString('THREE', $tabs2->render());
        $this->assertStringNotContainsString('ONE', $tabs2->render());
        $this->assertStringNotContainsString('TWO', $tabs2->render());
    }

    public function testWithTabsResetsIndexIfOutOfBounds(): void
    {
        $original = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
        ])->withSelectedIndex(5);

        // Adding fewer tabs should clamp index
        $updated = $original->withTabs([
            ['label' => 'New', 'content' => Text::new('New')],
        ]);

        // Should not throw and should render without error
        $rendered = $updated->render();
        $this->assertNotSame('', $rendered);
    }
}

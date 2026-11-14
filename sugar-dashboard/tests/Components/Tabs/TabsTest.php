<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tabs;

use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Dashboard\Components\Tabs\Tabs;
use SugarCraft\Dashboard\Components\Card\Text;
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
        [$w, $h] = $tabs->innerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithMultipleTabs(): void
    {
        $tabs = Tabs::new([
            ['label' => 'Tab1', 'content' => Text::new('Content 1')],
            ['label' => 'Tab2', 'content' => Text::new('Content 2')],
        ]);
        [$w, $h] = $tabs->innerSize();

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

    // ═══════════════════════════════════════════════════════════════
    // Underline offset (finding sugar-dashboard-052)
    // ═══════════════════════════════════════════════════════════════

    public function testUnderlineSitsAtActiveTabStartColumn(): void
    {
        // Colorless construction: raw byte offsets, no SGR arithmetic.
        // Cells: Home=4+2, sep=1, About=5+2, sep=1, Contact=7+2.
        // Active tab 2 therefore starts at column 6+1+7+1 = 15.
        $tabs = new Tabs([
            ['label' => 'Home', 'content' => Text::new('A')],
            ['label' => 'About', 'content' => Text::new('B')],
            ['label' => 'Contact', 'content' => Text::new('C')],
        ], selectedIndex: 2);

        $lines = explode("\n", $tabs->render());
        $underline = $lines[1];

        $leading = strlen($underline) - strlen(ltrim($underline, ' '));
        $this->assertSame(15, $leading, 'dash must start at the active cell, not at column 2');
        // Dash covers the active cell exactly: [Contact] = 7 + 2 columns.
        $this->assertSame(9, substr_count($underline, '─'));

        // Every layout must agree: active tab 0 has offset 0 and a
        // [Home] cell of 4+2 dashes.
        $first = new Tabs([
            ['label' => 'Home', 'content' => Text::new('A')],
            ['label' => 'About', 'content' => Text::new('B')],
        ], selectedIndex: 0);
        $firstLine = explode("\n", $first->render())[1];
        $this->assertSame(0, strlen($firstLine) - strlen(ltrim($firstLine, ' ')));
        $this->assertSame(6, substr_count($firstLine, '─'));

        // Middle tab: Home cell (6) + separator (1) = column 7.
        $middle = new Tabs([
            ['label' => 'Home', 'content' => Text::new('A')],
            ['label' => 'Settings', 'content' => Text::new('B')],
            ['label' => 'Contact', 'content' => Text::new('C')],
        ], selectedIndex: 1);
        $middleLine = explode("\n", $middle->render())[1];
        $this->assertSame(7, strlen($middleLine) - strlen(ltrim($middleLine, ' ')));
        $this->assertSame(10, substr_count($middleLine, '─'));
    }

    public function testUnderlineTracksTruncatedOverflowLayout(): void
    {
        // Ten long tabs inside an 80-cell allocation: labels take an equal
        // cell budget and nothing may escape the frame (finding 052: the
        // old code overflowed the bar and the dash ignored the layout).
        $tabs = [];
        for ($i = 1; $i <= 10; $i++) {
            $tabs[] = ['label' => "Overview-$i", 'content' => Text::new("content $i")];
        }

        $sized = (new Tabs($tabs, selectedIndex: 7))->setSize(80, 0);
        $lines = explode("\n", $sized->render());

        // Natural width would be 9*10+11 + 10*2 + 9 = 130 > 80: budget
        // kicks in (label budget = intdiv(80-9, 10) - 2 = 5 cells).
        $this->assertLessThanOrEqual(80, \SugarCraft\Core\Util\Width::string($lines[0]));
        $underline = $lines[1];
        $this->assertLessThanOrEqual(80, \SugarCraft\Core\Util\Width::string($underline));

        // Every label got the same 5-cell budget -> cell 5+2=7, separator 1.
        // Active tab 7 starts at 7*(7+1) = 56.
        $leading = strlen($underline) - strlen(ltrim($underline, ' '));
        $this->assertSame(56, $leading);
        $this->assertSame(7, substr_count($underline, '─'));
    }
}

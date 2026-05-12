<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Navbar;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class NavbarTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testNavbarImplementsSizer(): void
    {
        $navbar = Navbar::new();
        $this->assertInstanceOf(Sizer::class, $navbar);
    }

    public function testNavbarImplementsItem(): void
    {
        $navbar = Navbar::new();
        $this->assertInstanceOf(Item::class, $navbar);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $navbar = Navbar::new();
        $rendered = $navbar->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderWithBrand(): void
    {
        $navbar = Navbar::brand('MyApp');
        $rendered = $navbar->render();

        $this->assertStringContainsString('MyApp', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Items handling
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithItems(): void
    {
        $navbar = Navbar::new([
            ['label' => 'Home', 'isActive' => true],
            ['label' => 'About'],
            ['label' => 'Contact'],
        ]);
        $rendered = $navbar->render();

        $this->assertStringContainsString('Home', $rendered);
        $this->assertStringContainsString('About', $rendered);
        $this->assertStringContainsString('Contact', $rendered);
    }

    public function testRenderWithIcons(): void
    {
        $navbar = Navbar::new([
            ['label' => 'Home', 'icon' => '⌂'],
            ['label' => 'About', 'icon' => 'ℹ'],
        ]);
        $rendered = $navbar->render();

        $this->assertStringContainsString('⌂', $rendered);
        $this->assertStringContainsString('ℹ', $rendered);
    }

    public function testWithItems(): void
    {
        $navbar = Navbar::new()->withItems([
            ['label' => 'New Item'],
        ]);
        $rendered = $navbar->render();

        $this->assertStringContainsString('New Item', $rendered);
    }

    public function testWithBrand(): void
    {
        $navbar = Navbar::new()->withBrand('TestBrand');
        $rendered = $navbar->render();

        $this->assertStringContainsString('TestBrand', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Active item handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithActiveItem(): void
    {
        $navbar = Navbar::new([
            ['label' => 'Home'],
            ['label' => 'About'],
        ])->withActiveItem(0);
        $rendered = $navbar->render();

        $this->assertStringContainsString('Home', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $navbar = Navbar::new()
            ->withBorderColor(Color::ansi(9));
        $rendered = $navbar->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testActiveColorAddsAnsiCodes(): void
    {
        $navbar = Navbar::new([
            ['label' => 'Home', 'isActive' => true],
        ])->withActiveColor(Color::ansi(12));
        $rendered = $navbar->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testInactiveColorAddsAnsiCodes(): void
    {
        $navbar = Navbar::new([
            ['label' => 'Home'],
        ])->withInactiveColor(Color::ansi(8));
        $rendered = $navbar->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBgColorAddsAnsiCodes(): void
    {
        $navbar = Navbar::new()
            ->withBgColor(Color::ansi(9));
        $rendered = $navbar->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Navbar::new();
        $resized = $original->setSize(80, 24);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsOutput(): void
    {
        $navbar = Navbar::brand('TestApp')->setSize(80, 24);
        $rendered = $navbar->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithItemsReturnsNewInstance(): void
    {
        $original = Navbar::new();
        $updated = $original->withItems([['label' => 'Test']]);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithItems(): void
    {
        $original = Navbar::new();
        $original->withItems([['label' => 'Changed']]);
        $rendered = $original->render();

        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $navbar = Navbar::new();
        [$w, $h] = $navbar->getInnerSize();

        $this->assertGreaterThan(0, $w);
        // Default navbar has borderColor set, so height is 2 (content + border line)
        $this->assertSame(2, $h);
    }

    public function testGetInnerSizeWithContent(): void
    {
        $navbar = Navbar::brand('TestApp', [
            ['label' => 'Item1'],
            ['label' => 'Item2'],
        ]);
        [$w, $h] = $navbar->getInnerSize();

        $this->assertGreaterThan(0, $w);
        // Navbar with borderColor set has height 2 (content + border line)
        $this->assertSame(2, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyNavbar(): void
    {
        $navbar = Navbar::new();
        $rendered = $navbar->render();

        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeContent(): void
    {
        $navbar = Navbar::new([
            ['label' => 'ホーム'],
        ]);
        $rendered = $navbar->render();

        $this->assertStringContainsString('ホーム', $rendered);
    }
}
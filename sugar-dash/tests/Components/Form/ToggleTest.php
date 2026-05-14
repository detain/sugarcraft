<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Form;

use SugarCraft\Dash\Components\Form\Toggle;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ToggleTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testToggleImplementsSizer(): void
    {
        $toggle = Toggle::new();
        $this->assertInstanceOf(Sizer::class, $toggle);
    }

    public function testToggleImplementsItem(): void
    {
        $toggle = Toggle::new();
        $this->assertInstanceOf(Item::class, $toggle);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $toggle = Toggle::new();
        $rendered = $toggle->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderOffShowsOffIndicator(): void
    {
        $toggle = Toggle::new(false);
        $rendered = $toggle->render();

        $this->assertStringContainsString('○', $rendered);
    }

    public function testRenderOnShowsOnIndicator(): void
    {
        $toggle = Toggle::new(true);
        $rendered = $toggle->render();

        $this->assertStringContainsString('●', $rendered);
    }

    public function testRenderOffShowsOffLabel(): void
    {
        $toggle = Toggle::new(false);
        $rendered = $toggle->render();

        $this->assertStringContainsString('OFF', $rendered);
    }

    public function testRenderOnShowsOnLabel(): void
    {
        $toggle = Toggle::new(true);
        $rendered = $toggle->render();

        $this->assertStringContainsString('ON', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testOnFactory(): void
    {
        $toggle = Toggle::on();
        $rendered = $toggle->render();

        $this->assertStringContainsString('●', $rendered);
    }

    public function testOffFactory(): void
    {
        $toggle = Toggle::off();
        $rendered = $toggle->render();

        $this->assertStringContainsString('○', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom labels
    // ═══════════════════════════════════════════════════════════════

    public function testCustomLabels(): void
    {
        $toggle = Toggle::new(true)->withLabels('YES', 'NO');
        $rendered = $toggle->render();

        $this->assertStringContainsString('YES', $rendered);
        $this->assertStringNotContainsString('ON', $rendered);
    }

    public function testCustomLabelsOff(): void
    {
        $toggle = Toggle::new(false)->withLabels('YES', 'NO');
        $rendered = $toggle->render();

        $this->assertStringContainsString('NO', $rendered);
        $this->assertStringNotContainsString('OFF', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testOnColorAddsAnsiCodes(): void
    {
        $toggle = Toggle::new(true)->withOnColor(Color::ansi(9));
        $rendered = $toggle->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testOffColorAddsAnsiCodes(): void
    {
        $toggle = Toggle::new(false)->withOffColor(Color::ansi(8));
        $rendered = $toggle->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTrackColorAddsAnsiCodes(): void
    {
        $toggle = Toggle::new()->withTrackColor(Color::ansi(9));
        $rendered = $toggle->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithOnReturnsNewInstance(): void
    {
        $original = Toggle::new(false);
        $updated = $original->withOn(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithLabelsReturnsNewInstance(): void
    {
        $original = Toggle::new();
        $updated = $original->withLabels('ON', 'OFF');

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithOn(): void
    {
        $original = Toggle::new(false);
        $original->withOn(true);

        $rendered = $original->render();
        $this->assertStringContainsString('○', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Toggle::new();
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $toggle = Toggle::new();
        [$w, $h] = $toggle->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithCustomLabels(): void
    {
        $toggle = Toggle::new()->withLabels('ENABLED', 'DISABLED');
        [$w, $h] = $toggle->getInnerSize();

        $this->assertGreaterThan(7, $w); // Should be wider with custom labels
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testLongLabels(): void
    {
        $toggle = Toggle::new(true)->withLabels(
            'ENABLED_STATE',
            'DISABLED_STATE'
        );
        $rendered = $toggle->render();

        $this->assertStringContainsString('ENABLED_STATE', $rendered);
    }

    public function testUnicodeLabels(): void
    {
        $toggle = Toggle::new(true)->withLabels('開', '關');
        $rendered = $toggle->render();

        $this->assertStringContainsString('開', $rendered);
        $this->assertStringContainsString('關', $rendered);
    }
}

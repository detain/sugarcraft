<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Form;

use SugarCraft\Dash\Components\Form\SwitchComponent;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class SwitchComponentTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testSwitchImplementsSizer(): void
    {
        $switch = SwitchComponent::new();
        $this->assertInstanceOf(Sizer::class, $switch);
    }

    public function testSwitchImplementsItem(): void
    {
        $switch = SwitchComponent::new();
        $this->assertInstanceOf(Item::class, $switch);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $switch = SwitchComponent::new();
        $rendered = $switch->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsBrackets(): void
    {
        $switch = SwitchComponent::new();
        $rendered = $switch->render();

        $this->assertStringContainsString('[', $rendered);
        $this->assertStringContainsString(']', $rendered);
    }

    public function testRenderOffShowsCorrectFormat(): void
    {
        $switch = SwitchComponent::new(false);
        $rendered = $switch->render();

        // Off format: [O ] where O comes before space
        // Check that O appears before the final ]
        $this->assertStringContainsString('[', $rendered);
        $this->assertMatchesRegularExpression('/\[.+?O.+\]\s*$/', $rendered);
    }

    public function testRenderOnShowsCorrectFormat(): void
    {
        $switch = SwitchComponent::new(true);
        $rendered = $switch->render();

        // On format: [ O] - check for [ then space-O (with possible ANSI codes between)
        $this->assertMatchesRegularExpression('/\[.* O.*\]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testOnFactory(): void
    {
        $switch = SwitchComponent::on();
        $rendered = $switch->render();

        $this->assertMatchesRegularExpression('/\[.* O.*\]/', $rendered);
    }

    public function testOffFactory(): void
    {
        $switch = SwitchComponent::off();
        $rendered = $switch->render();

        $this->assertMatchesRegularExpression('/\[.+?O.+\]\s*$/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testOnColorAddsAnsiCodes(): void
    {
        $switch = SwitchComponent::new(true)->withOnColor(Color::ansi(9));
        $rendered = $switch->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testOffColorAddsAnsiCodes(): void
    {
        $switch = SwitchComponent::new(false)->withOffColor(Color::ansi(8));
        $rendered = $switch->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTextColorAddsAnsiCodes(): void
    {
        $switch = SwitchComponent::new()->withTextColor(Color::ansi(7));
        $rendered = $switch->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithOnReturnsNewInstance(): void
    {
        $original = SwitchComponent::new(false);
        $updated = $original->withOn(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithOnColorReturnsNewInstance(): void
    {
        $original = SwitchComponent::new();
        $updated = $original->withOnColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithOn(): void
    {
        $original = SwitchComponent::new(false);
        $original->withOn(true);

        $rendered = $original->render();
        // When we call withOn(true) but original is false, original is unchanged
        // The withOn returns a new instance, so original should still be OFF
        $this->assertMatchesRegularExpression('/\[.+?O.+\]\s*$/', $original->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = SwitchComponent::new();
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $switch = SwitchComponent::new();
        [$w, $h] = $switch->getInnerSize();

        $this->assertSame(4, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeOnState(): void
    {
        $switch = SwitchComponent::on();
        [$w, $h] = $switch->getInnerSize();

        $this->assertSame(4, $w);
        $this->assertSame(1, $h);
    }

}

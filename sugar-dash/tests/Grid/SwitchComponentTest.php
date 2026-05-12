<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\SwitchComponent;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
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

        // Off format: [O ]
        $this->assertStringContainsString('[O', $rendered);
    }

    public function testRenderOnShowsCorrectFormat(): void
    {
        $switch = SwitchComponent::new(true);
        $rendered = $switch->render();

        // On format: [ O]
        $this->assertStringContainsString(' O]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testOnFactory(): void
    {
        $switch = SwitchComponent::on();
        $rendered = $switch->render();

        $this->assertStringContainsString(' O]', $rendered);
    }

    public function testOffFactory(): void
    {
        $switch = SwitchComponent::off();
        $rendered = $switch->render();

        $this->assertStringContainsString('[O', $rendered);
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
        $this->assertStringContainsString('[O', $rendered);
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

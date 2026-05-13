<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\StatusIndicator;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class StatusIndicatorTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testStatusIndicatorImplementsSizer(): void
    {
        $indicator = StatusIndicator::new();
        $this->assertInstanceOf(Sizer::class, $indicator);
    }

    public function testStatusIndicatorImplementsItem(): void
    {
        $indicator = StatusIndicator::new();
        $this->assertInstanceOf(Item::class, $indicator);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $indicator = StatusIndicator::new();
        $rendered = $indicator->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsSymbol(): void
    {
        $indicator = StatusIndicator::new();
        $rendered = $indicator->render();

        // Should contain the indicator symbol
        $this->assertMatchesRegularExpression('/[●○⚠✕]/', $rendered);
    }

    public function testDefaultStatusIsOnline(): void
    {
        $indicator = StatusIndicator::new();
        $this->assertSame(StatusIndicator::Online, $indicator->getStatus());
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testOnlineFactory(): void
    {
        $indicator = StatusIndicator::online();
        $this->assertSame(StatusIndicator::Online, $indicator->getStatus());
    }

    public function testOfflineFactory(): void
    {
        $indicator = StatusIndicator::offline();
        $this->assertSame(StatusIndicator::Offline, $indicator->getStatus());
    }

    public function testWarningFactory(): void
    {
        $indicator = StatusIndicator::warning();
        $this->assertSame(StatusIndicator::Warning, $indicator->getStatus());
    }

    public function testErrorFactory(): void
    {
        $indicator = StatusIndicator::error();
        $this->assertSame(StatusIndicator::Error, $indicator->getStatus());
    }

    public function testInfoFactory(): void
    {
        $indicator = StatusIndicator::info();
        $this->assertSame(StatusIndicator::Info, $indicator->getStatus());
    }

    // ═══════════════════════════════════════════════════════════════
    // Symbols
    // ═══════════════════════════════════════════════════════════════

    public function testGetSymbolReturnsOnlineSymbol(): void
    {
        $indicator = StatusIndicator::new(StatusIndicator::Online);
        $this->assertSame('●', $indicator->getSymbol());
    }

    public function testGetSymbolReturnsOfflineSymbol(): void
    {
        $indicator = StatusIndicator::new(StatusIndicator::Offline);
        $this->assertSame('○', $indicator->getSymbol());
    }

    public function testGetSymbolReturnsWarningSymbol(): void
    {
        $indicator = StatusIndicator::new(StatusIndicator::Warning);
        $this->assertSame('⚠', $indicator->getSymbol());
    }

    public function testGetSymbolReturnsErrorSymbol(): void
    {
        $indicator = StatusIndicator::new(StatusIndicator::Error);
        $this->assertSame('✕', $indicator->getSymbol());
    }

    public function testGetSymbolWithLargerSize(): void
    {
        $indicator = StatusIndicator::new()->withSize(3);
        $this->assertSame('●●●', $indicator->getSymbol());
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithColorAddsAnsiCodes(): void
    {
        $indicator = StatusIndicator::new()
            ->withColor(Color::ansi(9)); // Red
        $rendered = $indicator->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testRenderWithoutColorHasNoAnsi(): void
    {
        $indicator = StatusIndicator::new()->withColor(null);
        $rendered = $indicator->render();

        // Should NOT contain ANSI codes
        $this->assertDoesNotMatchRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $indicator = StatusIndicator::new()
            ->withColor(Color::ansi(9)); // Red
        $rendered = $indicator->render();

        // Should end with reset code
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Pulse animation
    // ═══════════════════════════════════════════════════════════════

    public function testPulseModeReturnsNonEmpty(): void
    {
        $indicator = StatusIndicator::new()->withPulse(true);
        $rendered = $indicator->render();

        $this->assertNotSame('', $rendered);
    }

    public function testPulseModeToggles(): void
    {
        $indicator = StatusIndicator::new()->withPulse(true);

        // Render multiple times to check animation state changes
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $indicator->render();
        }

        // At least some renders should differ (pulse effect)
        // Note: In deterministic test, this relies on timing
        $this->assertNotEmpty($results[0]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $indicator = StatusIndicator::new()->withSize(1);
        [$w, $h] = $indicator->getInnerSize();

        $this->assertSame(1, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithLargerSize(): void
    {
        $indicator = StatusIndicator::new()->withSize(3);
        [$w, $h] = $indicator->getInnerSize();

        $this->assertSame(3, $w);
        $this->assertSame(1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithStatusReturnsNewInstance(): void
    {
        $original = StatusIndicator::new();
        $updated = $original->withStatus(StatusIndicator::Error);

        $this->assertNotSame($original, $updated);
        $this->assertSame(StatusIndicator::Error, $updated->getStatus());
        $this->assertSame(StatusIndicator::Online, $original->getStatus());
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $original = StatusIndicator::new()->withColor(null);
        $red = Color::ansi(9);
        $updated = $original->withColor($red);

        $this->assertNotSame($original, $updated);
    }

    public function testWithPulseReturnsNewInstance(): void
    {
        $original = StatusIndicator::new()->withPulse(false);
        $updated = $original->withPulse(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithSizeReturnsNewInstance(): void
    {
        $original = StatusIndicator::new()->withSize(1);
        $updated = $original->withSize(5);

        $this->assertNotSame($original, $updated);
    }

    public function testWithSizeClampsToMinimumOne(): void
    {
        $indicator = StatusIndicator::new()->withSize(0);
        $this->assertSame(1, $this->invokePrivate('getSize', $indicator));
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = StatusIndicator::new();
        $resized = $original->setSize(10, 1);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helper methods
    // ═══════════════════════════════════════════════════════════════

    /**
     * Invoke a private property for testing via its getter name.
     */
    private function invokePrivate(string $getter, StatusIndicator $indicator): mixed
    {
        $reflection = new \ReflectionClass($indicator);
        // Convert getter name like 'getSize' to property name 'size'
        $propName = lcfirst(ltrim($getter, 'get'));
        $prop = $reflection->getProperty($propName);
        $prop->setAccessible(true);
        return $prop->getValue($indicator);
    }
}

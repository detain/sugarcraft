<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\StatusBar;

use SugarCraft\Dashboard\Components\StatusBar\StatusIndicator;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
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

    public function testPulsePhasesAreByteDifferent(): void
    {
        // The live-pulse assertion the old byte-identical dim branch could
        // never pass: bright half-cycle vs dim half-cycle must DIFFER.
        $indicator = StatusIndicator::new()->withPulse(true);

        $bright = $indicator->render(0.0);   // phase 0.0 -> bright
        $dim = $indicator->render(0.75);     // phase 1.5 -> dim

        $this->assertNotSame($bright, $dim);
    }

    public function testPulseRenderIsDeterministicForAGivenNow(): void
    {
        $base = Color::hex('#22C55E');
        $indicator = new StatusIndicator(StatusIndicator::Online, $base, true, 1);

        // Pins on the parameterized (host-supplied clock) renders: bright
        // half-cycle emits the configured color verbatim, dark half-cycle
        // emits the documented 50%-toward-black blend. Expressions are
        // built through the same toFg/profile channel the render uses, so
        // the pins stay true under any detected color profile.
        $profile = \SugarCraft\Dashboard\Foundation\ColorMode::profile();
        $this->assertSame(
            $base->toFg($profile) . '●' . Ansi::reset(),
            $indicator->render(0.0),
        );
        $this->assertSame(
            $base->blend(Color::rgb(0, 0, 0), 0.5)->toFg($profile) . '●' . Ansi::reset(),
            $indicator->render(0.75),
        );
        // Same $now twice renders byte-identical.
        $this->assertSame($indicator->render(1.25), $indicator->render(1.25));
    }

    public function testPulseBareRenderIsTheStillBrightFrame(): void
    {
        // No clock inside the library: a bare render() never reads wall
        // time — it is the bright frame (reduced-motion reachable).
        $indicator = StatusIndicator::new()->withPulse(true);

        $this->assertSame($indicator->render(), $indicator->render());
        $this->assertSame($indicator->render(0.0), $indicator->render());
    }

    public function testSizeKnobIsClampedToAllocation(): void
    {
        // 176: withSize grew the segment past the row the host allocated.
        $indicator = StatusIndicator::new()->withSize(3);

        $clamped = $indicator->setSize(2, 1)->render();
        $natural = $indicator->render();

        $this->assertSame(2, substr_count($clamped, '●'));
        $this->assertSame(3, substr_count($natural, '●'));

        // A zero-width allocation paints nothing instead of spilling.
        $this->assertSame('', $indicator->setSize(0, 1)->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $indicator = StatusIndicator::new()->withSize(1);
        [$w, $h] = $indicator->innerSize();

        $this->assertSame(1, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithLargerSize(): void
    {
        $indicator = StatusIndicator::new()->withSize(3);
        [$w, $h] = $indicator->innerSize();

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

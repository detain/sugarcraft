<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Feedback;

use SugarCraft\Dash\Components\Feedback\Spinner;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class SpinnerTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testSpinnerImplementsSizer(): void
    {
        $spinner = Spinner::new();
        $this->assertInstanceOf(Sizer::class, $spinner);
    }

    public function testSpinnerImplementsItem(): void
    {
        $spinner = Spinner::new();
        $this->assertInstanceOf(Item::class, $spinner);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $spinner = Spinner::new();
        $rendered = $spinner->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderReturnsSingleChar(): void
    {
        $spinner = Spinner::new()->withMessage('');
        $rendered = $spinner->render();

        // Should be a single character (possibly with ANSI codes)
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
        $this->assertSame(1, mb_strlen($stripped, 'UTF-8'));
    }

    public function testDefaultFramesAreLineSpinner(): void
    {
        $spinner = Spinner::new();

        // Default frames should be the line spinner
        $this->assertSame(['|', '/', '-', '\\'], $this->invokePrivate('getFrames', $spinner));
    }

    // ═══════════════════════════════════════════════════════════════
    // Frame access
    // ═══════════════════════════════════════════════════════════════

    public function testGetFrameCount(): void
    {
        $spinner = Spinner::new();
        $this->assertSame(4, $spinner->getFrameCount());
    }

    public function testGetFrameCountWithCustomFrames(): void
    {
        $spinner = Spinner::new()->withFrames(['*', '+', 'x']);
        $this->assertSame(3, $spinner->getFrameCount());
    }

    public function testGetFrameAtValidIndex(): void
    {
        $spinner = Spinner::new()->withFrames(['a', 'b', 'c']);

        $this->assertSame('a', $spinner->getFrameAt(0));
        $this->assertSame('b', $spinner->getFrameAt(1));
        $this->assertSame('c', $spinner->getFrameAt(2));
    }

    public function testGetFrameAtWrapsPositiveIndex(): void
    {
        $spinner = Spinner::new()->withFrames(['a', 'b', 'c']);

        // Index 3 wraps to 0
        $this->assertSame('a', $spinner->getFrameAt(3));
        // Index 4 wraps to 1
        $this->assertSame('b', $spinner->getFrameAt(4));
    }

    public function testGetFrameAtNegativeIndex(): void
    {
        $spinner = Spinner::new()->withFrames(['a', 'b', 'c']);

        // Negative index wraps from end
        $this->assertSame('c', $spinner->getFrameAt(-1));
        $this->assertSame('b', $spinner->getFrameAt(-2));
    }

    public function testGetFrameAtEmptyFrames(): void
    {
        $spinner = new Spinner([], 80, null, '');
        $this->assertSame('', $spinner->getFrameAt(0));
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithColorAddsAnsiCodes(): void
    {
        $spinner = Spinner::new()
            ->withColor(Color::ansi(9)); // Red
        $rendered = $spinner->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testRenderWithoutColorHasNoAnsi(): void
    {
        $spinner = Spinner::new()->withColor(null);
        $rendered = $spinner->render();

        // Should NOT contain ANSI codes
        $this->assertDoesNotMatchRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $spinner = Spinner::new()
            ->withColor(Color::ansi(9)); // Red
        $rendered = $spinner->render();

        // Should end with reset code
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Message handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithMessageChangesInnerSize(): void
    {
        $spinnerNoMsg = Spinner::new()->withMessage('');
        $spinnerWithMsg = Spinner::new()->withMessage('Loading');

        [$w1, $h1] = $spinnerNoMsg->getInnerSize();
        [$w2, $h2] = $spinnerWithMsg->getInnerSize();

        // Width should be larger with message
        $this->assertGreaterThan($w1, $w2);
        $this->assertSame(1, $h1);
        $this->assertSame(1, $h2);
    }

    public function testEmptyMessageGivesZeroWidth(): void
    {
        $spinner = new Spinner(['|'], 80, null, '');
        [$w, $h] = $spinner->getInnerSize();

        $this->assertGreaterThan(0, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Interval handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithIntervalAcceptsPositiveValues(): void
    {
        $spinner = Spinner::new()->withInterval(100);
        $this->assertSame(100, $this->invokePrivate('getInterval', $spinner));
    }

    public function testWithIntervalClampsNegativeToOne(): void
    {
        $spinner = Spinner::new()->withInterval(-50);
        $this->assertSame(1, $this->invokePrivate('getInterval', $spinner));
    }

    public function testWithIntervalClampsZeroToOne(): void
    {
        $spinner = Spinner::new()->withInterval(0);
        $this->assertSame(1, $this->invokePrivate('getInterval', $spinner));
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithFramesReturnsNewInstance(): void
    {
        $original = Spinner::new();
        $updated = $original->withFrames(['*', '+']);

        $this->assertNotSame($original, $updated);
        $this->assertSame(['*', '+'], $this->invokePrivate('getFrames', $updated));
        $this->assertSame(['|', '/', '-', '\\'], $this->invokePrivate('getFrames', $original));
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $original = Spinner::new()->withColor(null);
        $red = Color::ansi(9);
        $updated = $original->withColor($red);

        $this->assertNotSame($original, $updated);
        $this->assertSame($red, $this->invokePrivate('getColor', $updated));
        $this->assertNull($this->invokePrivate('getColor', $original));
    }

    public function testWithMessageReturnsNewInstance(): void
    {
        $original = Spinner::new()->withMessage('');
        $updated = $original->withMessage('Working');

        $this->assertNotSame($original, $updated);
        $this->assertSame('Working', $this->invokePrivate('getMessage', $updated));
        $this->assertSame('', $this->invokePrivate('getMessage', $original));
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Spinner::new();
        $resized = $original->setSize(10, 1);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $spinner = Spinner::new()->withMessage('Test');
        [$w, $h] = $spinner->getInnerSize();

        // Width = frame(1) + space(1) + message(4) = 6
        $this->assertSame(6, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithoutMessage(): void
    {
        $spinner = new Spinner(['|'], 80, Color::ansi(9), '');
        [$w, $h] = $spinner->getInnerSize();

        $this->assertSame(1, $w);
        $this->assertSame(1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom frames
    // ═══════════════════════════════════════════════════════════════

    public function testCustomFramesAreUsedInRender(): void
    {
        $spinner = Spinner::new()->withFrames(['★', '☆']);
        $frame = $spinner->getFrameAt(0);

        // Should use custom frame
        $this->assertSame('★', $frame);
    }

    public function testDotSpinnerFrames(): void
    {
        $spinner = Spinner::new()->withFrames(['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧']);

        $this->assertSame(8, $spinner->getFrameCount());
        $this->assertSame('⠋', $spinner->getFrameAt(0));
        $this->assertSame('⠙', $spinner->getFrameAt(1));
    }

    public function testSingleFrameSpinner(): void
    {
        $spinner = Spinner::new()->withFrames(['●']);

        $rendered = $spinner->render();
        $this->assertStringContainsString('●', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithEmptyFrames(): void
    {
        $spinner = new Spinner([], 80, null, '');
        $rendered = $spinner->render();

        // Should return empty string
        $this->assertSame('', $rendered);
    }

    public function testMultipleColorInstancesIndependent(): void
    {
        $spinner1 = Spinner::new()->withColor(Color::ansi(1)); // Red
        $spinner2 = Spinner::new()->withColor(Color::ansi(2)); // Green

        $rendered1 = $spinner1->render();
        $rendered2 = $spinner2->render();

        // Both should render without error
        $this->assertNotSame('', $rendered1);
        $this->assertNotSame('', $rendered2);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helper methods
    // ═══════════════════════════════════════════════════════════════

    /**
     * Invoke a private property for testing via its getter name.
     */
    private function invokePrivate(string $getter, Spinner $spinner): mixed
    {
        $reflection = new \ReflectionClass($spinner);
        // Convert getter name like 'getFrames' to property name 'frames'
        $propName = lcfirst(ltrim($getter, 'get'));
        $prop = $reflection->getProperty($propName);
        $prop->setAccessible(true);
        return $prop->getValue($spinner);
    }
}
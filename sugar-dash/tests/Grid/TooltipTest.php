<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Tooltip;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class TooltipTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testTooltipImplementsSizer(): void
    {
        $tooltip = Tooltip::new('Test');
        $this->assertInstanceOf(Sizer::class, $tooltip);
    }

    public function testTooltipImplementsItem(): void
    {
        $tooltip = Tooltip::new('Test');
        $this->assertInstanceOf(Item::class, $tooltip);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $tooltip = Tooltip::new('Test');
        $rendered = $tooltip->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsText(): void
    {
        $tooltip = Tooltip::new('My Tooltip');
        $rendered = $tooltip->render();

        $this->assertStringContainsString('My Tooltip', $rendered);
    }

    public function testRenderHasBoxDrawningCharacters(): void
    {
        $tooltip = Tooltip::new('Test');
        $rendered = $tooltip->render();

        // Should have box-drawing characters for border
        $this->assertStringContainsString('┌', $rendered);
        $this->assertStringContainsString('┐', $rendered);
        $this->assertStringContainsString('└', $rendered);
        $this->assertStringContainsString('┘', $rendered);
    }

    public function testRenderHasPositionArrow(): void
    {
        $tooltip = Tooltip::new('Test');
        $rendered = $tooltip->render();

        // Default position is top, should have up arrow
        $this->assertStringContainsString('▲', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Preset styles
    // ═══════════════════════════════════════════════════════════════

    public function testDarkFactory(): void
    {
        $tooltip = Tooltip::dark('Dark Tooltip');
        $rendered = $tooltip->render();

        $this->assertStringContainsString('Dark Tooltip', $rendered);
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testInfoFactory(): void
    {
        $tooltip = Tooltip::info('Info Tooltip');
        $rendered = $tooltip->render();

        $this->assertStringContainsString('Info Tooltip', $rendered);
    }

    public function testWarningFactory(): void
    {
        $tooltip = Tooltip::warning('Warning Tooltip');
        $rendered = $tooltip->render();

        $this->assertStringContainsString('Warning Tooltip', $rendered);
    }

    public function testDangerFactory(): void
    {
        $tooltip = Tooltip::danger('Danger Tooltip');
        $rendered = $tooltip->render();

        $this->assertStringContainsString('Danger Tooltip', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Positions
    // ═══════════════════════════════════════════════════════════════

    public function testPositionTopHasUpArrow(): void
    {
        $tooltip = Tooltip::new('Test')->withPosition('top');
        $rendered = $tooltip->render();

        $this->assertStringContainsString('▲', $rendered);
    }

    public function testPositionBottomHasDownArrow(): void
    {
        $tooltip = Tooltip::new('Test')->withPosition('bottom');
        $rendered = $tooltip->render();

        $this->assertStringContainsString('▼', $rendered);
    }

    public function testPositionLeftHasLeftArrow(): void
    {
        $tooltip = Tooltip::new('Test')->withPosition('left');
        $rendered = $tooltip->render();

        $this->assertStringContainsString('◀', $rendered);
    }

    public function testPositionRightHasRightArrow(): void
    {
        $tooltip = Tooltip::new('Test')->withPosition('right');
        $rendered = $tooltip->render();

        $this->assertStringContainsString('▶', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBackgroundColorAddsAnsiCodes(): void
    {
        $tooltip = Tooltip::new('Test')
            ->withBackgroundColor(Color::ansi(9));
        $rendered = $tooltip->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testForegroundColorAddsAnsiCodes(): void
    {
        $tooltip = Tooltip::new('Test')
            ->withForegroundColor(Color::ansi(9));
        $rendered = $tooltip->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $tooltip = Tooltip::new('Test')
            ->withBackgroundColor(Color::ansi(9))
            ->withForegroundColor(Color::ansi(15));
        $rendered = $tooltip->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Width allocation
    // ═══════════════════════════════════════════════════════════════

    public function testWidthAllocation(): void
    {
        $tooltip = Tooltip::new('Hi')->setSize(30, 5);
        $rendered = $tooltip->render();

        // Should use the allocated width
        $lines = explode("\n", $rendered);
        $this->assertGreaterThanOrEqual(30, mb_strlen($lines[1] ?? '', 'UTF-8'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithTextReturnsNewInstance(): void
    {
        $original = Tooltip::new('Original');
        $updated = $original->withText('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
        $this->assertStringNotContainsString('Updated', $original->render());
    }

    public function testWithPositionReturnsNewInstance(): void
    {
        $original = Tooltip::new('Test');
        $updated = $original->withPosition('bottom');

        $this->assertNotSame($original, $updated);
    }

    public function testWithBackgroundColorReturnsNewInstance(): void
    {
        $original = Tooltip::new('Test');
        $updated = $original->withBackgroundColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithForegroundColorReturnsNewInstance(): void
    {
        $original = Tooltip::new('Test');
        $updated = $original->withForegroundColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithText(): void
    {
        $original = Tooltip::new('Original');
        $original->withText('Changed');

        $this->assertStringContainsString('Original', $original->render());
        $this->assertStringNotContainsString('Changed', $original->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Tooltip::new('Test');
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $tooltip = Tooltip::new('Test');
        [$w, $h] = $tooltip->getInnerSize();

        $this->assertGreaterThan(0, $w);
        // Height is 4: top border + text + bottom border + position arrow
        $this->assertSame(4, $h);
    }

    public function testGetInnerSizeWithWidthAllocation(): void
    {
        $tooltip = Tooltip::new('Hi')->setSize(50, 5);
        [$w, ] = $tooltip->getInnerSize();

        $this->assertGreaterThanOrEqual(50, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyText(): void
    {
        $tooltip = Tooltip::new('');
        $rendered = $tooltip->render();

        $this->assertNotSame('', $rendered);
        // Should still have box characters
        $this->assertStringContainsString('┌', $rendered);
    }

    public function testUnicodeText(): void
    {
        $tooltip = Tooltip::new('日本語');
        $rendered = $tooltip->render();

        $this->assertStringContainsString('日本語', $rendered);
    }

    public function testVeryLongText(): void
    {
        $tooltip = Tooltip::new(str_repeat('x', 100));
        $rendered = $tooltip->render();

        $this->assertStringContainsString(str_repeat('x', 100), $rendered);
    }
}

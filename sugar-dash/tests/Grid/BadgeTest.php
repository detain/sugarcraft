<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Badge;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class BadgeTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testBadgeImplementsSizer(): void
    {
        $badge = Badge::new('Active');
        $this->assertInstanceOf(Sizer::class, $badge);
    }

    public function testBadgeImplementsItem(): void
    {
        $badge = Badge::new('Active');
        $this->assertInstanceOf(Item::class, $badge);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $badge = Badge::new('Active');
        $rendered = $badge->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsLabel(): void
    {
        $badge = Badge::new('Hello');
        $rendered = $badge->render();

        $this->assertStringContainsString('Hello', $rendered);
    }

    public function testRenderWithEmptyLabel(): void
    {
        $badge = Badge::new('');
        $rendered = $badge->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Preset styles
    // ═══════════════════════════════════════════════════════════════

    public function testSuccessFactory(): void
    {
        $badge = Badge::success('Done');
        $rendered = $badge->render();

        $this->assertStringContainsString('Done', $rendered);
        // Success is green
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testWarningFactory(): void
    {
        $badge = Badge::warning('Caution');
        $rendered = $badge->render();

        $this->assertStringContainsString('Caution', $rendered);
    }

    public function testDangerFactory(): void
    {
        $badge = Badge::danger('Error');
        $rendered = $badge->render();

        $this->assertStringContainsString('Error', $rendered);
    }

    public function testInfoFactory(): void
    {
        $badge = Badge::info('Info');
        $rendered = $badge->render();

        $this->assertStringContainsString('Info', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Outlined style
    // ═══════════════════════════════════════════════════════════════

    public function testOutlinedStyleAddsBorderChars(): void
    {
        $badge = Badge::new('Test')->withOutlined(true);
        $rendered = $badge->render();

        // Outlined style uses box-drawing characters
        $this->assertStringContainsString('┌', $rendered);
        $this->assertStringContainsString('┐', $rendered);
        $this->assertStringContainsString('│', $rendered);
        $this->assertStringContainsString('└', $rendered);
    }

    public function testNonOutlinedStyleDoesNotHaveBorderChars(): void
    {
        $badge = Badge::new('Test');
        $rendered = $badge->render();

        // Should not have box-drawing characters
        $this->assertStringNotContainsString('┌', $rendered);
        $this->assertStringNotContainsString('┐', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testForegroundColorAddsAnsiCodes(): void
    {
        $badge = Badge::new('Test')
            ->withForegroundColor(Color::ansi(9));
        $rendered = $badge->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBackgroundColorAddsAnsiCodes(): void
    {
        $badge = Badge::new('Test')
            ->withBackgroundColor(Color::ansi(9));
        $rendered = $badge->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $badge = Badge::new('Test')
            ->withForegroundColor(Color::ansi(9))
            ->withBackgroundColor(Color::ansi(1));
        $rendered = $badge->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    public function testNullColorsRendersWithoutAnsi(): void
    {
        $badge = new Badge('Test', null, null, false, ' ');
        $rendered = $badge->render();

        // No ANSI codes when colors are null
        $this->assertDoesNotMatchRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Padding
    // ═══════════════════════════════════════════════════════════════

    public function testCustomPadding(): void
    {
        $badge1 = Badge::new('Test')->withPadding('  ');
        $badge2 = Badge::new('Test')->withPadding('');

        $rendered1 = $badge1->render();
        $rendered2 = $badge2->render();

        // More padding = wider output
        $this->assertGreaterThan(
            mb_strlen($rendered2, 'UTF-8'),
            mb_strlen($rendered1, 'UTF-8')
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Badge::new('Test');
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocation(): void
    {
        $badge = Badge::new('Hi')->setSize(30, 1);
        $rendered = $badge->render();

        // Should pad to fill width
        $this->assertGreaterThanOrEqual(30, mb_strlen($rendered, 'UTF-8'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithLabelReturnsNewInstance(): void
    {
        $original = Badge::new('Original');
        $updated = $original->withLabel('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
        $this->assertStringNotContainsString('Updated', $original->render());
    }

    public function testWithForegroundColorReturnsNewInstance(): void
    {
        $original = Badge::new('Test');
        $updated = $original->withForegroundColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithBackgroundColorReturnsNewInstance(): void
    {
        $original = Badge::new('Test');
        $updated = $original->withBackgroundColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithOutlinedReturnsNewInstance(): void
    {
        $original = Badge::new('Test');
        $updated = $original->withOutlined(true);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithLabel(): void
    {
        $original = Badge::new('Original');
        $original->withLabel('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $badge = Badge::new('Test');
        [$w, $h] = $badge->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h); // Single-line for non-outlined
    }

    public function testGetInnerSizeOutlinedHasHeightThree(): void
    {
        $badge = Badge::new('Test')->withOutlined(true);
        [, $h] = $badge->getInnerSize();

        $this->assertSame(3, $h); // Box with top, middle, bottom
    }

    public function testGetInnerSizeWithWidthAllocation(): void
    {
        $badge = Badge::new('Hi')->setSize(50, 1);
        [$w, ] = $badge->getInnerSize();

        $this->assertGreaterThanOrEqual(50, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongLabel(): void
    {
        $badge = Badge::new(str_repeat('x', 100));
        $rendered = $badge->render();

        $this->assertStringContainsString('x', $rendered);
    }

    public function testUnicodeLabel(): void
    {
        $badge = Badge::new('日本語');
        $rendered = $badge->render();

        $this->assertStringContainsString('日本語', $rendered);
    }

    public function testSpecialCharsInLabel(): void
    {
        $badge = Badge::new('Test & <Tag> "Quote"');
        $rendered = $badge->render();

        $this->assertStringContainsString('Test & <Tag> "Quote"', $rendered);
    }
}

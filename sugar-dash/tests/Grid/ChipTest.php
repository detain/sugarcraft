<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Chip;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ChipTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testChipImplementsSizer(): void
    {
        $chip = Chip::new('Test');
        $this->assertInstanceOf(Sizer::class, $chip);
    }

    public function testChipImplementsItem(): void
    {
        $chip = Chip::new('Test');
        $this->assertInstanceOf(Item::class, $chip);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $chip = Chip::new('Test');
        $rendered = $chip->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsLabel(): void
    {
        $chip = Chip::new('Hello');
        $rendered = $chip->render();

        $this->assertStringContainsString('Hello', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Preset styles
    // ═══════════════════════════════════════════════════════════════

    public function testPrimaryFactory(): void
    {
        $chip = Chip::primary('Primary');
        $rendered = $chip->render();

        $this->assertStringContainsString('Primary', $rendered);
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testSuccessFactory(): void
    {
        $chip = Chip::success('Done');
        $rendered = $chip->render();

        $this->assertStringContainsString('Done', $rendered);
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testWarningFactory(): void
    {
        $chip = Chip::warning('Caution');
        $rendered = $chip->render();

        $this->assertStringContainsString('Caution', $rendered);
    }

    public function testDangerFactory(): void
    {
        $chip = Chip::danger('Error');
        $rendered = $chip->render();

        $this->assertStringContainsString('Error', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Selected state
    // ═══════════════════════════════════════════════════════════════

    public function testSelectedState(): void
    {
        $chip = Chip::new('Test')->withSelected(true);
        $rendered = $chip->render();

        $this->assertStringContainsString('Test', $rendered);
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testUnselectedState(): void
    {
        $chip = Chip::new('Test')->withSelected(false);
        $rendered = $chip->render();

        $this->assertStringContainsString('Test', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Deletable state
    // ═══════════════════════════════════════════════════════════════

    public function testDeletableAddsDeleteIcon(): void
    {
        $chip = Chip::new('Test')->withDeletable(true);
        $rendered = $chip->render();

        $this->assertStringContainsString('Test', $rendered);
        $this->assertStringContainsString('×', $rendered);
    }

    public function testNonDeletableDoesNotHaveDeleteIcon(): void
    {
        $chip = Chip::new('Test');
        $rendered = $chip->render();

        $this->assertStringNotContainsString('×', $rendered);
    }

    public function testCustomDeleteIcon(): void
    {
        $chip = Chip::new('Test')
            ->withDeletable(true)
            ->withDeleteIcon('✕');
        $rendered = $chip->render();

        $this->assertStringContainsString('✕', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testForegroundColorAddsAnsiCodes(): void
    {
        $chip = Chip::new('Test')
            ->withForegroundColor(Color::ansi(9));
        $rendered = $chip->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBackgroundColorAddsAnsiCodes(): void
    {
        $chip = Chip::new('Test')
            ->withBackgroundColor(Color::ansi(9));
        $rendered = $chip->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $chip = Chip::new('Test')
            ->withForegroundColor(Color::ansi(9))
            ->withBackgroundColor(Color::ansi(1));
        $rendered = $chip->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Chip::new('Test');
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocation(): void
    {
        $chip = Chip::new('Hi')->setSize(30, 1);
        $rendered = $chip->render();

        $this->assertGreaterThanOrEqual(30, mb_strlen($rendered, 'UTF-8'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithLabelReturnsNewInstance(): void
    {
        $original = Chip::new('Original');
        $updated = $original->withLabel('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testWithSelectedReturnsNewInstance(): void
    {
        $original = Chip::new('Test');
        $updated = $original->withSelected(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithDeletableReturnsNewInstance(): void
    {
        $original = Chip::new('Test');
        $updated = $original->withDeletable(true);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithLabel(): void
    {
        $original = Chip::new('Original');
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
        $chip = Chip::new('Test');
        [$w, $h] = $chip->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithWidthAllocation(): void
    {
        $chip = Chip::new('Hi')->setSize(50, 1);
        [$w, ] = $chip->getInnerSize();

        $this->assertGreaterThanOrEqual(50, $w);
    }

    public function testGetInnerSizeWithDeleteIcon(): void
    {
        $chip = Chip::new('Test')->withDeletable(true);
        [$w, ] = $chip->getInnerSize();

        // Should be wider with delete icon
        $chipWithoutDelete = Chip::new('Test');
        [$w2, ] = $chipWithoutDelete->getInnerSize();

        $this->assertGreaterThan($w2, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongLabel(): void
    {
        $chip = Chip::new(str_repeat('x', 100));
        $rendered = $chip->render();

        $this->assertStringContainsString('x', $rendered);
    }

    public function testUnicodeLabel(): void
    {
        $chip = Chip::new('日本語');
        $rendered = $chip->render();

        $this->assertStringContainsString('日本語', $rendered);
    }

    public function testEmptyLabel(): void
    {
        $chip = Chip::new('');
        $rendered = $chip->render();

        $this->assertNotSame('', $rendered);
    }
}

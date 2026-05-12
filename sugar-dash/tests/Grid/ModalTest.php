<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Modal;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ModalTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testModalImplementsSizer(): void
    {
        $modal = Modal::new('Test content');
        $this->assertInstanceOf(Sizer::class, $modal);
    }

    public function testModalImplementsItem(): void
    {
        $modal = Modal::new('Test content');
        $this->assertInstanceOf(Item::class, $modal);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $modal = Modal::new('Test content');
        $rendered = $modal->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsContent(): void
    {
        $modal = Modal::new('Hello World');
        $rendered = $modal->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderHasBorderChars(): void
    {
        $modal = Modal::new('Test');
        $rendered = $modal->render();

        // Default uses '─' and '│' as borders
        $this->assertMatchesRegularExpression('/[─│┌┐└┘]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Title handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithTitle(): void
    {
        $modal = Modal::new('Content')->withTitle('Title');
        $rendered = $modal->render();

        $this->assertStringContainsString('Title', $rendered);
        $this->assertStringContainsString('Content', $rendered);
    }

    public function testTitledFactory(): void
    {
        $modal = Modal::titled('Content', 'My Title');
        $rendered = $modal->render();

        $this->assertStringContainsString('My Title', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $modal = Modal::new('Test')
            ->withBorderColor(Color::ansi(9));
        $rendered = $modal->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBgColorAddsAnsiCodes(): void
    {
        $modal = Modal::new('Test')
            ->withBgColor(Color::ansi(9));
        $rendered = $modal->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Style handling
    // ═══════════════════════════════════════════════════════════════

    public function testStyleDoubleUsesDoubleChars(): void
    {
        $modal = Modal::new('Test')->withStyle('double');
        $rendered = $modal->render();

        // Double style uses ╔╗╚╝═║
        $this->assertMatchesRegularExpression('/[╔╗╚╝]/', $rendered);
    }

    public function testStyleRoundedUsesRoundedChars(): void
    {
        $modal = Modal::new('Test')->withStyle('rounded');
        $rendered = $modal->render();

        // Rounded style uses ╭╮╰╯─│
        $this->assertMatchesRegularExpression('/[╭╮╰╯]/', $rendered);
    }

    public function testStyleBoldUsesBoldChars(): void
    {
        $modal = Modal::new('Test')->withStyle('bold');
        $rendered = $modal->render();

        // Bold style uses ┏┓┗┛━┃
        $this->assertMatchesRegularExpression('/[┏┓┗┛]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Close button
    // ═══════════════════════════════════════════════════════════════

    public function testWithShowClose(): void
    {
        $modal = Modal::new('Test')->withShowClose(true);
        $rendered = $modal->render();

        $this->assertStringContainsString('×', $rendered);
    }

    public function testWithCloseLabel(): void
    {
        $modal = Modal::new('Test')->withCloseLabel('[X]');
        $rendered = $modal->render();

        $this->assertStringContainsString('[X]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Modal::new('Test');
        $resized = $original->setSize(80, 24);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsOutput(): void
    {
        $modal = Modal::new('Test content')->setSize(80, 24);
        $rendered = $modal->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithContentReturnsNewInstance(): void
    {
        $original = Modal::new('Original');
        $updated = $original->withContent('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testOriginalUnchangedAfterWithContent(): void
    {
        $original = Modal::new('Original');
        $original->withContent('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $modal = Modal::new('Test');
        [$w, $h] = $modal->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithExplicitSize(): void
    {
        $modal = Modal::new('Test')->setSize(100, 30);
        [$w, $h] = $modal->getInnerSize();

        $this->assertGreaterThanOrEqual(20, $w);
        $this->assertGreaterThanOrEqual(5, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyContent(): void
    {
        $modal = Modal::new('');
        $rendered = $modal->render();

        $this->assertNotSame('', $rendered);
    }

    public function testLongContentWraps(): void
    {
        $modal = Modal::new('This is a very long content that should wrap within the modal to test word wrapping.');
        $modal = $modal->setSize(50, 15);
        $rendered = $modal->render();

        // Should have multiple lines due to wrapping
        $this->assertGreaterThan(1, substr_count($rendered, "\n"));
    }

    public function testUnicodeContent(): void
    {
        $modal = Modal::new('日本語コンテンツ');
        $modal = $modal->setSize(40, 15);
        $rendered = $modal->render();

        $this->assertStringContainsString('日本語コンテンツ', $rendered);
    }
}
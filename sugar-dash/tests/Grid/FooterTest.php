<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Footer;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class FooterTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testFooterImplementsSizer(): void
    {
        $footer = Footer::new();
        $this->assertInstanceOf(Sizer::class, $footer);
    }

    public function testFooterImplementsItem(): void
    {
        $footer = Footer::new();
        $this->assertInstanceOf(Item::class, $footer);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $footer = Footer::new();
        $rendered = $footer->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsContent(): void
    {
        $footer = Footer::new('Hello World');
        $rendered = $footer->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderHasBorderChars(): void
    {
        $footer = Footer::new('Test');
        $rendered = $footer->render();

        // Default has top border
        $this->assertStringContainsString('─', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Copyright factory
    // ═══════════════════════════════════════════════════════════════

    public function testCopyrightFactory(): void
    {
        $footer = Footer::copyright('Acme Inc');
        $rendered = $footer->render();

        $this->assertStringContainsString('©', $rendered);
        $this->assertStringContainsString('Acme Inc', $rendered);
    }

    public function testCopyrightWithoutHolder(): void
    {
        $footer = Footer::copyright();
        $rendered = $footer->render();

        $this->assertStringContainsString('©', $rendered);
        $this->assertStringContainsString('All rights reserved', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Alignment handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithAlignLeft(): void
    {
        $footer = Footer::new('Test')->withAlign(Footer::ALIGN_LEFT);
        $rendered = $footer->render();

        $this->assertStringContainsString('Test', $rendered);
    }

    public function testWithAlignCenter(): void
    {
        $footer = Footer::new('Test')->withAlign(Footer::ALIGN_CENTER);
        $rendered = $footer->render();

        $this->assertStringContainsString('Test', $rendered);
    }

    public function testWithAlignRight(): void
    {
        $footer = Footer::new('Test')->withAlign(Footer::ALIGN_RIGHT);
        $rendered = $footer->render();

        $this->assertStringContainsString('Test', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $footer = Footer::new()
            ->withBorderColor(Color::ansi(9));
        $rendered = $footer->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTextColorAddsAnsiCodes(): void
    {
        $footer = Footer::new('Test')
            ->withTextColor(Color::ansi(12));
        $rendered = $footer->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBgColorAddsAnsiCodes(): void
    {
        $footer = Footer::new()
            ->withBgColor(Color::ansi(9));
        $rendered = $footer->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Border position handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderPositionTop(): void
    {
        $footer = Footer::new('Test')->withBorderPosition('top');
        $rendered = $footer->render();

        // Should have border at top
        $lines = explode("\n", $rendered);
        $this->assertStringContainsString('─', $lines[0]);
    }

    public function testBorderPositionBottom(): void
    {
        $footer = Footer::new('Test')->withBorderPosition('bottom');
        $rendered = $footer->render();

        // Should have border at bottom
        $lines = explode("\n", $rendered);
        $this->assertStringContainsString('─', array_pop($lines));
    }

    public function testBorderPositionBoth(): void
    {
        $footer = Footer::new('Test')->withBorderPosition('both');
        $rendered = $footer->render();

        // Should have border at top and bottom
        $lines = explode("\n", $rendered);
        $this->assertStringContainsString('─', $lines[0]);
        $this->assertStringContainsString('─', array_pop($lines));
    }

    public function testBorderPositionNone(): void
    {
        $footer = Footer::new('Test')->withBorderPosition('none');
        $rendered = $footer->render();

        // Should not have border
        $this->assertStringNotContainsString('─', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Footer::new();
        $resized = $original->setSize(80, 24);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsOutput(): void
    {
        $footer = Footer::new('Test content')->setSize(80, 24);
        $rendered = $footer->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithContentReturnsNewInstance(): void
    {
        $original = Footer::new('Original');
        $updated = $original->withContent('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testOriginalUnchangedAfterWithContent(): void
    {
        $original = Footer::new('Original');
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
        $footer = Footer::new();
        [$w, $h] = $footer->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThanOrEqual(1, $h);
    }

    public function testGetInnerSizeWithBorderBoth(): void
    {
        $footer = Footer::new()->withBorderPosition('both');
        [, $h] = $footer->getInnerSize();

        // Should have top border line + content line + bottom border line = 3
        $this->assertSame(3, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyContent(): void
    {
        $footer = Footer::new('');
        $rendered = $footer->render();

        $this->assertNotSame('', $rendered);
    }

    public function testLongContentTruncates(): void
    {
        $footer = Footer::new('This is a very long footer content that should be truncated.');
        $footer = $footer->setSize(30, 3);
        $rendered = $footer->render();

        // Content should be truncated
        $lines = explode("\n", $rendered);
        $this->assertLessThanOrEqual(30, \SugarCraft\Core\Util\Width::string($lines[1]));
    }

    public function testUnicodeContent(): void
    {
        $footer = Footer::new('© 2026 日本語');
        $rendered = $footer->render();

        $this->assertStringContainsString('日本語', $rendered);
    }
}
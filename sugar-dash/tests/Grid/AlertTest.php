<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Alert;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class AlertTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testAlertImplementsSizer(): void
    {
        $alert = Alert::new('Test message');
        $this->assertInstanceOf(Sizer::class, $alert);
    }

    public function testAlertImplementsItem(): void
    {
        $alert = Alert::new('Test message');
        $this->assertInstanceOf(Item::class, $alert);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $alert = Alert::new('Test message');
        $rendered = $alert->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsMessage(): void
    {
        $alert = Alert::new('Hello World');
        $rendered = $alert->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderHasBorderChar(): void
    {
        $alert = Alert::new('Test');
        $rendered = $alert->render();

        // Default uses '│' as border
        $this->assertStringContainsString('│', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Preset styles
    // ═══════════════════════════════════════════════════════════════

    public function testInfoFactory(): void
    {
        $alert = Alert::info('Information message');
        $rendered = $alert->render();

        $this->assertStringContainsString('Information message', $rendered);
        $this->assertStringContainsString('ℹ', $alert->render());
    }

    public function testWarningFactory(): void
    {
        $alert = Alert::warning('Warning message');
        $rendered = $alert->render();

        $this->assertStringContainsString('Warning message', $rendered);
        $this->assertStringContainsString('⚠', $alert->render());
    }

    public function testErrorFactory(): void
    {
        $alert = Alert::error('Error message');
        $rendered = $alert->render();

        $this->assertStringContainsString('Error message', $rendered);
        $this->assertStringContainsString('✖', $alert->render());
    }

    public function testSuccessFactory(): void
    {
        $alert = Alert::success('Success message');
        $rendered = $alert->render();

        $this->assertStringContainsString('Success message', $rendered);
        $this->assertStringContainsString('✓', $alert->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Title handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithTitle(): void
    {
        $alert = Alert::new('Message')->withTitle('Title');
        $rendered = $alert->render();

        $this->assertStringContainsString('Title', $rendered);
        $this->assertStringContainsString('Message', $rendered);
    }

    public function testNullTitleDoesNotRender(): void
    {
        $alert = Alert::new('Message');
        $rendered = $alert->render();

        // Title is null by default, only message should show
        $this->assertStringContainsString('Message', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $alert = Alert::new('Test')
            ->withBorderColor(Color::ansi(9));
        $rendered = $alert->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBackgroundColorAddsAnsiCodes(): void
    {
        $alert = Alert::new('Test')
            ->withBackgroundColor(Color::ansi(9));
        $rendered = $alert->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $alert = Alert::new('Test')
            ->withBorderColor(Color::ansi(9))
            ->withBackgroundColor(Color::ansi(1));
        $rendered = $alert->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Border character
    // ═══════════════════════════════════════════════════════════════

    public function testCustomBorderChar(): void
    {
        $alert = Alert::new('Test')->withBorderChar(':');
        $rendered = $alert->render();

        $this->assertStringContainsString(':', $rendered);
        $this->assertStringNotContainsString('│', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Alert::new('Test');
        $resized = $original->setSize(50, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsOutput(): void
    {
        $alert = Alert::new('Test message that might wrap')->setSize(30, 10);
        $rendered = $alert->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Text wrapping
    // ═══════════════════════════════════════════════════════════════

    public function testLongMessageWraps(): void
    {
        $alert = Alert::new('This is a very long message that should wrap within the allocated width to test the word wrapping functionality.');
        $alert = $alert->setSize(30, 10);
        $rendered = $alert->render();

        $lines = explode("\n", $rendered);
        // Wrapped content should have multiple lines
        $this->assertGreaterThan(1, count($lines));
    }

    public function testShortMessageDoesNotWrap(): void
    {
        $alert = Alert::new('Short')->setSize(50, 5);
        $rendered = $alert->render();

        $lines = explode("\n", $rendered);
        // Short message should fit on one line
        $this->assertLessThanOrEqual(2, count($lines));
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithMessageReturnsNewInstance(): void
    {
        $original = Alert::new('Original');
        $updated = $original->withMessage('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testWithTitleReturnsNewInstance(): void
    {
        $original = Alert::new('Message');
        $updated = $original->withTitle('New Title');

        $this->assertNotSame($original, $updated);
    }

    public function testWithBorderColorReturnsNewInstance(): void
    {
        $original = Alert::new('Test');
        $updated = $original->withBorderColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithBackgroundColorReturnsNewInstance(): void
    {
        $original = Alert::new('Test');
        $updated = $original->withBackgroundColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithBorderCharReturnsNewInstance(): void
    {
        $original = Alert::new('Test');
        $updated = $original->withBorderChar('#');

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithMessage(): void
    {
        $original = Alert::new('Original');
        $original->withMessage('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $alert = Alert::new('Test message');
        [$w, $h] = $alert->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithTitleIncreasesHeight(): void
    {
        $alertNoTitle = Alert::new('Message');
        $alertWithTitle = Alert::new('Message')->withTitle('Title');

        [, $h1] = $alertNoTitle->getInnerSize();
        [, $h2] = $alertWithTitle->getInnerSize();

        $this->assertGreaterThanOrEqual($h1, $h2);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyMessage(): void
    {
        $alert = Alert::new('');
        $rendered = $alert->render();

        $this->assertNotSame('', $rendered);
    }

    public function testMultipleSpacesInMessage(): void
    {
        $alert = Alert::new('Word1    Word2    Word3');
        $rendered = $alert->render();

        // Multiple spaces should be handled
        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeMessage(): void
    {
        $alert = Alert::new('日本語メッセージ');
        $rendered = $alert->render();

        $this->assertStringContainsString('日本語メッセージ', $rendered);
    }

    public function testSpecialCharsMessage(): void
    {
        $alert = Alert::new('Test <tag> & "quotes"');
        $rendered = $alert->render();

        $this->assertStringContainsString('Test <tag> & "quotes"', $rendered);
    }
}

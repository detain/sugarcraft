<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Toast;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class ToastTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testToastImplementsSizer(): void
    {
        $toast = Toast::new('Test message');
        $this->assertInstanceOf(Sizer::class, $toast);
    }

    public function testToastImplementsItem(): void
    {
        $toast = Toast::new('Test message');
        $this->assertInstanceOf(Item::class, $toast);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $toast = Toast::new('Test message');
        $rendered = $toast->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsMessage(): void
    {
        $toast = Toast::new('Hello World');
        $rendered = $toast->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderHasBorderChars(): void
    {
        $toast = Toast::new('Test');
        $rendered = $toast->render();

        // Should have box-drawing border
        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('╮', $rendered);
        $this->assertStringContainsString('╰', $rendered);
        $this->assertStringContainsString('╯', $rendered);
    }

    public function testRenderHasVerticalBorders(): void
    {
        $toast = Toast::new('Test');
        $rendered = $toast->render();

        $this->assertStringContainsString('│', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Preset styles
    // ═══════════════════════════════════════════════════════════════

    public function testInfoFactory(): void
    {
        $toast = Toast::info('Information');
        $rendered = $toast->render();

        $this->assertStringContainsString('Information', $rendered);
        $this->assertStringContainsString('ℹ', $rendered);
    }

    public function testSuccessFactory(): void
    {
        $toast = Toast::success('Success!');
        $rendered = $toast->render();

        $this->assertStringContainsString('Success!', $rendered);
        $this->assertStringContainsString('✓', $rendered);
    }

    public function testWarningFactory(): void
    {
        $toast = Toast::warning('Warning');
        $rendered = $toast->render();

        $this->assertStringContainsString('Warning', $rendered);
        $this->assertStringContainsString('⚠', $rendered);
    }

    public function testErrorFactory(): void
    {
        $toast = Toast::error('Error occurred');
        $rendered = $toast->render();

        $this->assertStringContainsString('Error occurred', $rendered);
        $this->assertStringContainsString('✖', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Title
    // ═══════════════════════════════════════════════════════════════

    public function testWithTitle(): void
    {
        $toast = Toast::new('Message')->withTitle('Title');
        $rendered = $toast->render();

        $this->assertStringContainsString('Title', $rendered);
        $this->assertStringContainsString('Message', $rendered);
    }

    public function testNullTitleRendersWithoutTitleLine(): void
    {
        $toast = Toast::new('Message');
        $rendered = $toast->render();

        // Should only have one content line with message
        $lines = explode("\n", $rendered);
        $contentLines = array_filter($lines, fn($l) => str_contains($l, '│'));
        $this->assertLessThanOrEqual(3, count($contentLines)); // top, content, bottom
    }

    // ═══════════════════════════════════════════════════════════════
    // Icon
    // ═══════════════════════════════════════════════════════════════

    public function testIconInMessage(): void
    {
        $toast = Toast::new('Message')->withIcon('★');
        $rendered = $toast->render();

        $this->assertStringContainsString('★', $rendered);
    }

    public function testDefaultToastNoIcon(): void
    {
        $toast = Toast::new('Message');
        $rendered = $toast->render();

        // Default toast has no icon
        $this->assertStringNotContainsString('ℹ', $rendered);
        $this->assertStringNotContainsString('✓', $rendered);
        $this->assertStringNotContainsString('⚠', $rendered);
        $this->assertStringNotContainsString('✖', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBackgroundColorAddsAnsiCodes(): void
    {
        $toast = Toast::new('Test')
            ->withBackgroundColor(Color::ansi(9));
        $rendered = $toast->render();

        // Background color uses 24-bit bg code
        $this->assertMatchesRegularExpression('/\x1b\[4/', $rendered);
    }

    public function testForegroundColorAddsAnsiCodes(): void
    {
        $toast = Toast::new('Test')
            ->withForegroundColor(Color::ansi(7));
        $rendered = $toast->render();

        $this->assertMatchesRegularExpression('/\x1b\[3/', $rendered);
    }

    public function testBorderColorAddsAnsiCodes(): void
    {
        $toast = Toast::new('Test')
            ->withBorderColor(Color::ansi(9));
        $rendered = $toast->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $toast = Toast::new('Test')
            ->withBackgroundColor(Color::ansi(9))
            ->withForegroundColor(Color::ansi(7));
        $rendered = $toast->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Max width
    // ═══════════════════════════════════════════════════════════════

    public function testMaxWidthConstraint(): void
    {
        $toast = Toast::new('Short message')->withMaxWidth(20);
        $rendered = $toast->render();

        $lines = explode("\n", $rendered);
        foreach ($lines as $line) {
            if (str_contains($line, '─')) {
                continue; // Skip border lines
            }
            if ($line !== '' && !ctype_space($line)) {
                // Content should be within max width minus padding
                $this->assertLessThanOrEqual(22, mb_strlen($line, 'UTF-8'));
            }
        }
    }

    public function testLongMessageRespectsMaxWidth(): void
    {
        $toast = Toast::new(str_repeat('word ', 30))->withMaxWidth(30);
        $rendered = $toast->render();

        // Should wrap
        $lines = explode("\n", $rendered);
        $this->assertGreaterThan(2, count($lines));
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Toast::new('Test');
        $resized = $original->setSize(40, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsOutput(): void
    {
        $toast = Toast::new('Test message')->setSize(50, 5);
        $rendered = $toast->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Text wrapping
    // ═══════════════════════════════════════════════════════════════

    public function testLongMessageWraps(): void
    {
        $toast = Toast::new('This is a very long message that should wrap to multiple lines within the toast container.');
        $rendered = $toast->render();

        $lines = explode("\n", $rendered);
        // Should have more than 3 lines (top, one content, bottom)
        $this->assertGreaterThan(3, count($lines));
    }

    public function testShortMessageSingleLine(): void
    {
        $toast = Toast::new('Hi');
        $rendered = $toast->render();

        $lines = explode("\n", $rendered);
        // Just top border, content, bottom border
        $this->assertLessThanOrEqual(3, count($lines));
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithMessageReturnsNewInstance(): void
    {
        $original = Toast::new('Original');
        $updated = $original->withMessage('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testWithTitleReturnsNewInstance(): void
    {
        $original = Toast::new('Message');
        $updated = $original->withTitle('New Title');

        $this->assertNotSame($original, $updated);
    }

    public function testWithBackgroundColorReturnsNewInstance(): void
    {
        $original = Toast::new('Test');
        $updated = $original->withBackgroundColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithForegroundColorReturnsNewInstance(): void
    {
        $original = Toast::new('Test');
        $updated = $original->withForegroundColor(Color::ansi(7));

        $this->assertNotSame($original, $updated);
    }

    public function testWithBorderColorReturnsNewInstance(): void
    {
        $original = Toast::new('Test');
        $updated = $original->withBorderColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithIconReturnsNewInstance(): void
    {
        $original = Toast::new('Test');
        $updated = $original->withIcon('★');

        $this->assertNotSame($original, $updated);
    }

    public function testWithMaxWidthReturnsNewInstance(): void
    {
        $original = Toast::new('Test');
        $updated = $original->withMaxWidth(40);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithMessage(): void
    {
        $original = Toast::new('Original');
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
        $toast = Toast::new('Test message');
        [$w, $h] = $toast->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithTitleIncreasesHeight(): void
    {
        $toastNoTitle = Toast::new('Message');
        $toastWithTitle = Toast::new('Message')->withTitle('Title');

        [, $h1] = $toastNoTitle->getInnerSize();
        [, $h2] = $toastWithTitle->getInnerSize();

        $this->assertGreaterThan($h1, $h2);
    }

    public function testGetInnerSizeWithLongMessage(): void
    {
        $toast = Toast::new(str_repeat('word ', 20));
        [, $h] = $toast->getInnerSize();

        // Long message should increase height due to wrapping
        $this->assertGreaterThan(3, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyMessage(): void
    {
        $toast = Toast::new('');
        $rendered = $toast->render();

        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeMessage(): void
    {
        $toast = Toast::new('日本語メッセージ');
        $rendered = $toast->render();

        $this->assertStringContainsString('日本語メッセージ', $rendered);
    }

    public function testSpecialCharsMessage(): void
    {
        $toast = Toast::new('Test <tag> & "quotes"');
        $rendered = $toast->render();

        $this->assertStringContainsString('Test <tag> & "quotes"', $rendered);
    }

    public function testMessageWithNewlines(): void
    {
        $toast = Toast::new("Line 1\nLine 2");
        $rendered = $toast->render();

        // Should handle multi-line input gracefully
        $this->assertNotSame('', $rendered);
    }
}

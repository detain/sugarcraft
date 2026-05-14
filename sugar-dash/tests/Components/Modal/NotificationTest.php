<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Modal;

use SugarCraft\Dash\Components\Modal\Notification;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testNotificationImplementsSizer(): void
    {
        $notification = Notification::new('Message');
        $this->assertInstanceOf(Sizer::class, $notification);
    }

    public function testNotificationImplementsItem(): void
    {
        $notification = Notification::new('Message');
        $this->assertInstanceOf(Item::class, $notification);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $notification = Notification::new('Message');
        $rendered = $notification->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsMessage(): void
    {
        $notification = Notification::new('Hello World');
        $rendered = $notification->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Box-drawing characters
    // ═══════════════════════════════════════════════════════════════

    public function testRenderContainsBoxCharacters(): void
    {
        $notification = Notification::new('Test');
        $rendered = $notification->render();

        $this->assertStringContainsString('╔', $rendered);
        $this->assertStringContainsString('╗', $rendered);
        $this->assertStringContainsString('║', $rendered);
        $this->assertStringContainsString('╚', $rendered);
        $this->assertStringContainsString('╝', $rendered);
        $this->assertStringContainsString('═', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Preset styles
    // ═══════════════════════════════════════════════════════════════

    public function testSuccessFactory(): void
    {
        $notification = Notification::success('Operation completed');
        $rendered = $notification->render();

        $this->assertStringContainsString('Operation completed', $rendered);
        $this->assertStringContainsString('Success', $rendered);
        $this->assertStringContainsString('✓', $rendered);
    }

    public function testWarningFactory(): void
    {
        $notification = Notification::warning('Be careful');
        $rendered = $notification->render();

        $this->assertStringContainsString('Be careful', $rendered);
        $this->assertStringContainsString('Warning', $rendered);
        $this->assertStringContainsString('⚠', $rendered);
    }

    public function testErrorFactory(): void
    {
        $notification = Notification::error('Something went wrong');
        $rendered = $notification->render();

        $this->assertStringContainsString('Something went wrong', $rendered);
        $this->assertStringContainsString('Error', $rendered);
        $this->assertStringContainsString('✕', $rendered);
    }

    public function testInfoFactory(): void
    {
        $notification = Notification::info('Here is some info');
        $rendered = $notification->render();

        $this->assertStringContainsString('Here is some info', $rendered);
        $this->assertStringContainsString('Info', $rendered);
        $this->assertStringContainsString('ℹ', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $notification = Notification::new('Test')
            ->withBorderColor(Color::ansi(9));
        $rendered = $notification->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBackgroundColorAddsAnsiCodes(): void
    {
        $notification = Notification::new('Test')
            ->withBackgroundColor(Color::ansi(8));
        $rendered = $notification->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTitleColorAddsAnsiCodes(): void
    {
        $notification = Notification::new('Test')
            ->withTitle('Title')
            ->withTitleColor(Color::ansi(12));
        $rendered = $notification->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testMessageColorAddsAnsiCodes(): void
    {
        $notification = Notification::new('Test')
            ->withMessageColor(Color::ansi(7));
        $rendered = $notification->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $notification = Notification::new('Test')
            ->withBorderColor(Color::ansi(9))
            ->withBackgroundColor(Color::ansi(8));
        $rendered = $notification->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Notification::new('Message');
        $resized = $original->setSize(50, 10);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocation(): void
    {
        $notification = Notification::new('Message')->setSize(60, 10);
        [$w, ] = $notification->getInnerSize();

        $this->assertGreaterThanOrEqual(60, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithMessageReturnsNewInstance(): void
    {
        $original = Notification::new('Original');
        $updated = $original->withMessage('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testWithTitleReturnsNewInstance(): void
    {
        $original = Notification::new('Message');
        $updated = $original->withTitle('New Title');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('New Title', $updated->render());
    }

    public function testWithBorderColorReturnsNewInstance(): void
    {
        $original = Notification::new('Message');
        $updated = $original->withBorderColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithBackgroundColorReturnsNewInstance(): void
    {
        $original = Notification::new('Message');
        $updated = $original->withBackgroundColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testWithTitleColorReturnsNewInstance(): void
    {
        $original = Notification::new('Message');
        $updated = $original->withTitleColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithMessageColorReturnsNewInstance(): void
    {
        $original = Notification::new('Message');
        $updated = $original->withMessageColor(Color::ansi(7));

        $this->assertNotSame($original, $updated);
    }

    public function testWithIconReturnsNewInstance(): void
    {
        $original = Notification::new('Message');
        $updated = $original->withIcon('★');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('★', $updated->render());
    }

    public function testOriginalUnchangedAfterWithMessage(): void
    {
        $original = Notification::new('Original');
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
        $notification = Notification::new('Message');
        [$w, $h] = $notification->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithTitleHasExtraLine(): void
    {
        $notificationNoTitle = Notification::new('Message');
        $notificationWithTitle = Notification::new('Message')->withTitle('Title');

        [, $hNoTitle] = $notificationNoTitle->getInnerSize();
        [, $hWithTitle] = $notificationWithTitle->getInnerSize();

        $this->assertGreaterThan($hNoTitle, $hWithTitle);
    }

    public function testGetInnerSizeWithIconShowsTitleLine(): void
    {
        $notificationNoIcon = Notification::new('Message')->withTitle('Title');
        $notificationWithIcon = Notification::new('Message')->withTitle('Title')->withIcon('★');

        [, $hNoIcon] = $notificationNoIcon->getInnerSize();
        [, $hWithIcon] = $notificationWithIcon->getInnerSize();

        // Both should be same height since icon is part of title line
        $this->assertSame($hNoIcon, $hWithIcon);
    }

    // ═══════════════════════════════════════════════════════════════
    // Word wrapping
    // ═══════════════════════════════════════════════════════════════

    public function testLongMessageGetsWrapped(): void
    {
        $notification = Notification::new(str_repeat('word ', 50));
        $rendered = $notification->render();

        // Should wrap to multiple lines
        $lines = explode("\n", $rendered);
        $this->assertGreaterThan(1, count($lines));
    }

    public function testEmptyTitleRendersWithoutTitleLine(): void
    {
        $notification = Notification::new('Content');
        $rendered = $notification->render();

        // Should only have top, content, and bottom borders - no separate title line
        $contentLineCount = 0;
        foreach (explode("\n", $rendered) as $line) {
            if (str_contains($line, 'Content')) {
                $contentLineCount++;
            }
        }
        $this->assertLessThanOrEqual(1, $contentLineCount);
    }

    public function testEmptyMessageRenders(): void
    {
        $notification = Notification::new('');
        $rendered = $notification->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testUnicodeTitle(): void
    {
        $notification = Notification::new('Message')->withTitle('タイトル');
        $rendered = $notification->render();

        $this->assertStringContainsString('タイトル', $rendered);
    }

    public function testUnicodeMessage(): void
    {
        $notification = Notification::new('こんにちは');
        $rendered = $notification->render();

        $this->assertStringContainsString('こんにちは', $rendered);
    }

    public function testSpecialCharsInMessage(): void
    {
        $notification = Notification::new('Test & <Tag> "Quote"');
        $rendered = $notification->render();

        $this->assertStringContainsString('Test & <Tag> "Quote"', $rendered);
    }

    public function testIconTruncatesToTwoChars(): void
    {
        $notification = Notification::new('Message')->withIcon('★★★');
        $rendered = $notification->render();

        // Should only show first 2 chars of icon
        $this->assertStringNotContainsString('★★★', $rendered);
    }
}

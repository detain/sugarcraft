<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Picture;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class PictureTest extends TestCase
{
    // Helper to strip ANSI codes for string comparison
    private function stripAnsi(string $output): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $output);
    }

    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testPictureImplementsSizer(): void
    {
        $picture = Picture::fromUrl('https://example.com/photo.jpg');
        $this->assertInstanceOf(Sizer::class, $picture);
    }

    public function testPictureImplementsItem(): void
    {
        $picture = Picture::fromUrl('https://example.com/photo.jpg');
        $this->assertInstanceOf(Item::class, $picture);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testFromUrlCreatesPictureWithUrl(): void
    {
        $picture = Picture::fromUrl('https://example.com/photo.jpg', 'A photo');
        $rendered = $picture->render();

        $this->assertNotSame('', $rendered);
    }

    public function testWithInitialsCreatesPictureWithFallback(): void
    {
        $picture = Picture::withInitials('John Doe');
        $rendered = $picture->render();

        // Should show initials "JD"
        $this->assertStringContainsString('JD', $this->stripAnsi($rendered));
    }

    public function testWithInitialsAndUrlShowsImage(): void
    {
        $picture = Picture::withInitials('Jane Doe', 'https://example.com/jane.jpg');
        $rendered = $picture->render();

        // Should show image pattern, not initials
        $this->assertStringContainsString('▓', $this->stripAnsi($rendered));
    }

    public function testWithIconCreatesPictureWithIconFallback(): void
    {
        $picture = Picture::withIcon('📷', 'https://example.com/photo.jpg');
        $rendered = $picture->render();

        $this->assertNotSame('', $rendered);
    }

    public function testWithEmojiCreatesPictureWithEmojiFallback(): void
    {
        $picture = Picture::withEmoji('🖼️', 'https://example.com/photo.jpg');
        $rendered = $picture->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $picture = Picture::fromUrl('https://example.com/photo.jpg');
        $rendered = $picture->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $picture = Picture::fromUrl('https://example.com/photo.jpg');
        $rendered = $picture->render();

        $this->assertStringContainsString('┌', $rendered);
        $this->assertStringContainsString('┐', $rendered);
    }

    public function testRenderWithCaption(): void
    {
        $picture = Picture::fromUrl('https://example.com/photo.jpg')
            ->withCaption('My vacation');
        $rendered = $picture->render();

        $this->assertStringContainsString('My vacation', $this->stripAnsi($rendered));
    }

    public function testZeroDimensionsRendersEmpty(): void
    {
        // withWidthHint clamps to minimum 1, so use setSize to get truly zero
        $picture = Picture::fromUrl('https://example.com/photo.jpg')
            ->setSize(0, 0);
        $rendered = $picture->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Fallback types
    // ═══════════════════════════════════════════════════════════════

    public function testFallbackTypeInitials(): void
    {
        $picture = new Picture(
            url: null,
            alt: null,
            caption: null,
            widthHint: 20,
            heightHint: 10,
            fallbackType: Picture::FALLBACK_INITIALS,
            fallbackValue: 'Alice Bob',
        );
        $rendered = $picture->render();

        // Should show "AB" for Alice Bob
        $this->assertStringContainsString('AB', $this->stripAnsi($rendered));
    }

    public function testFallbackTypeIcon(): void
    {
        $picture = new Picture(
            url: null,
            alt: null,
            caption: null,
            widthHint: 20,
            heightHint: 10,
            fallbackType: Picture::FALLBACK_ICON,
            fallbackValue: '📷',
        );
        $rendered = $picture->render();

        $this->assertStringContainsString('📷', $rendered);
    }

    public function testFallbackTypeEmoji(): void
    {
        $picture = new Picture(
            url: null,
            alt: null,
            caption: null,
            widthHint: 20,
            heightHint: 10,
            fallbackType: Picture::FALLBACK_EMOJI,
            fallbackValue: '🖼️',
        );
        $rendered = $picture->render();

        $this->assertStringContainsString('🖼️', $rendered);
    }

    public function testFallbackTypePlaceholder(): void
    {
        $picture = new Picture(
            url: null,
            alt: null,
            caption: null,
            widthHint: 20,
            heightHint: 10,
            fallbackType: Picture::FALLBACK_PLACEHOLDER,
            fallbackValue: null,
        );
        $rendered = $picture->render();

        $this->assertStringContainsString('[picture]', $this->stripAnsi($rendered));
    }

    public function testEmptyFallbackNameShowsQuestionMarks(): void
    {
        $picture = new Picture(
            url: null,
            alt: null,
            caption: null,
            widthHint: 20,
            heightHint: 10,
            fallbackType: Picture::FALLBACK_INITIALS,
            fallbackValue: '',
        );
        $rendered = $picture->render();

        $this->assertStringContainsString('??', $this->stripAnsi($rendered));
    }

    // ═══════════════════════════════════════════════════════════════
    // Caption rendering
    // ═══════════════════════════════════════════════════════════════

    public function testCaptionIncreasesHeight(): void
    {
        $withoutCaption = Picture::fromUrl('https://example.com/photo.jpg')
            ->withWidthHint(20)
            ->withHeightHint(5);
        [$w1, $h1] = $withoutCaption->getInnerSize();

        $withCaption = Picture::fromUrl('https://example.com/photo.jpg')
            ->withWidthHint(20)
            ->withHeightHint(5)
            ->withCaption('A caption');
        [$w2, $h2] = $withCaption->getInnerSize();

        $this->assertSame($h1 + 1, $h2);
    }

    public function testLongCaptionTruncated(): void
    {
        // Caption should be truncated with ellipsis to fit within width
        $picture = Picture::placeholder()
            ->withWidthHint(10)
            ->withHeightHint(5)
            ->withCaption('This is a very long caption that should be truncated');
        $rendered = $picture->render();

        $stripped = $this->stripAnsi($rendered);
        // Caption text should end with ellipsis and be truncated
        $this->assertStringContainsString('…', $stripped);
        // Should not contain the full uncaptioned text
        $this->assertStringNotContainsString('This is a very long caption that should be truncated', $stripped);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $picture = Picture::fromUrl('https://example.com/photo.jpg')
            ->withBorderColor(Color::ansi(9));
        $rendered = $picture->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBackgroundColorAddsAnsiCodes(): void
    {
        $picture = Picture::fromUrl('https://example.com/photo.jpg')
            ->withBackgroundColor(Color::ansi(9));
        $rendered = $picture->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testCaptionColorAddsAnsiCodes(): void
    {
        $picture = Picture::fromUrl('https://example.com/photo.jpg')
            ->withCaption('Test')
            ->withCaptionColor(Color::ansi(12));
        $rendered = $picture->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $picture = Picture::fromUrl('https://example.com/photo.jpg')
            ->withBackgroundColor(Color::ansi(9))
            ->withBorderColor(Color::ansi(1))
            ->withCaption('Test')
            ->withCaptionColor(Color::ansi(11));
        $rendered = $picture->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Picture::placeholder();
        $resized = $original->setSize(40, 20);

        $this->assertNotSame($original, $resized);
    }

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $picture = Picture::placeholder()
            ->withWidthHint(20)
            ->withHeightHint(10);
        [$w, $h] = $picture->getInnerSize();

        // Should be widthHint + 2 (borders) and heightHint + 2 (borders)
        $this->assertSame(22, $w);
        $this->assertSame(12, $h);
    }

    public function testGetInnerSizeWithCaption(): void
    {
        $picture = Picture::placeholder()
            ->withWidthHint(20)
            ->withHeightHint(10)
            ->withCaption('Test');
        [$w, $h] = $picture->getInnerSize();

        // Should have extra height for caption
        $this->assertSame(22, $w);
        $this->assertSame(13, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithUrlReturnsNewInstance(): void
    {
        $original = Picture::placeholder();
        $updated = $original->withUrl('https://example.com/new.jpg');

        $this->assertNotSame($original, $updated);
    }

    public function testWithAltReturnsNewInstance(): void
    {
        $original = Picture::placeholder();
        $updated = $original->withAlt('New alt');

        $this->assertNotSame($original, $updated);
    }

    public function testWithCaptionReturnsNewInstance(): void
    {
        $original = Picture::placeholder();
        $updated = $original->withCaption('New caption');

        $this->assertNotSame($original, $updated);
    }

    public function testWithWidthHintReturnsNewInstance(): void
    {
        $original = Picture::placeholder();
        $updated = $original->withWidthHint(50);

        $this->assertNotSame($original, $updated);
    }

    public function testWithHeightHintReturnsNewInstance(): void
    {
        $original = Picture::placeholder();
        $updated = $original->withHeightHint(50);

        $this->assertNotSame($original, $updated);
    }

    public function testWithFallbackTypeReturnsNewInstance(): void
    {
        $original = Picture::placeholder();
        $updated = $original->withFallbackType(Picture::FALLBACK_EMOJI);

        $this->assertNotSame($original, $updated);
    }

    public function testWithFallbackValueReturnsNewInstance(): void
    {
        $original = Picture::placeholder();
        $updated = $original->withFallbackValue('🖼️');

        $this->assertNotSame($original, $updated);
    }

    public function testWithBorderColorReturnsNewInstance(): void
    {
        $original = Picture::placeholder();
        $updated = $original->withBorderColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithBackgroundColorReturnsNewInstance(): void
    {
        $original = Picture::placeholder();
        $updated = $original->withBackgroundColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithCaptionColorReturnsNewInstance(): void
    {
        $original = Picture::placeholder();
        $updated = $original->withCaptionColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithers(): void
    {
        // Use placeholder mode (shows [picture]) - verify that calling withers doesn't change original
        $original = Picture::placeholder();
        $original->withUrl('https://example.com/new.jpg');
        $original->withCaption('New caption');
        $original->withWidthHint(100);
        $original->withHeightHint(100);

        // Original should be unchanged - still shows [picture] placeholder
        $this->assertStringContainsString('[picture]', $original->render());
        $this->assertStringNotContainsString('New caption', $original->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeWidthHintDefaultsToOne(): void
    {
        $picture = Picture::placeholder()->withWidthHint(-5);
        [$w, ] = $picture->getInnerSize();

        $this->assertGreaterThanOrEqual(1, $w);
    }

    public function testNegativeHeightHintDefaultsToOne(): void
    {
        $picture = Picture::placeholder()->withHeightHint(-5);
        [, $h] = $picture->getInnerSize();

        $this->assertGreaterThanOrEqual(1, $h);
    }

    public function testVeryLongFallbackValueHandled(): void
    {
        $picture = Picture::withInitials(str_repeat('A', 100));
        $rendered = $picture->render();

        // Should not crash
        $this->assertNotSame('', $rendered);
    }

    public function testMultiByteEmojiInFallback(): void
    {
        $picture = Picture::withEmoji('👨‍👩‍👧‍👦');
        $rendered = $picture->render();

        $this->assertNotSame('', $rendered);
    }
}

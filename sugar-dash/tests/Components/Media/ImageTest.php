<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Media;

use SugarCraft\Dash\Components\Media\Image;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ImageTest extends TestCase
{
    // Helper to strip ANSI codes for string comparison
    private function stripAnsi(string $output): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $output);
    }

    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testImageImplementsSizer(): void
    {
        $image = Image::fromUrl('https://example.com/image.png');
        $this->assertInstanceOf(Sizer::class, $image);
    }

    public function testImageImplementsItem(): void
    {
        $image = Image::fromUrl('https://example.com/image.png');
        $this->assertInstanceOf(Item::class, $image);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testFromUrlCreatesImageWithUrl(): void
    {
        $image = Image::fromUrl('https://example.com/photo.jpg', 'A photo');
        $rendered = $image->render();

        $this->assertNotSame('', $rendered);
    }

    public function testPlaceholderCreatesImageWithoutUrl(): void
    {
        $image = Image::placeholder('No image');
        $rendered = $image->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $image = Image::fromUrl('https://example.com/image.png');
        $rendered = $image->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $image = Image::fromUrl('https://example.com/image.png');
        $rendered = $image->render();

        // Should contain box-drawing characters
        $this->assertStringContainsString('┌', $rendered);
        $this->assertStringContainsString('┐', $rendered);
    }

    public function testRenderContainsAltText(): void
    {
        // Alt text is shown in placeholder mode (no URL)
        $image = Image::placeholder('My Photo');
        $rendered = $image->render();

        $this->assertStringContainsString('My Photo', $this->stripAnsi($rendered));
    }

    public function testZeroDimensionsRendersEmpty(): void
    {
        $image = Image::fromUrl('https://example.com/image.png')
            ->withMaxWidth(0)
            ->withMaxHeight(0);
        $rendered = $image->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Format variants
    // ═══════════════════════════════════════════════════════════════

    public function testBoxFormatRendersWithBorders(): void
    {
        $image = Image::fromUrl('https://example.com/image.png')
            ->withFormat(Image::FORMAT_BOX)
            ->withMaxWidth(20)
            ->withMaxHeight(10);
        $rendered = $image->render();

        $this->assertStringContainsString('┌', $rendered);
        $this->assertStringContainsString('┐', $rendered);
    }

    public function testBlockFormatRendersSolidFill(): void
    {
        $image = Image::placeholder('test')
            ->withFormat(Image::FORMAT_BLOCK)
            ->withMaxWidth(10)
            ->withMaxHeight(5);
        $rendered = $image->render();

        $this->assertStringContainsString('█', $rendered);
    }

    public function testAsciiFormatRendersArt(): void
    {
        $image = Image::placeholder('test')
            ->withFormat(Image::FORMAT_ASCII)
            ->withMaxWidth(20)
            ->withMaxHeight(10);
        $rendered = $image->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $image = Image::fromUrl('https://example.com/image.png')
            ->withBorderColor(Color::ansi(9));
        $rendered = $image->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBackgroundColorAddsAnsiCodes(): void
    {
        $image = Image::fromUrl('https://example.com/image.png')
            ->withBackgroundColor(Color::ansi(9));
        $rendered = $image->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $image = Image::fromUrl('https://example.com/image.png')
            ->withBackgroundColor(Color::ansi(9))
            ->withBorderColor(Color::ansi(1));
        $rendered = $image->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Image::placeholder();
        $resized = $original->setSize(40, 20);

        $this->assertNotSame($original, $resized);
    }

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $image = Image::placeholder()->withMaxWidth(30)->withMaxHeight(15);
        [$w, $h] = $image->getInnerSize();

        $this->assertSame(30, $w);
        $this->assertSame(15, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithUrlReturnsNewInstance(): void
    {
        $original = Image::placeholder();
        $updated = $original->withUrl('https://example.com/new.png');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('▓', $updated->render());
    }

    public function testWithAltReturnsNewInstance(): void
    {
        $original = Image::placeholder();
        $updated = $original->withAlt('New description');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('New description', $updated->render());
    }

    public function testWithMaxWidthReturnsNewInstance(): void
    {
        $original = Image::placeholder();
        $updated = $original->withMaxWidth(50);

        $this->assertNotSame($original, $updated);
    }

    public function testWithMaxHeightReturnsNewInstance(): void
    {
        $original = Image::placeholder();
        $updated = $original->withMaxHeight(50);

        $this->assertNotSame($original, $updated);
    }

    public function testWithFormatReturnsNewInstance(): void
    {
        $original = Image::placeholder();
        $updated = $original->withFormat(Image::FORMAT_BLOCK);

        $this->assertNotSame($original, $updated);
    }

    public function testWithBorderColorReturnsNewInstance(): void
    {
        $original = Image::placeholder();
        $updated = $original->withBorderColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithBackgroundColorReturnsNewInstance(): void
    {
        $original = Image::placeholder();
        $updated = $original->withBackgroundColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithers(): void
    {
        // In placeholder mode, alt text IS shown
        $original = Image::placeholder('Old');
        $original->withUrl('https://example.com/new.png');
        $original->withAlt('New');
        $original->withMaxWidth(100);
        $original->withMaxHeight(100);

        // Original should be unchanged - still shows 'Old' alt text
        $this->assertStringContainsString('Old', $original->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeWidthRendersEmpty(): void
    {
        $image = Image::placeholder()->withMaxWidth(-5);
        $rendered = $image->render();

        $this->assertSame('', $rendered);
    }

    public function testNegativeHeightRendersEmpty(): void
    {
        $image = Image::placeholder()->withMaxHeight(-5);
        $rendered = $image->render();

        $this->assertSame('', $rendered);
    }

    public function testVeryLongAltTextHandledGracefully(): void
    {
        // Very long alt text should not cause crashes
        $image = Image::placeholder(str_repeat('x', 100))
            ->withMaxWidth(10)
            ->withMaxHeight(5);
        $rendered = $image->render();

        // Should render without crashing
        $this->assertNotSame('', $rendered);
    }
}

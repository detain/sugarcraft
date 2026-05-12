<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Avatar;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class AvatarTest extends TestCase
{
    // Helper to strip ANSI codes for string comparison
    private function stripAnsi(string $output): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $output);
    }

    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testAvatarImplementsSizer(): void
    {
        $avatar = Avatar::fromName('John Doe');
        $this->assertInstanceOf(Sizer::class, $avatar);
    }

    public function testAvatarImplementsItem(): void
    {
        $avatar = Avatar::fromName('John Doe');
        $this->assertInstanceOf(Item::class, $avatar);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $avatar = Avatar::fromName('Test');
        $rendered = $avatar->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsInitials(): void
    {
        $avatar = Avatar::fromName('John Doe');
        $rendered = $avatar->render();

        $this->assertStringContainsString('JD', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Initials generation
    // ═══════════════════════════════════════════════════════════════

    public function testSingleNameInitials(): void
    {
        $avatar = Avatar::fromName('Alice');
        $rendered = $avatar->render();

        // Two characters from single name
        $this->assertMatchesRegularExpression('/A[A-Z]?/', $rendered);
    }

    public function testMultipleNamesInitials(): void
    {
        $avatar = Avatar::fromName('John Paul Smith');
        $rendered = $avatar->render();

        // First and last name initials: JS
        $this->assertStringContainsString('JS', $this->stripAnsi($rendered));
    }

    public function testEmptyNameFallback(): void
    {
        $avatar = Avatar::fromName('');
        $rendered = $avatar->render();

        $this->assertStringContainsString('??', $rendered);
    }

    public function testNullNameFallback(): void
    {
        $avatar = new Avatar(null, null, Avatar::SIZE_MEDIUM, null, null, null);
        $rendered = $avatar->render();

        $this->assertStringContainsString('??', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size variants
    // ═══════════════════════════════════════════════════════════════

    public function testSmallAvatarSize(): void
    {
        $avatar = Avatar::small('Test');
        $rendered = $avatar->render();

        // Small is 3 chars wide
        $this->assertGreaterThanOrEqual(3, mb_strlen($rendered, 'UTF-8'));
    }

    public function testMediumAvatarSize(): void
    {
        $avatar = Avatar::fromName('Test');
        $rendered = $avatar->render();

        // Medium is 5 chars wide
        $this->assertGreaterThanOrEqual(5, mb_strlen($rendered, 'UTF-8'));
    }

    public function testLargeAvatarSize(): void
    {
        $avatar = Avatar::large('Test');
        $rendered = $avatar->render();

        // Large is 7 chars wide
        $this->assertGreaterThanOrEqual(7, mb_strlen($rendered, 'UTF-8'));
    }

    public function testSizeToPixels(): void
    {
        $this->assertSame(3, Avatar::sizeToPixels(Avatar::SIZE_SMALL));
        $this->assertSame(5, Avatar::sizeToPixels(Avatar::SIZE_MEDIUM));
        $this->assertSame(7, Avatar::sizeToPixels(Avatar::SIZE_LARGE));
        $this->assertSame(5, Avatar::sizeToPixels(999)); // Default
    }

    // ═══════════════════════════════════════════════════════════════
    // Image avatars
    // ═══════════════════════════════════════════════════════════════

    public function testImageAvatarRendersPlaceholder(): void
    {
        $avatar = Avatar::withImage('https://example.com/photo.jpg');
        $rendered = $avatar->render();

        // Image avatars render as placeholder blocks
        $this->assertNotSame('', $rendered);
    }

    public function testImageAvatarWithName(): void
    {
        $avatar = Avatar::withImage('https://example.com/photo.jpg', 'John Doe');
        $rendered = $avatar->render();

        // Should show initials since name is provided
        $this->assertStringContainsString('JD', $this->stripAnsi($rendered));
    }

    // ═══════════════════════════════════════════════════════════════
    // Status indicator
    // ═══════════════════════════════════════════════════════════════

    public function testStatusOnline(): void
    {
        $avatar = Avatar::fromName('John')->withStatus('●');
        $rendered = $avatar->render();

        $this->assertStringContainsString('●', $rendered);
    }

    public function testStatusOffline(): void
    {
        $avatar = Avatar::fromName('John')->withStatus('○');
        $rendered = $avatar->render();

        $this->assertStringContainsString('○', $rendered);
    }

    public function testStatusAway(): void
    {
        $avatar = Avatar::fromName('John')->withStatus('◐');
        $rendered = $avatar->render();

        $this->assertStringContainsString('◐', $rendered);
    }

    public function testStatusBusy(): void
    {
        $avatar = Avatar::fromName('John')->withStatus('✕');
        $rendered = $avatar->render();

        $this->assertStringContainsString('✕', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBackgroundColorAddsAnsiCodes(): void
    {
        $avatar = Avatar::fromName('Test')
            ->withBackgroundColor(Color::ansi(9));
        $rendered = $avatar->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $avatar = Avatar::fromName('Test')
            ->withBackgroundColor(Color::ansi(9))
            ->withForegroundColor(Color::ansi(1));
        $rendered = $avatar->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Avatar::fromName('Test');
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithNameReturnsNewInstance(): void
    {
        $original = Avatar::fromName('Original');
        $updated = $original->withName('Updated');

        $this->assertNotSame($original, $updated);
    }

    public function testWithImageUrlReturnsNewInstance(): void
    {
        $original = Avatar::fromName('Test');
        $updated = $original->withImageUrl('https://example.com/img.jpg');

        $this->assertNotSame($original, $updated);
    }

    public function testWithSizeReturnsNewInstance(): void
    {
        $original = Avatar::fromName('Test');
        $updated = $original->withSize(Avatar::SIZE_LARGE);

        $this->assertNotSame($original, $updated);
    }

    public function testWithStatusReturnsNewInstance(): void
    {
        $original = Avatar::fromName('Test');
        $updated = $original->withStatus('●');

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithName(): void
    {
        $original = Avatar::fromName('Original');
        $original->withName('Changed');
        $rendered = $original->render();

        $stripped = $this->stripAnsi($rendered);
        // Avatar renders initials, not full name - 'Or' from 'Original'
        $this->assertStringContainsString('Or', $stripped);
        $this->assertStringNotContainsString('Changed', $stripped);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $avatar = Avatar::fromName('Test');
        [$w, $h] = $avatar->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithStatus(): void
    {
        $avatar = Avatar::fromName('Test')->withStatus('●');
        [$w, ] = $avatar->getInnerSize();

        // Should be wider with status indicator
        $avatarWithoutStatus = Avatar::fromName('Test');
        [$w2, ] = $avatarWithoutStatus->getInnerSize();

        $this->assertGreaterThan($w2, $w);
    }

    public function testGetInnerSizeWithWidthAllocation(): void
    {
        $avatar = Avatar::fromName('Hi')->setSize(20, 1);
        [$w, ] = $avatar->getInnerSize();

        $this->assertGreaterThanOrEqual(20, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongName(): void
    {
        $avatar = Avatar::fromName(str_repeat('A', 100));
        $rendered = $avatar->render();

        // Initials should only be 2 chars, but rendered output may include ANSI codes
        // Just verify it doesn't crash and is bounded
        $this->assertLessThan(200, mb_strlen($rendered, 'UTF-8'));
    }

    public function testUnicodeName(): void
    {
        $avatar = Avatar::fromName('日本語');
        $rendered = $avatar->render();

        // Should still render something
        $this->assertNotSame('', $rendered);
    }

    public function testNameWithExtraSpaces(): void
    {
        $avatar = Avatar::fromName('  John   Paul  ');
        $rendered = $avatar->render();

        // Should trim and get JP
        $this->assertStringContainsString('JP', $this->stripAnsi($rendered));
    }
}

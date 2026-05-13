<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\ASCIIBanner;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ASCIIBannerTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testASCIIBannerImplementsSizer(): void
    {
        $banner = ASCIIBanner::new('Test');
        $this->assertInstanceOf(Sizer::class, $banner);
    }

    public function testASCIIBannerImplementsItem(): void
    {
        $banner = ASCIIBanner::new('Test');
        $this->assertInstanceOf(Item::class, $banner);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $banner = ASCIIBanner::new('HELLO');
        $rendered = $banner->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsTitle(): void
    {
        $banner = ASCIIBanner::new('HELLO');
        $rendered = $banner->render();

        $this->assertStringContainsString('HELLO', $rendered);
    }

    public function testRenderHasBorderChars(): void
    {
        $banner = ASCIIBanner::new('Test');
        $rendered = $banner->render();

        // Should have border characters
        $this->assertMatchesRegularExpression('/[+\-]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testWithSubtitleFactory(): void
    {
        $banner = ASCIIBanner::withSubtitle('Title', 'Subtitle');
        $rendered = $banner->render();

        $this->assertStringContainsString('Title', $rendered);
        $this->assertStringContainsString('Subtitle', $rendered);
    }

    public function testClassicFactory(): void
    {
        $banner = ASCIIBanner::classic('Test');
        $rendered = $banner->render();

        $this->assertStringContainsString('+', $rendered);
    }

    public function testDoubleFactory(): void
    {
        $banner = ASCIIBanner::double('Test');
        $rendered = $banner->render();

        // Double style uses ╔╗╚╝
        $this->assertMatchesRegularExpression('/[╔╗╚╝]/', $rendered);
    }

    public function testBlockFactory(): void
    {
        $banner = ASCIIBanner::block('Test');
        $rendered = $banner->render();

        // Block style uses ┏┓┗┛
        $this->assertMatchesRegularExpression('/[┏┓┗┛]/', $rendered);
    }

    public function testStarsFactory(): void
    {
        $banner = ASCIIBanner::stars('Test');
        $rendered = $banner->render();

        $this->assertStringContainsString('*', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Style handling
    // ═══════════════════════════════════════════════════════════════

    public function testStyleDoubleUsesDoubleChars(): void
    {
        $banner = ASCIIBanner::new('Test')->withStyle('double');
        $rendered = $banner->render();

        $this->assertMatchesRegularExpression('/[╔╗╚╝]/', $rendered);
    }

    public function testStyleRoundedUsesRoundedChars(): void
    {
        $banner = ASCIIBanner::new('Test')->withStyle('rounded');
        $rendered = $banner->render();

        $this->assertMatchesRegularExpression('/[╭╮╰╯]/', $rendered);
    }

    public function testStyleBlockUsesBlockChars(): void
    {
        $banner = ASCIIBanner::new('Test')->withStyle('block');
        $rendered = $banner->render();

        $this->assertMatchesRegularExpression('/[┏┓┗┛]/', $rendered);
    }

    public function testStyleStarsUsesStars(): void
    {
        $banner = ASCIIBanner::new('Test')->withStyle('stars');
        $rendered = $banner->render();

        $this->assertStringContainsString('*', $rendered);
    }

    public function testStyleDashUsesDashes(): void
    {
        $banner = ASCIIBanner::new('Test')->withStyle('dash');
        $rendered = $banner->render();

        $this->assertStringContainsString('+', $rendered);
        $this->assertStringContainsString('-', $rendered);
    }

    public function testStyleClassicUsesPlainChars(): void
    {
        $banner = ASCIIBanner::new('Test')->withStyle('classic');
        $rendered = $banner->render();

        $this->assertStringContainsString('+', $rendered);
        $this->assertStringContainsString('-', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $banner = ASCIIBanner::new('Test')
            ->withBorderColor(Color::ansi(9));
        $rendered = $banner->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTextColorAddsAnsiCodes(): void
    {
        $banner = ASCIIBanner::new('Test')
            ->withTextColor(Color::ansi(12));
        $rendered = $banner->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Subtitle handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithSubtitle(): void
    {
        $banner = ASCIIBanner::new('Title')->withSubtitle('Subtitle');
        $rendered = $banner->render();

        $this->assertStringContainsString('Title', $rendered);
        $this->assertStringContainsString('Subtitle', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Padding handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithPadding(): void
    {
        $banner = ASCIIBanner::new('Content')->withPadding(2);
        $rendered = $banner->render();

        // Should have extra padding lines
        $lines = explode("\n", $rendered);
        $this->assertGreaterThan(5, count($lines));
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = ASCIIBanner::new('Test');
        $resized = $original->setSize(50, 20);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsOutput(): void
    {
        $banner = ASCIIBanner::new('Test content')->setSize(40, 10);
        $rendered = $banner->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithSubtitleReturnsNewInstance(): void
    {
        $original = ASCIIBanner::new('Title');
        $updated = $original->withSubtitle('Subtitle');

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithSubtitle(): void
    {
        $original = ASCIIBanner::new('Title');
        $original->withSubtitle('Changed');
        $rendered = $original->render();

        $this->assertStringNotContainsString('Changed', $rendered);
    }

    public function testWithBorderColorReturnsNewInstance(): void
    {
        $original = ASCIIBanner::new('Test');
        $updated = $original->withBorderColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithTextColorReturnsNewInstance(): void
    {
        $original = ASCIIBanner::new('Test');
        $updated = $original->withTextColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithStyleReturnsNewInstance(): void
    {
        $original = ASCIIBanner::new('Test');
        $updated = $original->withStyle('double');

        $this->assertNotSame($original, $updated);
    }

    public function testWithPaddingReturnsNewInstance(): void
    {
        $original = ASCIIBanner::new('Test');
        $updated = $original->withPadding(3);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $banner = ASCIIBanner::new('Test');
        [$w, $h] = $banner->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithSubtitleIncreasesHeight(): void
    {
        $bannerNoSub = ASCIIBanner::new('Title');
        $bannerWithSub = ASCIIBanner::withSubtitle('Title', 'Subtitle');

        [, $h1] = $bannerNoSub->getInnerSize();
        [, $h2] = $bannerWithSub->getInnerSize();

        $this->assertGreaterThan($h1, $h2);
    }

    public function testGetInnerSizeWithMorePadding(): void
    {
        $bannerSmall = ASCIIBanner::new('Title')->withPadding(1);
        $bannerLarge = ASCIIBanner::new('Title')->withPadding(3);

        [, $h1] = $bannerSmall->getInnerSize();
        [, $h2] = $bannerLarge->getInnerSize();

        $this->assertGreaterThan($h1, $h2);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testShortTitle(): void
    {
        $banner = ASCIIBanner::new('X');
        $rendered = $banner->render();

        $this->assertStringContainsString('X', $rendered);
    }

    public function testLongTitle(): void
    {
        $banner = ASCIIBanner::new('A very long banner title');
        $rendered = $banner->render();

        $this->assertStringContainsString('A very long banner title', $rendered);
    }

    public function testUnicodeTitle(): void
    {
        $banner = ASCIIBanner::new('日本語');
        $rendered = $banner->render();

        $this->assertStringContainsString('日本語', $rendered);
    }

    public function testNumbersAsTitle(): void
    {
        $banner = ASCIIBanner::new('12345');
        $rendered = $banner->render();

        $this->assertStringContainsString('12345', $rendered);
    }

    public function testStyleThick(): void
    {
        $banner = ASCIIBanner::new('Test')->withStyle('thick');
        $rendered = $banner->render();

        // Thick style uses █
        $this->assertStringContainsString('█', $rendered);
    }
}

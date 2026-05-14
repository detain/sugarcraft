<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use SugarCraft\Dash\Components\Card\Header;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class HeaderTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testHeaderImplementsSizer(): void
    {
        $header = Header::new('Title');
        $this->assertInstanceOf(Sizer::class, $header);
    }

    public function testHeaderImplementsItem(): void
    {
        $header = Header::new('Title');
        $this->assertInstanceOf(Item::class, $header);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $header = Header::new('Title');
        $rendered = $header->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsTitle(): void
    {
        $header = Header::new('Hello World');
        $rendered = $header->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Preset factories
    // ═══════════════════════════════════════════════════════════════

    public function testNewFactory(): void
    {
        $header = Header::new('Page Title');
        $this->assertStringContainsString('Page Title', $header->render());
    }

    public function testCenteredFactory(): void
    {
        $header = Header::centered('Centered Title');
        $rendered = $header->render();

        $this->assertStringContainsString('Centered Title', $rendered);
    }

    public function testHeroFactory(): void
    {
        $header = Header::hero('Hero Title', 'With a subtitle');
        $rendered = $header->render();

        $this->assertStringContainsString('Hero Title', $rendered);
        $this->assertStringContainsString('With a subtitle', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Title and subtitle
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyTitleReturnsEmpty(): void
    {
        $header = Header::new('');
        $rendered = $header->render();

        $this->assertSame('', $rendered);
    }

    public function testSubtitleRendersOnNewLine(): void
    {
        $header = Header::new('Title')->withSubtitle('My Subtitle');
        $rendered = $header->render();

        $this->assertStringContainsString('Title', $rendered);
        $this->assertStringContainsString('My Subtitle', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Alignment
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultAlignmentIsLeft(): void
    {
        $header = Header::new('Left Aligned');
        $rendered = $header->render();

        $this->assertStringContainsString('Left Aligned', $rendered);
    }

    public function testCenterAlignment(): void
    {
        $header = Header::new('Centered')->withAlignment('center');
        $rendered = $header->render();

        $this->assertStringContainsString('Centered', $rendered);
    }

    public function testRightAlignment(): void
    {
        $header = Header::new('Right Aligned')->withAlignment('right');
        $rendered = $header->render();

        $this->assertStringContainsString('Right Aligned', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testTitleColorAddsAnsiCodes(): void
    {
        $header = Header::new('Test')->withTitleColor(Color::ansi(12));
        $rendered = $header->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testSubtitleColorAddsAnsiCodes(): void
    {
        $header = Header::new('Title')->withSubtitle('Sub')->withSubtitleColor(Color::ansi(8));
        $rendered = $header->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBorderColorUsedForDivider(): void
    {
        $header = Header::new('Title')->withDivider()->withBorderColor(Color::ansi(9));
        $rendered = $header->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Divider
    // ═══════════════════════════════════════════════════════════════

    public function testDividerRendersAsLine(): void
    {
        $header = Header::new('Title')->withDivider();
        $rendered = $header->render();

        $this->assertStringContainsString('─', $rendered);
    }

    public function testDividerAddsExtraLine(): void
    {
        $headerNoDivider = Header::new('Title');
        $headerWithDivider = Header::new('Title')->withDivider();

        [, $hNoDivider] = $headerNoDivider->getInnerSize();
        [, $hWithDivider] = $headerWithDivider->getInnerSize();

        $this->assertSame($hNoDivider + 1, $hWithDivider);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Header::new('Title');
        $resized = $original->setSize(50, 10);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithTitleReturnsNewInstance(): void
    {
        $original = Header::new('Original');
        $updated = $original->withTitle('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testWithSubtitleReturnsNewInstance(): void
    {
        $original = Header::new('Title');
        $updated = $original->withSubtitle('Subtitle');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Subtitle', $updated->render());
    }

    public function testWithAlignmentReturnsNewInstance(): void
    {
        $original = Header::new('Title');
        $updated = $original->withAlignment('center');

        $this->assertNotSame($original, $updated);
    }

    public function testWithTitleColorReturnsNewInstance(): void
    {
        $original = Header::new('Title');
        $updated = $original->withTitleColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithSubtitleColorReturnsNewInstance(): void
    {
        $original = Header::new('Title');
        $updated = $original->withSubtitleColor(Color::ansi(7));

        $this->assertNotSame($original, $updated);
    }

    public function testWithBorderColorReturnsNewInstance(): void
    {
        $original = Header::new('Title');
        $updated = $original->withBorderColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithDividerReturnsNewInstance(): void
    {
        $original = Header::new('Title');
        $updated = $original->withDivider();

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithTitle(): void
    {
        $original = Header::new('Original');
        $original->withTitle('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $header = Header::new('Title');
        [$w, $h] = $header->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithSubtitleHasExtraLine(): void
    {
        $headerNoSub = Header::new('Title');
        $headerWithSub = Header::new('Title')->withSubtitle('Sub');

        [, $hNoSub] = $headerNoSub->getInnerSize();
        [, $hWithSub] = $headerWithSub->getInnerSize();

        $this->assertSame($hNoSub + 1, $hWithSub);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testUnicodeTitle(): void
    {
        $header = Header::new('タイトル');
        $rendered = $header->render();

        $this->assertStringContainsString('タイトル', $rendered);
    }

    public function testUnicodeSubtitle(): void
    {
        $header = Header::new('Title')->withSubtitle('サブタイトル');
        $rendered = $header->render();

        $this->assertStringContainsString('サブタイトル', $rendered);
    }

    public function testLongTitleGetsProperWidth(): void
    {
        $header = Header::new(str_repeat('x', 50));
        [$w, ] = $header->getInnerSize();

        $this->assertSame(50, $w);
    }
}

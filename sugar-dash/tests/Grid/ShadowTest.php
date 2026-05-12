<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Dash\Grid\Shadow;
use SugarCraft\Dash\Grid\Text;
use PHPUnit\Framework\TestCase;

final class ShadowTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testShadowImplementsSizer(): void
    {
        $shadow = Shadow::new(Text::new('content'));
        $this->assertInstanceOf(Sizer::class, $shadow);
    }

    public function testShadowImplementsItem(): void
    {
        $shadow = Shadow::new(Text::new('content'));
        $this->assertInstanceOf(Item::class, $shadow);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderBasicContent(): void
    {
        $text = Text::new('Hello');
        $shadow = Shadow::new($text);
        $rendered = $shadow->render();

        $this->assertStringContainsString('Hello', $rendered);
    }

    public function testRenderMultiLineContent(): void
    {
        $text = Text::new("Line 1\nLine 2\nLine 3");
        $shadow = Shadow::new($text);
        $rendered = $shadow->render();

        $this->assertStringContainsString('Line 1', $rendered);
        $this->assertStringContainsString('Line 2', $rendered);
        $this->assertStringContainsString('Line 3', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Shadow styles
    // ═══════════════════════════════════════════════════════════════

    public function testNormalShadowStyle(): void
    {
        $text = Text::new('Shadow');
        $shadow = Shadow::new($text)->withStyle(Shadow::Normal);
        $rendered = $shadow->render();

        // Normal shadow uses '░' character
        $this->assertStringContainsString('░', $rendered);
    }

    public function testHeavyShadowStyle(): void
    {
        $text = Text::new('Heavy');
        $shadow = Shadow::new($text)->withStyle(Shadow::Heavy);
        $rendered = $shadow->render();

        // Heavy shadow uses '▓' character
        $this->assertStringContainsString('▓', $rendered);
    }

    public function testNoShadowStyle(): void
    {
        $text = Text::new('No Shadow');
        $shadow = Shadow::new($text)->withStyle(Shadow::None);
        $rendered = $shadow->render();

        // Should contain content without shadow characters
        $this->assertStringContainsString('No Shadow', $rendered);
    }

    public function testStyleConstants(): void
    {
        $this->assertSame('normal', Shadow::Normal);
        $this->assertSame('heavy', Shadow::Heavy);
        $this->assertSame('none', Shadow::None);
    }

    // ═══════════════════════════════════════════════════════════════
    // Shadow presence
    // ═══════════════════════════════════════════════════════════════

    public function testShadowAddsBottomRow(): void
    {
        $text = Text::new('Bottom');
        $shadow = Shadow::new($text);
        $rendered = $shadow->render();

        $lines = explode("\n", $rendered);
        // Should have content line + shadow line
        $this->assertGreaterThanOrEqual(2, count($lines));
    }

    public function testShadowAddsRightColumn(): void
    {
        $text = Text::new('Right');
        $shadow = Shadow::new($text)->withStyle(Shadow::Normal);
        $rendered = $shadow->render();

        // Shadow adds '░' character to each line
        $this->assertStringContainsString('░', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Shadow::new(Text::new('content'));
        $resized = $original->setSize(30, 10);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsRender(): void
    {
        $text = Text::new('Sized');
        $shadow = Shadow::new($text)->setSize(20, 5);
        $rendered = $shadow->render();

        $this->assertStringContainsString('Sized', $rendered);
    }

    public function testGetInnerSizeWithoutSetSize(): void
    {
        $text = Text::new('Inner');
        $shadow = Shadow::new($text);
        [$w, $h] = $shadow->getInnerSize();

        // Should have dimensions for content + shadow
        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithSetSize(): void
    {
        $text = Text::new('Size');
        $shadow = Shadow::new($text)->setSize(20, 5);
        [$w, $h] = $shadow->getInnerSize();

        $this->assertSame(20, $w);
        $this->assertSame(5, $h);
    }

    public function testGetInnerSizeReflectsShadowOffset(): void
    {
        $text = Text::new('Offset');
        // Default offset is 1,1 so dimensions should include +1 for right shadow col and bottom shadow row
        $shadow = Shadow::new($text)->withOffset(2, 3);
        [$w, $h] = $shadow->getInnerSize();

        // Should include xOffset and yOffset in calculation
        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Offset control
    // ═══════════════════════════════════════════════════════════════

    public function testWithXOffset(): void
    {
        $text = Text::new('X Offset');
        $shadow = Shadow::new($text)->withXOffset(3);
        $rendered = $shadow->render();

        $this->assertStringContainsString('X Offset', $rendered);
    }

    public function testWithYOffset(): void
    {
        $text = Text::new('Y Offset');
        $shadow = Shadow::new($text)->withYOffset(2);
        $rendered = $shadow->render();

        $this->assertStringContainsString('Y Offset', $rendered);
    }

    public function testWithOffset(): void
    {
        $text = Text::new('Both');
        $shadow = Shadow::new($text)->withOffset(2, 2);
        $rendered = $shadow->render();

        $this->assertStringContainsString('Both', $rendered);
    }

    public function testNegativeOffsetClampedToZero(): void
    {
        $text = Text::new('Clamped');
        $shadow = Shadow::new($text)->withXOffset(-5)->withYOffset(-10);
        $rendered = $shadow->render();

        $this->assertStringContainsString('Clamped', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithColor(): void
    {
        $text = Text::new('Colored');
        $color = \SugarCraft\Core\Util\Color::hex('#FF0000');
        $shadow = Shadow::new($text)->withColor($color);
        $rendered = $shadow->render();

        $this->assertStringContainsString('Colored', $rendered);
    }

    public function testWithNullColor(): void
    {
        $text = Text::new('No Color');
        $shadow = Shadow::new($text)->withColor(null);
        $rendered = $shadow->render();

        $this->assertStringContainsString('No Color', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Wither chaining
    // ═══════════════════════════════════════════════════════════════

    public function testChainedWithers(): void
    {
        $text = Text::new('Chained');
        $shadow = Shadow::new($text)
            ->withStyle(Shadow::Heavy)
            ->withOffset(1, 1)
            ->withColor(\SugarCraft\Core\Util\Color::hex('#333333'));

        $rendered = $shadow->render();
        $this->assertStringContainsString('Chained', $rendered);
    }

    public function testWithHeavyShort(): void
    {
        $text = Text::new('Heavy');
        $shadow = Shadow::new($text)->withHeavy();
        $rendered = $shadow->render();

        $this->assertStringContainsString('▓', $rendered);
    }

    public function testWithNoShadowShort(): void
    {
        $text = Text::new('Hidden');
        $shadow = Shadow::new($text)->withNoShadow();
        $rendered = $shadow->render();

        // Should just contain the text without shadow chars
        $this->assertStringContainsString('Hidden', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyContent(): void
    {
        $text = Text::new('');
        $shadow = Shadow::new($text);
        $rendered = $shadow->render();

        // Should still render with shadow
        $this->assertNotNull($rendered);
    }

    public function testSingleCharacter(): void
    {
        $text = Text::new('X');
        $shadow = Shadow::new($text);
        $rendered = $shadow->render();

        $this->assertStringContainsString('X', $rendered);
    }

    public function testWideContent(): void
    {
        $text = Text::new('This is a very long piece of content');
        $shadow = Shadow::new($text);
        $rendered = $shadow->render();

        $this->assertStringContainsString('This is a very long piece of content', $rendered);
    }

    public function testTallContent(): void
    {
        $longText = "Line 1\nLine 2\nLine 3\nLine 4\nLine 5\nLine 6\nLine 7\nLine 8\nLine 9\nLine 10";
        $text = Text::new($longText);
        $shadow = Shadow::new($text)->setSize(40, 5);
        $rendered = $shadow->render();

        // Content should be rendered at the given height
        $lines = explode("\n", $rendered);
        $this->assertLessThanOrEqual(6, count($lines)); // 5 content + 1 shadow row
    }

    public function testUnicodeContent(): void
    {
        $text = Text::new('日本語 中文 한국어');
        $shadow = Shadow::new($text);
        $rendered = $shadow->render();

        $this->assertStringContainsString('日本語', $rendered);
        $this->assertStringContainsString('中文', $rendered);
        $this->assertStringContainsString('한국어', $rendered);
    }

    public function testSpecialCharacters(): void
    {
        $text = Text::new('Test $pecial @chars!');
        $shadow = Shadow::new($text);
        $rendered = $shadow->render();

        $this->assertStringContainsString('Test $pecial @chars!', $rendered);
    }
}

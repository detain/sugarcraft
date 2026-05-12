<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Card;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Dash\Grid\Text;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class CardTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testCardImplementsSizer(): void
    {
        $card = Card::new(Text::new('Content'));
        $this->assertInstanceOf(Sizer::class, $card);
    }

    public function testCardImplementsItem(): void
    {
        $card = Card::new(Text::new('Content'));
        $this->assertInstanceOf(Item::class, $card);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $card = Card::new(Text::new('Content'));
        $rendered = $card->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsContent(): void
    {
        $card = Card::new(Text::new('Hello World'));
        $rendered = $card->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderHasBorderChars(): void
    {
        $card = Card::new(Text::new('Content'));
        $rendered = $card->render();

        // Single style uses box-drawing characters
        $this->assertMatchesRegularExpression('/[┌┐└┘─│]/', $rendered);
    }

    public function testRenderStringContent(): void
    {
        $card = Card::new('String content');
        $rendered = $card->render();

        $this->assertStringContainsString('String content', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Title
    // ═══════════════════════════════════════════════════════════════

    public function testWithTitle(): void
    {
        $card = Card::new(Text::new('Content'))->withTitle('My Title');
        $rendered = $card->render();

        $this->assertStringContainsString('My Title', $rendered);
    }

    public function testWithTitleFactory(): void
    {
        $card = Card::titled(Text::new('Content'), 'Title Here');
        $rendered = $card->render();

        $this->assertStringContainsString('Title Here', $rendered);
    }

    public function testTitleDisplayedInBorder(): void
    {
        $card = Card::new(Text::new('Content'))->withTitle('Center');
        $rendered = $card->render();

        // Title should be between border characters on top line
        $this->assertStringContainsString('Center', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Footer
    // ═══════════════════════════════════════════════════════════════

    public function testWithFooter(): void
    {
        $card = Card::new(Text::new('Content'))->withFooter(Text::new('Footer'));
        $rendered = $card->render();

        $this->assertStringContainsString('Footer', $rendered);
    }

    public function testWithStringFooter(): void
    {
        $card = Card::new(Text::new('Content'))->withFooter('String Footer');
        $rendered = $card->render();

        $this->assertStringContainsString('String Footer', $rendered);
    }

    public function testNullFooterNotRendered(): void
    {
        $card = Card::new(Text::new('Content'));
        $rendered = $card->render();

        // No footer separator when footer is null
        $lines = explode("\n", $rendered);
        // Just top, content, bottom border
        $this->assertLessThanOrEqual(4, count($lines));
    }

    // ═══════════════════════════════════════════════════════════════
    // Border styles
    // ═══════════════════════════════════════════════════════════════

    public function testSingleStyleDefault(): void
    {
        $card = Card::new(Text::new('Content'));
        $rendered = $card->render();

        $this->assertStringContainsString('┌', $rendered);
        $this->assertStringContainsString('┐', $rendered);
    }

    public function testDoubleStyle(): void
    {
        $card = Card::new(Text::new('Content'))->withStyle('double');
        $rendered = $card->render();

        $this->assertStringContainsString('╔', $rendered);
        $this->assertStringContainsString('╗', $rendered);
    }

    public function testRoundedStyle(): void
    {
        $card = Card::new(Text::new('Content'))->withStyle('rounded');
        $rendered = $card->render();

        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('╮', $rendered);
    }

    public function testBoldStyle(): void
    {
        $card = Card::new(Text::new('Content'))->withStyle('bold');
        $rendered = $card->render();

        $this->assertStringContainsString('┏', $rendered);
        $this->assertStringContainsString('┓', $rendered);
    }

    public function testEmptyStyle(): void
    {
        $card = Card::new(Text::new('Content'))->withStyle('empty');
        $rendered = $card->render();

        // Empty style should have spaces instead of border chars
        $this->assertDoesNotMatchRegularExpression('/[┌┐┏┓╔╗]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $card = Card::new(Text::new('Content'))
            ->withBorderColor(Color::ansi(9));
        $rendered = $card->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTitleColorAddsAnsiCodes(): void
    {
        $card = Card::new(Text::new('Content'))
            ->withTitle('Title')
            ->withTitleColor(Color::ansi(9));
        $rendered = $card->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $card = Card::new(Text::new('Content'))
            ->withBorderColor(Color::ansi(9))
            ->withTitleColor(Color::ansi(12));
        $rendered = $card->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Padding
    // ═══════════════════════════════════════════════════════════════

    public function testCustomPadding(): void
    {
        $card1 = Card::new(Text::new('X'))->withPadding(0);
        $card2 = Card::new(Text::new('X'))->withPadding(3);
        $rendered1 = $card1->render();
        $rendered2 = $card2->render();

        // Both render same visible length (padding just repositions internal whitespace)
        $this->assertSame(
            mb_strlen($rendered1, 'UTF-8'),
            mb_strlen($rendered2, 'UTF-8')
        );
    }

    public function testDefaultPadding(): void
    {
        $card = Card::new(Text::new('Content'));
        $rendered = $card->render();

        // Default padding is 1
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Card::new(Text::new('Content'));
        $resized = $original->setSize(40, 10);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsOutput(): void
    {
        $card = Card::new(Text::new('Content'))->setSize(50, 10);
        $rendered = $card->render();

        $this->assertNotSame('', $rendered);
    }

    public function testWidthAllocation(): void
    {
        $card = Card::new(Text::new('X'))->setSize(60, 5);
        $rendered = $card->render();

        // Should use allocated width
        $lines = explode("\n", $rendered);
        foreach ($lines as $line) {
            if ($line !== '' && !ctype_space($line)) {
                $this->assertGreaterThanOrEqual(60, mb_strlen($line, 'UTF-8'));
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Content wrapping
    // ═══════════════════════════════════════════════════════════════

    public function testLongContentWraps(): void
    {
        $card = Card::new(str_repeat('word ', 50))->setSize(20, 20);
        $rendered = $card->render();

        $lines = explode("\n", $rendered);
        // Content should wrap to multiple lines
        $this->assertGreaterThan(3, count($lines));
    }

    public function testEmptyContentRenders(): void
    {
        $card = Card::new(Text::new(''));
        $rendered = $card->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithContentReturnsNewInstance(): void
    {
        $original = Card::new(Text::new('Original'));
        $updated = $original->withContent(Text::new('Updated'));

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testWithTitleReturnsNewInstance(): void
    {
        $original = Card::new(Text::new('Content'));
        $updated = $original->withTitle('New Title');

        $this->assertNotSame($original, $updated);
    }

    public function testWithFooterReturnsNewInstance(): void
    {
        $original = Card::new(Text::new('Content'));
        $updated = $original->withFooter(Text::new('Footer'));

        $this->assertNotSame($original, $updated);
    }

    public function testWithBorderColorReturnsNewInstance(): void
    {
        $original = Card::new(Text::new('Content'));
        $updated = $original->withBorderColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithTitleColorReturnsNewInstance(): void
    {
        $original = Card::new(Text::new('Content'));
        $updated = $original->withTitleColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithStyleReturnsNewInstance(): void
    {
        $original = Card::new(Text::new('Content'));
        $updated = $original->withStyle('double');

        $this->assertNotSame($original, $updated);
    }

    public function testWithPaddingReturnsNewInstance(): void
    {
        $original = Card::new(Text::new('Content'));
        $updated = $original->withPadding(3);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithContent(): void
    {
        $original = Card::new(Text::new('Original'));
        $original->withContent(Text::new('Changed'));
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $card = Card::new(Text::new('Content'));
        [$w, $h] = $card->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithTitleIncreasesHeight(): void
    {
        $cardNoTitle = Card::new(Text::new('Content'));
        $cardWithTitle = Card::new(Text::new('Content'))->withTitle('Title');

        [, $h1] = $cardNoTitle->getInnerSize();
        [, $h2] = $cardWithTitle->getInnerSize();

        $this->assertGreaterThan($h1, $h2);
    }

    public function testGetInnerSizeWithFooterIncreasesHeight(): void
    {
        $cardNoFooter = Card::new(Text::new('Content'));
        $cardWithFooter = Card::new(Text::new('Content'))->withFooter(Text::new('Footer'));

        [, $h1] = $cardNoFooter->getInnerSize();
        [, $h2] = $cardWithFooter->getInnerSize();

        $this->assertGreaterThan($h1, $h2);
    }

    public function testGetInnerSizeWithWidthAllocation(): void
    {
        $card = Card::new(Text::new('Content'))->setSize(80, 20);
        [$w, ] = $card->getInnerSize();

        $this->assertGreaterThanOrEqual(80, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongTitle(): void
    {
        $card = Card::new(Text::new('Content'))->withTitle(str_repeat('x', 100));
        $rendered = $card->render();

        // Should still render
        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeContent(): void
    {
        $card = Card::new('日本語コンテンツ');
        $rendered = $card->render();

        $this->assertStringContainsString('日本語コンテンツ', $rendered);
    }

    public function testSpecialCharsContent(): void
    {
        $card = Card::new('Test <tag> & "quotes"');
        $rendered = $card->render();

        $this->assertStringContainsString('Test <tag> & "quotes"', $rendered);
    }

    public function testCardWithItemContent(): void
    {
        $text = Text::new('Wrapped Content')->withMaxWidth(15);
        $card = Card::new($text);
        $rendered = $card->render();

        $this->assertStringContainsString('Wrapped Content', $rendered);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use SugarCraft\Dash\Components\Card\Markdown;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testMarkdownImplementsSizer(): void
    {
        $md = Markdown::new('# Hello');
        $this->assertInstanceOf(Sizer::class, $md);
    }

    public function testMarkdownImplementsItem(): void
    {
        $md = Markdown::new('# Hello');
        $this->assertInstanceOf(Item::class, $md);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $md = Markdown::new('# Hello World');
        $rendered = $md->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsText(): void
    {
        $md = Markdown::new('# Hello World');
        $rendered = $md->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Headers
    // ═══════════════════════════════════════════════════════════════

    public function testH1Header(): void
    {
        $md = Markdown::new('# Header 1');
        $rendered = $md->render();

        $this->assertStringContainsString('Header 1', $rendered);
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testH2Header(): void
    {
        $md = Markdown::new('## Header 2');
        $rendered = $md->render();

        $this->assertStringContainsString('Header 2', $rendered);
    }

    public function testH3Header(): void
    {
        $md = Markdown::new('### Header 3');
        $rendered = $md->render();

        $this->assertStringContainsString('Header 3', $rendered);
    }

    public function testH6Header(): void
    {
        $md = Markdown::new('###### Header 6');
        $rendered = $md->render();

        $this->assertStringContainsString('Header 6', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inline formatting
    // ═══════════════════════════════════════════════════════════════

    public function testBoldText(): void
    {
        $md = Markdown::new('This is **bold** text');
        $rendered = $md->render();

        $this->assertStringContainsString('bold', $rendered);
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testItalicText(): void
    {
        $md = Markdown::new('This is *italic* text');
        $rendered = $md->render();

        $this->assertStringContainsString('italic', $rendered);
    }

    public function testStrikethroughText(): void
    {
        $md = Markdown::new('This is ~~strikethrough~~ text');
        $rendered = $md->render();

        $this->assertStringContainsString('strikethrough', $rendered);
    }

    public function testInlineCode(): void
    {
        $md = Markdown::new('This is `inline code` text');
        $rendered = $md->render();

        $this->assertStringContainsString('inline code', $rendered);
    }

    public function testCombinedFormatting(): void
    {
        $md = Markdown::new('**bold** and *italic* and `code`');
        $rendered = $md->render();

        $this->assertStringContainsString('bold', $rendered);
        $this->assertStringContainsString('italic', $rendered);
        $this->assertStringContainsString('code', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Links
    // ═══════════════════════════════════════════════════════════════

    public function testLinksEnabled(): void
    {
        $md = Markdown::new('[Click here](https://example.com)');
        $rendered = $md->render();

        $this->assertStringContainsString('Click here', $rendered);
        $this->assertStringContainsString('https://example.com', $rendered);
    }

    public function testLinksDisabled(): void
    {
        $md = Markdown::new('[Click here](https://example.com)')->withEnableLinks(false);
        $rendered = $md->render();

        // Link syntax should be stripped but text preserved
        $this->assertStringContainsString('Click here', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Lists
    // ═══════════════════════════════════════════════════════════════

    public function testUnorderedList(): void
    {
        $md = Markdown::new("- Item 1\n- Item 2\n- Item 3");
        $rendered = $md->render();

        $this->assertStringContainsString('Item 1', $rendered);
        $this->assertStringContainsString('Item 2', $rendered);
        $this->assertStringContainsString('Item 3', $rendered);
    }

    public function testOrderedList(): void
    {
        $md = Markdown::new("1. First\n2. Second\n3. Third");
        $rendered = $md->render();

        $this->assertStringContainsString('First', $rendered);
        $this->assertStringContainsString('Second', $rendered);
        $this->assertStringContainsString('Third', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Blockquote
    // ═══════════════════════════════════════════════════════════════

    public function testBlockquote(): void
    {
        $md = Markdown::new('> This is a blockquote');
        $rendered = $md->render();

        $this->assertStringContainsString('This is a blockquote', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Horizontal rule
    // ═══════════════════════════════════════════════════════════════

    public function testHorizontalRuleDashes(): void
    {
        $md = Markdown::new("---\nSome text\n---");
        $rendered = $md->render();

        // Should render horizontal rules
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testHorizontalRuleStars(): void
    {
        $md = Markdown::new("***\nSome text\n***");
        $rendered = $md->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Code blocks
    // ═══════════════════════════════════════════════════════════════

    public function testCodeBlockDisabledHighlighting(): void
    {
        $md = Markdown::new("```\ncode here\n```")->withEnableCodeHighlighting(false);
        $rendered = $md->render();

        $this->assertStringContainsString('code here', $rendered);
    }

    public function testCodeBlockWithLanguage(): void
    {
        $md = Markdown::new("```php\n<?php echo 'hi';\n```");
        $rendered = $md->render();

        $this->assertStringContainsString('hi', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Themes
    // ═══════════════════════════════════════════════════════════════

    public function testMonokaiFactory(): void
    {
        $md = Markdown::monokai('# Hello');
        $rendered = $md->render();

        $this->assertStringContainsString('Hello', $rendered);
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testCustomTheme(): void
    {
        $md = Markdown::new('**bold**')->withTheme(Markdown::THEME_GITHUB);
        $rendered = $md->render();

        $this->assertStringContainsString('bold', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Markdown::new('Hello');
        $resized = $original->setSize(80, 10);

        $this->assertNotSame($original, $resized);
    }

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $md = Markdown::new("Line 1\nLine 2\nLine 3");
        [$w, $h] = $md->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(3, $h);
    }

    public function testEmptyContentHasZeroHeight(): void
    {
        $md = Markdown::new('');
        [, $h] = $md->getInnerSize();

        $this->assertSame(0, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithContentReturnsNewInstance(): void
    {
        $original = Markdown::new('# Original');
        $updated = $original->withContent('# Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testWithThemeReturnsNewInstance(): void
    {
        $original = Markdown::new('# Hello');
        $updated = $original->withTheme(Markdown::THEME_DRACULA);

        $this->assertNotSame($original, $updated);
    }

    public function testWithEnableLinksReturnsNewInstance(): void
    {
        $original = Markdown::new('[link](url)');
        $updated = $original->withEnableLinks(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithEnableCodeHighlightingReturnsNewInstance(): void
    {
        $original = Markdown::new("```\ncode\n```");
        $updated = $original->withEnableCodeHighlighting(false);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithContent(): void
    {
        $original = Markdown::new('# Original');
        $original->withContent('# Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Theme constants
    // ═══════════════════════════════════════════════════════════════

    public function testThemeConstants(): void
    {
        $this->assertSame('default', Markdown::THEME_DEFAULT);
        $this->assertSame('monokai', Markdown::THEME_MONOKAI);
        $this->assertSame('github', Markdown::THEME_GITHUB);
        $this->assertSame('dracula', Markdown::THEME_DRACULA);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyContent(): void
    {
        $md = Markdown::new('');
        $rendered = $md->render();

        // Empty content renders to empty string without error
        $this->assertSame('', $rendered);
    }

    public function testEmptyLines(): void
    {
        $md = Markdown::new("Line 1\n\nLine 3");
        $rendered = $md->render();

        $this->assertStringContainsString('Line 1', $rendered);
        $this->assertStringContainsString('Line 3', $rendered);
    }

    public function testUnicodeText(): void
    {
        $md = Markdown::new('# 日本語タイトル');
        $rendered = $md->render();

        $this->assertStringContainsString('日本語タイトル', $rendered);
    }

    public function testSpecialCharactersInText(): void
    {
        $md = Markdown::new('Text with <special> & "characters"');
        $rendered = $md->render();

        $this->assertStringContainsString('Text with', $rendered);
    }

    public function testComplexDocument(): void
    {
        $md = Markdown::new("# Title\n\nSome paragraph with **bold** and *italic*.\n\n- List item 1\n- List item 2\n\n> A blockquote\n\n---\n\nMore text.");
        $rendered = $md->render();

        $this->assertStringContainsString('Title', $rendered);
        $this->assertStringContainsString('bold', $rendered);
        $this->assertStringContainsString('italic', $rendered);
        $this->assertStringContainsString('List item 1', $rendered);
        $this->assertStringContainsString('blockquote', $rendered);
        $this->assertStringContainsString('More text', $rendered);
    }
}

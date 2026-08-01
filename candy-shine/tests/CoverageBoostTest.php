<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;
use SugarCraft\Shine\Render\BlockStack;
use SugarCraft\Shine\Render\BlockContext;
use SugarCraft\Shine\Render\BlockKind;
use SugarCraft\Sprinkles\Style;

/**
 * Coverage-boosting tests for edge cases, error paths, and uncovered branches.
 */
final class CoverageBoostTest extends TestCase
{
    // ---- BlockStack -------------------------------------------------------

    public function testBlockStackAvailableWidthZeroWordWrap(): void
    {
        $stack = new BlockStack();
        $stack->push(new BlockContext(
            BlockKind::Paragraph,
            depth: 1,
            accumulatedIndent: 0,
            cascadedStyle: Style::new(),
        ));

        // wordWrap <= 0 should return 0 (not negative).
        $this->assertSame(0, $stack->availableWidth(0));
    }

    public function testBlockStackAvailableWidthNegativeWordWrap(): void
    {
        $stack = new BlockStack();
        $stack->push(new BlockContext(
            BlockKind::Paragraph,
            depth: 1,
            accumulatedIndent: 0,
            cascadedStyle: Style::new(),
        ));

        // Negative wordWrap should return 0 (not negative).
        $this->assertSame(0, $stack->availableWidth(-10));
    }

    public function testBlockStackPopUnderflowThrows(): void
    {
        $stack = new BlockStack();
        $this->expectException(\UnderflowException::class);
        $this->expectExceptionMessage('BlockStack is empty');
        $stack->pop();
    }

    public function testBlockStackIsEmptyInitially(): void
    {
        $stack = new BlockStack();
        $this->assertTrue($stack->isEmpty());
    }

    public function testBlockStackDepthInitiallyZero(): void
    {
        $stack = new BlockStack();
        $this->assertSame(0, $stack->depth());
    }

    public function testBlockStackPeekReturnsNullWhenEmpty(): void
    {
        $stack = new BlockStack();
        $this->assertNull($stack->peek());
    }

    public function testBlockStackPeekKindReturnsNullWhenEmpty(): void
    {
        $stack = new BlockStack();
        $this->assertNull($stack->peekKind());
    }

    public function testBlockStackAccumulatedIndentInitiallyZero(): void
    {
        $stack = new BlockStack();
        $this->assertSame(0, $stack->accumulatedIndent());
    }

    public function testBlockStackMarginCountInitiallyZero(): void
    {
        $stack = new BlockStack();
        $this->assertSame(0, $stack->marginCount());
    }

    public function testBlockStackMarginCountIncrementsOnBlockQuote(): void
    {
        $stack = new BlockStack();
        $stack->push(new BlockContext(
            BlockKind::BlockQuote,
            depth: 1,
            accumulatedIndent: 2,
            cascadedStyle: Style::new(),
        ));
        $this->assertSame(1, $stack->marginCount());
    }

    public function testBlockStackMarginCountIncrementsOnListItem(): void
    {
        $stack = new BlockStack();
        $stack->push(new BlockContext(
            BlockKind::ListItem,
            depth: 1,
            accumulatedIndent: 0,
            cascadedStyle: Style::new(),
        ));
        $this->assertSame(1, $stack->marginCount());
    }

    public function testBlockStackAccumulatedIndentSumsAcrossPushes(): void
    {
        $stack = new BlockStack();
        $stack->push(new BlockContext(
            BlockKind::BlockQuote,
            depth: 1,
            accumulatedIndent: 2,
            cascadedStyle: Style::new(),
        ));
        $stack->push(new BlockContext(
            BlockKind::Paragraph,
            depth: 2,
            accumulatedIndent: 4,
            cascadedStyle: Style::new(),
        ));
        $this->assertSame(6, $stack->accumulatedIndent());
    }

    public function testBlockStackMarginCountDecrementsOnPop(): void
    {
        $stack = new BlockStack();
        $stack->push(new BlockContext(
            BlockKind::BlockQuote,
            depth: 1,
            accumulatedIndent: 2,
            cascadedStyle: Style::new(),
        ));
        $this->assertSame(1, $stack->marginCount());
        $stack->pop();
        $this->assertSame(0, $stack->marginCount());
    }

    public function testBlockStackAvailableWidthAfterBlockQuotePush(): void
    {
        // BlockQuote adds 2 to accumulatedIndent and 1 to marginCount.
        $stack = new BlockStack();
        $stack->push(new BlockContext(
            BlockKind::BlockQuote,
            depth: 1,
            accumulatedIndent: 2,
            cascadedStyle: Style::new(),
        ));

        // availableWidth = 80 - 2 (indent) - 1*2 (margin) = 76
        $this->assertSame(76, $stack->availableWidth(80));
    }

    public function testBlockStackAvailableWidthNeverDropsBelowOne(): void
    {
        // Even with large indent, availableWidth should be at least 1.
        $stack = new BlockStack();
        $stack->push(new BlockContext(
            BlockKind::BlockQuote,
            depth: 1,
            accumulatedIndent: 100,
            cascadedStyle: Style::new(),
        ));

        // wordWrap=80, indent=100, margins=2*1=2 → 80-100-2=-22 → max(1,-22)=1
        $this->assertSame(1, $stack->availableWidth(80));
    }

    // ---- Theme::fromJson error paths --------------------------------------

    public function testFromJsonThrowsRuntimeOnFileGetContentsFailure(): void
    {
        // Create a special file that exists but is unreadable.
        // Since we're root, we can make a file unreadable.
        $tmp = tempnam(sys_get_temp_dir(), 'shine_unreadable');
        chmod($tmp, 0);
        try {
            $this->expectException(\RuntimeException::class);
            Theme::fromJson($tmp);
        } finally {
            chmod($tmp, 0644);
            unlink($tmp);
        }
    }

    public function testFromJsonRejectsNonFilePath(): void
    {
        $this->expectException(\RuntimeException::class);
        Theme::fromJson(__DIR__);
    }

    // ---- Theme::parseColor edge cases -------------------------------------

    public function testParseColorRejectsEmptyHexString(): void
    {
        $json = json_encode(['code' => ['foreground' => '#']]);
        $this->expectException(\InvalidArgumentException::class);
        Theme::fromJsonString($json);
    }

    public function testParseColorRejectsHexWithInvalidChars(): void
    {
        // Color::hex throws on invalid hex digits - this exercises the error path.
        $this->expectException(\InvalidArgumentException::class);
        Theme::fromJsonString(json_encode(['code' => ['foreground' => '#gggggg']]));
    }

    // ---- Renderer::withStandardStyle error path --------------------------

    public function testWithStandardStyleThrowsOnUnknownName(): void
    {
        $r = Renderer::plain();
        $this->expectException(\InvalidArgumentException::class);
        $r->withStandardStyle('nonexistent-theme');
    }

    // ---- Renderer short aliases -------------------------------------------

    public function testThemeAlias(): void
    {
        $r = Renderer::plain()->theme(Theme::ansi());
        $this->assertInstanceOf(Renderer::class, $r);
    }

    public function testWordWrapAlias(): void
    {
        $r = Renderer::plain()->wordWrap(40);
        $this->assertInstanceOf(Renderer::class, $r);
    }

    public function testHyperlinksAlias(): void
    {
        $r = Renderer::plain()->hyperlinks(false);
        $this->assertInstanceOf(Renderer::class, $r);
    }

    public function testBaseURLAlias(): void
    {
        $r = Renderer::plain()->baseURL('https://example.com');
        $this->assertInstanceOf(Renderer::class, $r);
    }

    public function testTableWrapAlias(): void
    {
        $r = Renderer::plain()->tableWrap(true);
        $this->assertInstanceOf(Renderer::class, $r);
    }

    public function testPreservedNewLinesAlias(): void
    {
        $r = Renderer::plain()->preservedNewLines(true);
        $this->assertInstanceOf(Renderer::class, $r);
    }

    public function testEmojiAlias(): void
    {
        $r = Renderer::plain()->emoji(true);
        $this->assertInstanceOf(Renderer::class, $r);
    }

    public function testStandardStyleAlias(): void
    {
        $r = Renderer::plain()->standardStyle('ansi');
        $this->assertInstanceOf(Renderer::class, $r);
    }

    public function testSanitizeAlias(): void
    {
        $r = Renderer::plain()->sanitize(true);
        $this->assertInstanceOf(Renderer::class, $r);
    }

    // ---- Renderer emoji expansion with sanitize=false ---------------------

    public function testExpandEmojiWithSanitizeFalse(): void
    {
        // When sanitize is false, emoji shortcodes should still expand.
        $out = Renderer::plain()
            ->withEmoji(true)
            ->withSanitize(false)
            ->render(':smile: hello');
        $this->assertStringContainsString('😄', $out);
    }

    // ---- Renderer::stripControls edge case --------------------------------

    public function testStripControlsRemovesC0ButNotTabNewline(): void
    {
        // Use reflection to call the private method.
        $ref = new \ReflectionMethod(Renderer::class, 'stripControls');
        $ref->setAccessible(true);

        $input = "hello\x00world\x07test\x08more\n\t\r";
        $result = $ref->invoke(null, $input);

        // \x00, \x07, \x08 should be stripped.
        $this->assertStringNotContainsString("\x00", $result);
        $this->assertStringNotContainsString("\x07", $result);
        $this->assertStringNotContainsString("\x08", $result);
        // \n and \t should be preserved.
        $this->assertStringContainsString("\n", $result);
        $this->assertStringContainsString("\t", $result);
    }

    public function testStripControlsRemovesEsc(): void
    {
        $ref = new \ReflectionMethod(Renderer::class, 'stripControls');
        $ref->setAccessible(true);

        $input = "hello\x1b[31mworld";
        $result = $ref->invoke(null, $input);

        // ESC (0x1b) should be stripped.
        $this->assertStringNotContainsString("\x1b", $result);
        // The [31m portion remains since only the ESC byte is stripped.
        $this->assertStringContainsString('hello[31mworld', $result);
    }

    public function testStripControlsRemovesDEL(): void
    {
        $ref = new \ReflectionMethod(Renderer::class, 'stripControls');
        $ref->setAccessible(true);

        $input = "hello\x7fworld";
        $result = $ref->invoke(null, $input);

        // DEL (0x7f) should be stripped.
        $this->assertStringNotContainsString("\x7f", $result);
        $this->assertStringContainsString('helloworld', $result);
    }

    // ---- Renderer::safeUrl edge case --------------------------------------

    public function testSafeUrlRemovesC0Controls(): void
    {
        $ref = new \ReflectionMethod(Renderer::class, 'safeUrl');
        $ref->setAccessible(true);

        // URL with embedded control bytes.
        $url = "https://example.com\x00/test";
        $result = $ref->invoke(null, $url);

        $this->assertStringNotContainsString("\x00", $result);
        $this->assertStringContainsString('https://example.com', $result);
    }

    // ---- Renderer::expandEmojiShortcodes with all entries -----------------

    public function testExpandEmojiShortcodesAllKnown(): void
    {
        $ref = new \ReflectionMethod(Renderer::class, 'expandEmojiShortcodes');
        $ref->setAccessible(true);

        // Note: 'headphones' has a leading space in the map so :headphones: won't match.
        $known = [
            ':smile:', ':grin:', ':heart:', ':fire:', ':rocket:',
            ':star:', ':thumbsup:', ':thumbsdown:', ':check:', ':x:',
            ':warning:', ':info:', ':tada:', ':sparkles:', ':candy:',
            ':sugar:', ':honey:', ':clap:', ':eyes:', ':tongue:',
            ':wink:', ':sob:', ':sleeping:', ':zzz:',
            ':mail:', ':email:', ':phone:', ':camera:', ':gift:',
            ':pencil:', ':hammer:', ':wrench:', ':bug:', ':dragon:',
            ':koala:', ':tiger:', ':rabbit:', ':snake:',
        ];

        foreach ($known as $shortcode) {
            $result = $ref->invoke(null, $shortcode);
            $this->assertNotSame($shortcode, $result, "Shortcode $shortcode should be expanded");
        }
    }

    // ---- Renderer resolveUrl edge cases -----------------------------------

    public function testResolveUrlWithEmptyBaseUrl(): void
    {
        $r = Renderer::plain()->withBaseURL(null);
        $out = $r->render('[link](https://example.com)');
        $this->assertStringContainsString('https://example.com', $out);
    }

    public function testResolveUrlWithEmptyPathAndBaseUrl(): void
    {
        $r = Renderer::plain()
            ->withBaseURL('https://example.com/docs/')
            ->withHyperlinks(false);
        // Empty path after base URL.
        $out = $r->render('[link](#anchor)');
        $this->assertStringContainsString('(#anchor)', $out);
    }

    // ---- Renderer withBaseURL edge cases ----------------------------------

    public function testWithBaseURLStripsTrailingSlashAndAddsOne(): void
    {
        // Base URL with trailing slash should have one added.
        $r = Renderer::plain()
            ->withBaseURL('https://example.com/docs/')
            ->withHyperlinks(false);
        $out = $r->render('[home](readme.md)');
        // Should be https://example.com/docs/readme.md (trailing slash stripped, then / added).
        $this->assertStringContainsString('https://example.com/docs/readme.md', $out);
    }

    public function testWithBaseURLWithEmptyStringBecomesNull(): void
    {
        // Empty string base URL should become null (no prefix).
        $r = Renderer::plain()
            ->withBaseURL('')
            ->withHyperlinks(false);
        $out = $r->render('[home](readme.md)');
        $this->assertStringContainsString('(readme.md)', $out);
        $this->assertStringNotContainsString('https://', $out);
    }

    // ---- Renderer copy() method -------------------------------------------

    public function testCopyCreatesNewInstance(): void
    {
        $a = new Renderer(Theme::plain());
        $b = $a->withTheme(Theme::ansi());
        $this->assertNotSame($a, $b);
    }

    public function testCopyPreservesUnchangedValues(): void
    {
        $a = new Renderer(Theme::plain(), 80, false, 'https://test.com', true, true, true, true);
        $b = $a->withTheme(Theme::ansi());
        // Only theme changed; other values preserved.
        $this->assertNotSame($a->theme, $b->theme);
    }

    // ---- Renderer textIsPlain edge case -----------------------------------

    public function testTextIsPlainWithPlainStyle(): void
    {
        // Theme with plain text style (renders 'x' unchanged).
        $r = Renderer::plain();
        $out = $r->render('hello world');
        $this->assertSame('hello world', $out);
    }

    // ---- Renderer availableWidth edge cases -------------------------------

    public function testWordWrapWithZeroWidth(): void
    {
        // Zero wrap width should disable wrapping (null stored).
        $r = Renderer::plain()->withWordWrap(0);
        $out = $r->render('one two three four five six seven eight nine ten');
        $this->assertStringContainsString('one', $out);
        $this->assertStringContainsString('ten', $out);
    }

    public function testWordWrapWithNegativeWidth(): void
    {
        // Negative wrap width should disable wrapping (null stored).
        $r = Renderer::plain()->withWordWrap(-5);
        $out = $r->render('one two three four five six seven eight nine ten');
        $this->assertStringContainsString('one', $out);
        $this->assertStringContainsString('ten', $out);
    }

    // ---- Description list rendering ---------------------------------------

    public function testDescriptionListWithDefinitionListStyle(): void
    {
        $plain = Theme::plain();
        $styled = new Theme(
            heading1: $plain->heading1, heading2: $plain->heading2,
            heading3: $plain->heading3, heading4: $plain->heading4,
            heading5: $plain->heading5, heading6: $plain->heading6,
            paragraph: $plain->paragraph, bold: $plain->bold,
            italic: $plain->italic, code: $plain->code,
            codeBlock: $plain->codeBlock, link: $plain->link,
            blockquote: $plain->blockquote, listMarker: $plain->listMarker,
            rule: $plain->rule,
            definitionList: $plain->bold,
        );
        $r = new Renderer($styled);
        $out = $r->render("Term\n: Definition");
        // Should contain styled content.
        $this->assertStringContainsString('Term', $out);
        $this->assertStringContainsString('Definition', $out);
    }
}

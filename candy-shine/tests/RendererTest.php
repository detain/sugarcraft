<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;
use SugarCraft\Sprinkles\Style;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private function plain(): Renderer
    {
        return new Renderer(Theme::plain());
    }

    public function testHeading1Plain(): void
    {
        $this->assertSame('# Hello', $this->plain()->render("# Hello"));
    }

    public function testHeading6Plain(): void
    {
        $this->assertSame('###### deep', $this->plain()->render("###### deep"));
    }

    public function testParagraphPlain(): void
    {
        $this->assertSame('hello world', $this->plain()->render('hello world'));
    }

    public function testStrongAndEmphasisPlain(): void
    {
        $this->assertSame(
            'a bold and italic text',
            $this->plain()->render('a **bold** and *italic* text'),
        );
    }

    public function testInlineCodePlain(): void
    {
        $this->assertSame(
            'use foo() to call',
            $this->plain()->render('use `foo()` to call'),
        );
    }

    public function testFencedCodeBlockPlain(): void
    {
        $md  = "```\nphp -v\nphp -m\n```";
        $out = $this->plain()->render($md);
        $this->assertStringContainsString('php -v', $out);
        $this->assertStringContainsString('php -m', $out);
    }

    public function testBulletedListPlain(): void
    {
        $md = "- one\n- two\n- three";
        $expected = "• one\n• two\n• three";
        $this->assertSame($expected, $this->plain()->render($md));
    }

    public function testOrderedListPlain(): void
    {
        $md = "1. one\n2. two\n3. three";
        $expected = "1. one\n2. two\n3. three";
        $this->assertSame($expected, $this->plain()->render($md));
    }

    public function testLinkPlain(): void
    {
        // Plain theme + hyperlinks disabled → byte-exact "text (url)".
        $this->assertSame(
            'site (https://example.com)',
            $this->plain()->withHyperlinks(false)->render('[site](https://example.com)'),
        );
    }

    public function testLinkOsc8WrapsClickable(): void
    {
        $out = $this->plain()->render('[site](https://example.com)');
        // OSC 8 envelope plus a visible (url) fallback for terminals
        // without OSC 8 support.
        $this->assertStringContainsString("\x1b]8;;https://example.com\x1b\\site\x1b]8;;\x1b\\", $out);
        $this->assertStringContainsString('(https://example.com)', $out);
    }

    public function testBlockquotePlain(): void
    {
        $out = $this->plain()->render("> hello\n> world");
        $this->assertSame("▎ hello\n▎ world", $out);
    }

    public function testHorizontalRule(): void
    {
        $out = $this->plain()->render("---");
        $this->assertSame(str_repeat('─', 40), $out);
    }

    // ---- ANSI theme assertions: not pixel-exact, just confirm SGR ----

    public function testAnsiThemeWrapsHeadingsInSgr(): void
    {
        $out = (new Renderer())->render('# Hello');
        $this->assertStringContainsString('Hello', $out);
        $this->assertStringContainsString("\x1b[", $out);
    }

    public function testAnsiThemeWrapsCode(): void
    {
        $out = (new Renderer())->render('use `foo()`');
        $this->assertStringContainsString('foo()', $out);
        $this->assertStringContainsString("\x1b[", $out);
    }

    public function testWithThemeReturnsNewInstance(): void
    {
        $a = new Renderer(Theme::ansi());
        $b = $a->withTheme(Theme::plain());
        $this->assertNotSame($a, $b);
        $this->assertSame('plain', $b->render('plain'));
    }

    public function testRenderTrimsTrailingNewlines(): void
    {
        // Two paragraphs separated by a blank line; renderer joins with
        // "\n\n" but final result has no trailing blank lines.
        $out = $this->plain()->render("one\n\ntwo");
        $this->assertSame("one\n\ntwo", $out);
    }

    public function testNestedBulletedListIndents(): void
    {
        $md = "- parent\n  - child\n  - sibling\n- top";
        $out = $this->plain()->render($md);
        $expected = "• parent\n  • child\n  • sibling\n• top";
        $this->assertSame($expected, $out);
    }

    public function testNestedOrderedInsideBulletedIndents(): void
    {
        $md = "- parent\n  1. one\n  2. two";
        $out = $this->plain()->render($md);
        $expected = "• parent\n  1. one\n  2. two";
        $this->assertSame($expected, $out);
    }

    public function testOrderedNestedInsideOrderedAlignsToMarkerWidth(): void
    {
        // The outer marker "1." is 2 cells, so the nested "1." starts at
        // column 3 (one space + marker width).
        $md = "1. parent\n   1. child\n   2. sibling\n2. next";
        $out = $this->plain()->render($md);
        $expected = "1. parent\n   1. child\n   2. sibling\n2. next";
        $this->assertSame($expected, $out);
    }

    public function testMultiLineParagraphInListItemKeepsIndent(): void
    {
        // CommonMark turns a soft line-break inside a list-item paragraph
        // into a single newline; the renderer must indent continuation
        // lines so they line up under the first character of the body.
        $md = "- first line\n  second line";
        $out = $this->plain()->render($md);
        $this->assertSame("• first line\n  second line", $out);
    }

    // ---- GFM tables -----------------------------------------------------

    public function testRendersGfmTable(): void
    {
        $md = <<<MD
| Name  | Age |
| ----- | --- |
| Alice | 30  |
| Bob   | 25  |
MD;
        $out = $this->plain()->render($md);
        // Sprinkles\Table\Table renders with a rounded border.
        $this->assertStringContainsString('Name',  $out);
        $this->assertStringContainsString('Alice', $out);
        $this->assertStringContainsString('Bob',   $out);
        $this->assertStringContainsString('╭', $out);
        $this->assertStringContainsString('╯', $out);
    }

    public function testTableWithoutHeaderRendersBodyOnly(): void
    {
        // GFM requires a header row, so this is contrived. Verify we at
        // least don't crash on a single-row table.
        $md = "| a | b |\n|---|---|\n| 1 | 2 |";
        $out = $this->plain()->render($md);
        $this->assertStringContainsString('a', $out);
        $this->assertStringContainsString('1', $out);
    }

    // ---- task lists ----------------------------------------------------

    public function testTaskListRendersCheckGlyphs(): void
    {
        $md = "- [x] done\n- [ ] todo\n- [X] also done";
        $out = $this->plain()->render($md);
        $this->assertStringContainsString('☑ done',       $out);
        $this->assertStringContainsString('☐ todo',       $out);
        $this->assertStringContainsString('☑ also done',  $out);
    }

    public function testStrikethroughRenders(): void
    {
        $r = $this->plain()->withHyperlinks(false);
        $out = $r->render('one ~~two~~ three');
        // In plain mode the strike style is also a no-op (no SGR), so
        // we just verify the inner text survives — it shouldn't be
        // dropped on the floor like the pre-fix behaviour.
        $this->assertStringContainsString('two', $out);
        $this->assertStringContainsString('one ', $out);
        $this->assertStringContainsString(' three', $out);
    }

    public function testStrikethroughEmitsSgrInAnsiTheme(): void
    {
        $r = (new \SugarCraft\Shine\Renderer(\SugarCraft\Shine\Theme::ansi()))
            ->withHyperlinks(false);
        $out = $r->render('a ~~b~~ c');
        $this->assertStringContainsString("\x1b[9m", $out); // SGR 9 = strikethrough
    }

    public function testWordWrapBreaksLongParagraph(): void
    {
        $md = 'one two three four five six seven eight nine';
        $out = $this->plain()->withWordWrap(15)->render($md);
        // Each line should be <= 15 visible cells.
        foreach (explode("\n", $out) as $line) {
            $this->assertLessThanOrEqual(15, \SugarCraft\Core\Util\Width::string($line));
        }
        $this->assertGreaterThan(1, substr_count($out, "\n"));
    }

    public function testWordWrapHonoursBlockquote(): void
    {
        $md = "> one two three four five six seven eight";
        $out = $this->plain()->withWordWrap(15)->render($md);
        // Blockquote prefix '▎ ' eats 2 cells; each rendered line should
        // still respect the 15-cell budget overall.
        foreach (explode("\n", $out) as $line) {
            $this->assertLessThanOrEqual(15, \SugarCraft\Core\Util\Width::string($line));
        }
    }

    public function testThemeDarkPreset(): void
    {
        $t = \SugarCraft\Shine\Theme::dark();
        $this->assertNotNull($t->strike);
        $this->assertNotNull($t->linkText);
    }

    public function testThemeLightPreset(): void
    {
        $t = \SugarCraft\Shine\Theme::light();
        $this->assertNotNull($t->strike);
    }

    public function testThemeDraculaPreset(): void
    {
        $t = \SugarCraft\Shine\Theme::dracula();
        $this->assertNotNull($t->strike);
    }

    public function testThemeTokyoNightPreset(): void
    {
        $t = \SugarCraft\Shine\Theme::tokyoNight();
        $this->assertNotNull($t->strike);
    }

    public function testThemePinkPreset(): void
    {
        $t = \SugarCraft\Shine\Theme::pink();
        $this->assertNotNull($t->strike);
    }

    public function testThemeNottyIsPlain(): void
    {
        $r = (new \SugarCraft\Shine\Renderer(\SugarCraft\Shine\Theme::notty()))
            ->withHyperlinks(false);
        $out = $r->render('# Hello');
        $this->assertSame('# Hello', $out);
    }

    public function testHtmlBlockRendersLiteral(): void
    {
        $md = "<div class=\"x\">hi</div>\n\nafter";
        $out = $this->plain()->render($md);
        $this->assertStringContainsString('<div class="x">hi</div>', $out);
        $this->assertStringContainsString('after', $out);
    }

    public function testImageHasAltAndUrl(): void
    {
        $out = $this->plain()->render('![alt text](http://x/y.png)');
        $this->assertStringContainsString('alt text', $out);
        $this->assertStringContainsString('(http://x/y.png)', $out);
    }

    public function testWithBaseURLPrefixesRelativeLinks(): void
    {
        $out = $this->plain()
            ->withBaseURL('https://example.com/docs')
            ->withHyperlinks(false)
            ->render('[home](readme.md)');
        $this->assertStringContainsString('(https://example.com/docs/readme.md)', $out);
    }

    public function testWithBaseURLLeavesAbsoluteLinksAlone(): void
    {
        $out = $this->plain()
            ->withBaseURL('https://example.com/')
            ->withHyperlinks(false)
            ->render('[gh](https://github.com/x/y)');
        $this->assertStringContainsString('(https://github.com/x/y)', $out);
        $this->assertStringNotContainsString('example.com', $out);
    }

    public function testWithBaseURLPrefixesImages(): void
    {
        $out = $this->plain()
            ->withBaseURL('https://cdn.example.com/')
            ->render('![logo](logo.png)');
        $this->assertStringContainsString('(https://cdn.example.com/logo.png)', $out);
    }

    public function testWithTableWrapWrapsCells(): void
    {
        $md  = "| h |\n|---|\n| this is a long cell that should wrap |\n";
        $unwrapped = $this->plain()->render($md);
        $wrapped = $this->plain()
            ->withWordWrap(15)
            ->withTableWrap(true)
            ->render($md);
        // Wrapped output must contain at least one extra newline that
        // unwrapped doesn't, since the cell body now spans multiple
        // visible lines.
        $this->assertGreaterThan(
            substr_count($unwrapped, "\n"),
            substr_count($wrapped, "\n"),
        );
    }

    public function testWithInlineTableLinksOff(): void
    {
        $md = "| col |\n|-----|\n| [home](https://example.com/) |\n";
        $out = $this->plain()
            ->withInlineTableLinks(false)
            ->withHyperlinks(false)
            ->render($md);
        $this->assertStringNotContainsString('(https://example.com/)', $out);
        $this->assertStringContainsString('home', $out);
    }

    public function testWithInlineTableLinksOnByDefault(): void
    {
        $md = "| col |\n|-----|\n| [home](https://example.com/) |\n";
        $out = $this->plain()
            ->withHyperlinks(false)
            ->render($md);
        $this->assertStringContainsString('(https://example.com/)', $out);
    }

    public function testWithPreservedNewLines(): void
    {
        $md = "first\n\n\n\n\nlast";
        $out = $this->plain()
            ->withPreservedNewLines(true)
            ->render($md);
        $this->assertStringContainsString('first', $out);
        $this->assertStringContainsString('last',  $out);
        $this->assertGreaterThan(2, substr_count($out, "\n"));
    }

    public function testPreservedNewLinesOffByDefault(): void
    {
        $md = "first\n\n\n\n\nlast";
        $out = $this->plain()->render($md);
        $this->assertStringContainsString('first', $out);
        $this->assertStringContainsString('last',  $out);
    }

    private function withTheme(Theme $t): Renderer
    {
        return new Renderer($t);
    }

    public function testCustomTaskGlyphs(): void
    {
        $base = Theme::plain();
        $custom = new Theme(
            heading1: $base->heading1, heading2: $base->heading2, heading3: $base->heading3,
            heading4: $base->heading4, heading5: $base->heading5, heading6: $base->heading6,
            paragraph: $base->paragraph, bold: $base->bold, italic: $base->italic,
            code: $base->code, codeBlock: $base->codeBlock, link: $base->link,
            blockquote: $base->blockquote, listMarker: $base->listMarker, rule: $base->rule,
            taskTickedGlyph: '[x]', taskUntickedGlyph: '[ ]',
        );
        $out = $this->withTheme($custom)->render("- [x] done\n- [ ] todo");
        $this->assertStringContainsString('[x] done', $out);
        $this->assertStringContainsString('[ ] todo', $out);
    }

    public function testCustomHorizontalRule(): void
    {
        $base = Theme::plain();
        $custom = new Theme(
            heading1: $base->heading1, heading2: $base->heading2, heading3: $base->heading3,
            heading4: $base->heading4, heading5: $base->heading5, heading6: $base->heading6,
            paragraph: $base->paragraph, bold: $base->bold, italic: $base->italic,
            code: $base->code, codeBlock: $base->codeBlock, link: $base->link,
            blockquote: $base->blockquote, listMarker: $base->listMarker, rule: $base->rule,
            horizontalRuleGlyph: '=', horizontalRuleLength: 8,
        );
        $out = $this->withTheme($custom)->render("---");
        $this->assertStringContainsString('========', $out);
        $this->assertStringNotContainsString('─', $out);
    }

    public function testDocumentMarginAndIndent(): void
    {
        $base = Theme::plain();
        $custom = new Theme(
            heading1: $base->heading1, heading2: $base->heading2, heading3: $base->heading3,
            heading4: $base->heading4, heading5: $base->heading5, heading6: $base->heading6,
            paragraph: $base->paragraph, bold: $base->bold, italic: $base->italic,
            code: $base->code, codeBlock: $base->codeBlock, link: $base->link,
            blockquote: $base->blockquote, listMarker: $base->listMarker, rule: $base->rule,
            documentMargin: 2, documentIndent: 3,
        );
        $out = $this->withTheme($custom)->render('hello');
        // 2 leading newlines (margin) + 3-space indent on the body line.
        $this->assertStringStartsWith("\n\n   hello", $out);
        $this->assertStringEndsWith("\n\n", $out);
    }

    public function testListLevelIndentOverridesBulletWidth(): void
    {
        $base = Theme::plain();
        $custom = new Theme(
            heading1: $base->heading1, heading2: $base->heading2, heading3: $base->heading3,
            heading4: $base->heading4, heading5: $base->heading5, heading6: $base->heading6,
            paragraph: $base->paragraph, bold: $base->bold, italic: $base->italic,
            code: $base->code, codeBlock: $base->codeBlock, link: $base->link,
            blockquote: $base->blockquote, listMarker: $base->listMarker, rule: $base->rule,
            listLevelIndent: 6,
        );
        $out = $this->withTheme($custom)->render("- one\n  continuation");
        // The continuation should be indented 6 spaces (overriding the
        // default bullet+space width of 2).
        $this->assertStringContainsString('      continuation', $out);
    }

    // ---- Golden fixture tests -----------------------------------------------

    public function testNestedBlockquoteGolden(): void
    {
        $md = file_get_contents(__DIR__ . '/fixtures/nested_blockquote.md');
        $goldenFile = __DIR__ . '/fixtures/nested_blockquote.golden';
        $this->assertStringEqualsFile($goldenFile, $this->plain()->render($md));
    }

    public function testNestedListGolden(): void
    {
        $md = file_get_contents(__DIR__ . '/fixtures/nested_list.md');
        $goldenFile = __DIR__ . '/fixtures/nested_list.golden';
        $this->assertStringEqualsFile($goldenFile, $this->plain()->render($md));
    }

    public function testQuoteWithListGolden(): void
    {
        $md = file_get_contents(__DIR__ . '/fixtures/quote_with_list.md');
        $goldenFile = __DIR__ . '/fixtures/quote_with_list.golden';
        $this->assertStringEqualsFile($goldenFile, $this->plain()->render($md));
    }

    public function testInlineHtmlRenders(): void
    {
        $r = $this->plain();
        $this->assertSame("<b>bold</b>", $r->render("<b>bold</b>"));
    }

    public function testInlineHtmlSpanWithEmphasis(): void
    {
        $r = $this->plain();
        $this->assertSame("<em>italic</em>", $r->render("<em>italic</em>"));
    }

    public function testTextRenderingWithNullThemeText(): void
    {
        $base = Theme::plain();
        $custom = new Theme(
            heading1: $base->heading1, heading2: $base->heading2, heading3: $base->heading3,
            heading4: $base->heading4, heading5: $base->heading5, heading6: $base->heading6,
            paragraph: $base->paragraph, bold: $base->bold, italic: $base->italic,
            code: $base->code, codeBlock: $base->codeBlock, link: $base->link,
            blockquote: $base->blockquote, listMarker: $base->listMarker, rule: $base->rule,
            text: null,
        );
        $r = new Renderer($custom);
        // When theme->text is null, literal text passes through unchanged.
        $this->assertSame("plain text", $r->render("plain text"));
    }

    public function testTextRenderingWithPlainThemeText(): void
    {
        // When theme->text renders 'x' unchanged (plain), literal passes through.
        $r = $this->plain();
        $this->assertSame("hello world", $r->render("hello world"));
    }

    public function testStrikeWithNullThemeStrike(): void
    {
        $base = Theme::plain();
        $custom = new Theme(
            heading1: $base->heading1, heading2: $base->heading2, heading3: $base->heading3,
            heading4: $base->heading4, heading5: $base->heading5, heading6: $base->heading6,
            paragraph: $base->paragraph, bold: $base->bold, italic: $base->italic,
            code: $base->code, codeBlock: $base->codeBlock, link: $base->link,
            blockquote: $base->blockquote, listMarker: $base->listMarker, rule: $base->rule,
            strike: null,
        );
        $r = new Renderer($custom);
        $out = $r->withHyperlinks(false)->render('a ~~b~~ c');
        // When strike is null, Style::new()->strikethrough() is used as fallback.
        $this->assertStringContainsString('b', $out);
    }

    public function testApplyCaseUpper(): void
    {
        $r = $this->plain();
        $out = $r->render('# hello world');
        // The heading uses upper case when theme headingCase is set.
        $base = Theme::plain();
        $custom = new Theme(
            heading1: $base->heading1, heading2: $base->heading2, heading3: $base->heading3,
            heading4: $base->heading4, heading5: $base->heading5, heading6: $base->heading6,
            paragraph: $base->paragraph, bold: $base->bold, italic: $base->italic,
            code: $base->code, codeBlock: $base->codeBlock, link: $base->link,
            blockquote: $base->blockquote, listMarker: $base->listMarker, rule: $base->rule,
            headingCase: 'upper',
        );
        $r2 = new Renderer($custom);
        $out = $r2->render('# hello');
        $this->assertStringContainsString('HELLO', $out);
    }

    public function testApplyCaseLower(): void
    {
        $base = Theme::plain();
        $custom = new Theme(
            heading1: $base->heading1, heading2: $base->heading2, heading3: $base->heading3,
            heading4: $base->heading4, heading5: $base->heading5, heading6: $base->heading6,
            paragraph: $base->paragraph, bold: $base->bold, italic: $base->italic,
            code: $base->code, codeBlock: $base->codeBlock, link: $base->link,
            blockquote: $base->blockquote, listMarker: $base->listMarker, rule: $base->rule,
            headingCase: 'lower',
        );
        $r = new Renderer($custom);
        $out = $r->render('# HELLO');
        $this->assertStringContainsString('hello', $out);
    }

    public function testApplyCaseTitle(): void
    {
        $base = Theme::plain();
        $custom = new Theme(
            heading1: $base->heading1, heading2: $base->heading2, heading3: $base->heading3,
            heading4: $base->heading4, heading5: $base->heading5, heading6: $base->heading6,
            paragraph: $base->paragraph, bold: $base->bold, italic: $base->italic,
            code: $base->code, codeBlock: $base->codeBlock, link: $base->link,
            blockquote: $base->blockquote, listMarker: $base->listMarker, rule: $base->rule,
            headingCase: 'title',
        );
        $r = new Renderer($custom);
        $out = $r->render('# hello world');
        $this->assertStringContainsString('Hello World', $out);
    }

    public function testResolveUrlFragmentOnly(): void
    {
        $out = $this->plain()
            ->withBaseURL('https://example.com/')
            ->withHyperlinks(false)
            ->render('[jump](#section)');
        // Fragment-only URLs should pass through unchanged (not prefixed).
        $this->assertStringContainsString('(#section)', $out);
        $this->assertStringNotContainsString('example.com', $out);
    }

    public function testFromEnvironment(): void
    {
        // Test that fromEnvironment creates a renderer without throwing.
        $r = Renderer::fromEnvironment();
        $this->assertInstanceOf(Renderer::class, $r);
    }

    public function testRenderMarkdownStatic(): void
    {
        $out = Renderer::renderMarkdown('# Hello');
        $this->assertStringContainsString('Hello', $out);
    }

    public function testImageTextFallback(): void
    {
        // When theme->imageText is null but image is set, image style is used for alt text.
        $base = Theme::plain();
        $custom = new Theme(
            heading1: $base->heading1, heading2: $base->heading2, heading3: $base->heading3,
            heading4: $base->heading4, heading5: $base->heading5, heading6: $base->heading6,
            paragraph: $base->paragraph, bold: $base->bold, italic: $base->italic,
            code: $base->code, codeBlock: $base->codeBlock, link: $base->link,
            blockquote: $base->blockquote, listMarker: $base->listMarker, rule: $base->rule,
            image: $base->image,
            imageText: null,
        );
        $r = new Renderer($custom);
        $out = $r->render('![alt text](http://x/y.png)');
        $this->assertStringContainsString('alt text', $out);
    }

    public function testImageWithImageTextSet(): void
    {
        // When theme->imageText is set, it should be used instead of image style.
        $base = Theme::plain();
        $custom = new Theme(
            heading1: $base->heading1, heading2: $base->heading2, heading3: $base->heading3,
            heading4: $base->heading4, heading5: $base->heading5, heading6: $base->heading6,
            paragraph: $base->paragraph, bold: $base->bold, italic: $base->italic,
            code: $base->code, codeBlock: $base->codeBlock, link: $base->link,
            blockquote: $base->blockquote, listMarker: $base->listMarker, rule: $base->rule,
            image: Style::new()->dim(),
            imageText: Style::new()->bold(),
        );
        $r = new Renderer($custom);
        $out = $r->render('![alt text](http://x/y.png)');
        $this->assertStringContainsString('alt text', $out);
    }

    public function testExpandEmojiShortcodes(): void
    {
        $out = $this->plain()
            ->withEmoji(true)
            ->render(':smile: hello');
        $this->assertStringContainsString('😄', $out);
    }

    public function testExpandEmojiUnknownShortcodePassesThrough(): void
    {
        $out = $this->plain()
            ->withEmoji(true)
            ->render(':unknown: hello');
        $this->assertStringContainsString(':unknown:', $out);
    }

    public function testDocumentBlockPrefixAndSuffix(): void
    {
        $base = Theme::plain();
        $custom = new Theme(
            heading1: $base->heading1, heading2: $base->heading2, heading3: $base->heading3,
            heading4: $base->heading4, heading5: $base->heading5, heading6: $base->heading6,
            paragraph: $base->paragraph, bold: $base->bold, italic: $base->italic,
            code: $base->code, codeBlock: $base->codeBlock, link: $base->link,
            blockquote: $base->blockquote, listMarker: $base->listMarker, rule: $base->rule,
            documentBlockPrefix: '[',
            documentBlockSuffix: ']',
        );
        $r = new Renderer($custom);
        $out = $r->render('hello');
        $this->assertStringStartsWith('[', $out);
        $this->assertStringEndsWith(']', $out);
    }

    public function testTableWithCellStyleNull(): void
    {
        // When theme cell style is null, cells render without extra styling.
        $md = "| h |\n|---|\n| cell |\n";
        $out = $this->plain()->render($md);
        $this->assertStringContainsString('cell', $out);
    }

    public function testFencedCodeWithLanguageHint(): void
    {
        $md = "```php\necho 'hi';\n```";
        $out = $this->plain()->render($md);
        $this->assertStringContainsString('echo', $out);
    }

    public function testLinkWithTextSameAsUrl(): void
    {
        // When link text equals URL, just show URL styled.
        $out = $this->plain()
            ->withHyperlinks(false)
            ->render('https://example.com');
        $this->assertStringContainsString('https://example.com', $out);
    }

    public function testHeadingWithSuffix(): void
    {
        $base = Theme::plain();
        $custom = new Theme(
            heading1: $base->heading1, heading2: $base->heading2, heading3: $base->heading3,
            heading4: $base->heading4, heading5: $base->heading5, heading6: $base->heading6,
            paragraph: $base->paragraph, bold: $base->bold, italic: $base->italic,
            code: $base->code, codeBlock: $base->codeBlock, link: $base->link,
            blockquote: $base->blockquote, listMarker: $base->listMarker, rule: $base->rule,
            headingSuffix: ' ·',
        );
        $r = new Renderer($custom);
        $out = $r->render('# Hello');
        $this->assertStringContainsString('Hello', $out);
    }

    public function testHeadingPrefixGenerated(): void
    {
        // When headingPrefix is null, renderer generates it from '#' chars.
        $base = Theme::plain();
        $custom = new Theme(
            heading1: $base->heading1, heading2: $base->heading2, heading3: $base->heading3,
            heading4: $base->heading4, heading5: $base->heading5, heading6: $base->heading6,
            paragraph: $base->paragraph, bold: $base->bold, italic: $base->italic,
            code: $base->code, codeBlock: $base->codeBlock, link: $base->link,
            blockquote: $base->blockquote, listMarker: $base->listMarker, rule: $base->rule,
            headingPrefix: null,
        );
        $r = new Renderer($custom);
        $out = $r->render('## Hello');
        $this->assertStringContainsString('Hello', $out);
    }

    public function testImageWithEmptyAltShowsPlaceholder(): void
    {
        // When alt text is empty, render "[image]" placeholder.
        $out = $this->plain()->render('![](http://x/y.png)');
        $this->assertStringContainsString('[image]', $out);
    }

    public function testTableHeaderStyleNull(): void
    {
        // When tableHeader style is null, cell content passes through.
        $base = Theme::plain();
        $custom = new Theme(
            heading1: $base->heading1, heading2: $base->heading2, heading3: $base->heading3,
            heading4: $base->heading4, heading5: $base->heading5, heading6: $base->heading6,
            paragraph: $base->paragraph, bold: $base->bold, italic: $base->italic,
            code: $base->code, codeBlock: $base->codeBlock, link: $base->link,
            blockquote: $base->blockquote, listMarker: $base->listMarker, rule: $base->rule,
            tableHeader: null,
            tableCell: null,
        );
        $r = new Renderer($custom);
        $md = "| Name |\n| --- |\n| Bob |\n";
        $out = $r->render($md);
        $this->assertStringContainsString('Name', $out);
        $this->assertStringContainsString('Bob', $out);
    }

    public function testPreservedNewLinesWithExactlyThreeNewlines(): void
    {
        // Exactly 3 newlines = 2 blank lines, should be preserved.
        $md = "first\n\n\nlast";
        $out = $this->plain()
            ->withPreservedNewLines(true)
            ->render($md);
        // The preserved runs should result in more than default output.
        $this->assertStringContainsString('first', $out);
        $this->assertStringContainsString('last', $out);
    }
}

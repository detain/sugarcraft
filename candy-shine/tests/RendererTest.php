<?php

declare(strict_types=1);

namespace CandyCore\Shine\Tests;

use CandyCore\Shine\Renderer;
use CandyCore\Shine\Theme;
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
        $r = (new \CandyCore\Shine\Renderer(\CandyCore\Shine\Theme::ansi()))
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
            $this->assertLessThanOrEqual(15, \CandyCore\Core\Util\Width::string($line));
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
            $this->assertLessThanOrEqual(15, \CandyCore\Core\Util\Width::string($line));
        }
    }

    public function testThemeDarkPreset(): void
    {
        $t = \CandyCore\Shine\Theme::dark();
        $this->assertNotNull($t->strike);
        $this->assertNotNull($t->linkText);
    }

    public function testThemeLightPreset(): void
    {
        $t = \CandyCore\Shine\Theme::light();
        $this->assertNotNull($t->strike);
    }

    public function testThemeDraculaPreset(): void
    {
        $t = \CandyCore\Shine\Theme::dracula();
        $this->assertNotNull($t->strike);
    }

    public function testThemeTokyoNightPreset(): void
    {
        $t = \CandyCore\Shine\Theme::tokyoNight();
        $this->assertNotNull($t->strike);
    }

    public function testThemePinkPreset(): void
    {
        $t = \CandyCore\Shine\Theme::pink();
        $this->assertNotNull($t->strike);
    }

    public function testThemeNottyIsPlain(): void
    {
        $r = (new \CandyCore\Shine\Renderer(\CandyCore\Shine\Theme::notty()))
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
}

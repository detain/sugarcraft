<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Core\Syntax\TokenKind;
use SugarCraft\Freeze\CodeHighlighter;
use SugarCraft\Freeze\SvgRenderer;
use SugarCraft\Freeze\Theme;
use PHPUnit\Framework\TestCase;

final class CodeHighlighterTest extends TestCase
{
    public function testKeywordCarriesKeywordColour(): void
    {
        $out = (new CodeHighlighter())->highlight('return x;', 'php');
        // Dracula pink #ff79c6 → truecolor SGR 255;121;198 wrapping `return`.
        $this->assertStringContainsString("\x1b[38;2;255;121;198mreturn\x1b[0m", $out);
    }

    public function testStringCarriesStringColour(): void
    {
        $out = (new CodeHighlighter())->highlight('$s = "hi";', 'php');
        // Dracula yellow #f1fa8c → 241;250;140 wrapping the double-quoted string.
        $this->assertStringContainsString("\x1b[38;2;241;250;140m\"hi\"\x1b[0m", $out);
    }

    public function testNumberCarriesNumberColour(): void
    {
        $out = (new CodeHighlighter())->highlight('x = 42;', 'js');
        // Dracula purple #bd93f9 → 189;147;249 wrapping `42`.
        $this->assertStringContainsString("\x1b[38;2;189;147;249m42\x1b[0m", $out);
    }

    public function testCommentCarriesCommentColour(): void
    {
        $out = (new CodeHighlighter())->highlight('x = 1 // note', 'js');
        // Dracula comment #6272a4 → 98;114;164 wrapping the line comment.
        $this->assertStringContainsString("\x1b[38;2;98;114;164m// note\x1b[0m", $out);
    }

    public function testPlainMapsToForeground(): void
    {
        $theme = Theme::nord();
        $highlighter = CodeHighlighter::forTheme($theme);
        $this->assertSame($theme->foreground, $highlighter->colorFor(TokenKind::Plain));
    }

    public function testColorForCoversEveryKind(): void
    {
        $h = new CodeHighlighter(
            foreground: '#111111',
            keyword:    '#222222',
            string:     '#333333',
            number:     '#444444',
            comment:    '#555555',
        );
        $this->assertSame('#222222', $h->colorFor(TokenKind::Keyword));
        $this->assertSame('#333333', $h->colorFor(TokenKind::StringToken));
        $this->assertSame('#444444', $h->colorFor(TokenKind::Number));
        $this->assertSame('#555555', $h->colorFor(TokenKind::Comment));
        $this->assertSame('#111111', $h->colorFor(TokenKind::Plain));
    }

    public function testPlainSpansAreEmittedVerbatim(): void
    {
        // Whitespace/operators between tokens are Plain — never SGR-wrapped.
        $out = (new CodeHighlighter())->highlight('   ', 'php');
        $this->assertSame('   ', $out);
        $this->assertStringNotContainsString("\x1b", $out);
    }

    public function testUnknownLanguageReturnsInputByteIdentical(): void
    {
        // Opt-in: an unrecognised language tokenises to one Plain span, so the
        // input round-trips unchanged with no ANSI added.
        $code = "just some prose\nwith two lines";
        $out = (new CodeHighlighter())->highlight($code, 'text');
        $this->assertSame($code, $out);
    }

    public function testConcatenatedTextReproducesOriginal(): void
    {
        // Stripping the added SGR must recover the source byte-for-byte.
        $code = "if (x) {\n    return 1; // done\n}";
        $out = (new CodeHighlighter())->highlight($code, 'php');
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $out);
        $this->assertSame($code, $stripped);
    }

    public function testMultiLineCommentColouredOnEveryLine(): void
    {
        // Block comment spans lines; each line must re-open the colour so the
        // per-line SvgRenderer ANSI parse keeps it.
        $out = (new CodeHighlighter())->highlight("/* a\nb */", 'php');
        $lines = explode("\n", $out);
        foreach ($lines as $line) {
            $this->assertStringContainsString("\x1b[38;2;98;114;164m", $line);
        }
    }

    public function testHighlightFlowsThroughSvgRendererAsFill(): void
    {
        $theme = Theme::dracula();
        $svg = SvgRenderer::dracula()->withWindow(false)->render(
            CodeHighlighter::forTheme($theme)->highlight('return 0;', 'php'),
        );
        // Keyword pink surfaces as a <text fill> in the SVG.
        $this->assertStringContainsString('fill="#ff79c6"', $svg);
        $this->assertStringContainsString('return', $svg);
    }

    public function testUnhighlightedRenderIsUnchangedByOptInFeature(): void
    {
        // Plain (unknown-language) highlight output must render identically to
        // feeding the raw code straight to the renderer — proving the feature
        // does not disturb the default path.
        $code = 'plain untyped text';
        $renderer = SvgRenderer::dark()->withWindow(false);
        $viaHighlighter = $renderer->render((new CodeHighlighter())->highlight($code, 'text'));
        $direct = $renderer->render($code);
        $this->assertSame($direct, $viaHighlighter);
    }
}

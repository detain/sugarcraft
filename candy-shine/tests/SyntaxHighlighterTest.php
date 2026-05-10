<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use SugarCraft\Shine\SyntaxHighlighter;
use SugarCraft\Shine\Theme;
use PHPUnit\Framework\TestCase;

final class SyntaxHighlighterTest extends TestCase
{
    public function testPhpHighlightsKeywordsStringsCommentsNumbers(): void
    {
        $code = '<?php
if ($n === 42) { return "hello"; } // hi';
        $out = SyntaxHighlighter::highlight($code, 'php', Theme::ansi());

        // Each token class wraps its match in an SGR escape; we don't
        // pin the exact code (depends on the ansi theme), just that
        // each substring has been styled.
        $this->assertMatchesRegularExpression('/\x1b\[[^m]+m\Q42\E\x1b\[0m/', $out);
        $this->assertMatchesRegularExpression('/\x1b\[[^m]+m"hello"\x1b\[0m/', $out);
        // 'if' is a keyword (bold + magenta in the ansi theme — two
        // SGR opens before the text, one reset after).
        $this->assertMatchesRegularExpression('/\x1b\[1m\x1b\[[^m]+mif\x1b\[0m/u', $out);
        $this->assertMatchesRegularExpression('/\x1b\[3m\x1b\[[^m]+m\/\/ hi\x1b\[0m/', $out);
    }

    public function testJsAliasResolves(): void
    {
        $code = "const x = 1;";
        $out  = SyntaxHighlighter::highlight($code, 'javascript', Theme::ansi());
        // 'const' is a JS keyword; it should be styled.
        $this->assertMatchesRegularExpression('/\x1b\[1m\x1b\[[^m]+mconst\x1b\[0m/', $out);
    }

    public function testJsonHighlightsLiteralsAndStrings(): void
    {
        $out = SyntaxHighlighter::highlight('{"k": null, "n": 12}', 'json', Theme::ansi());
        $this->assertStringContainsString('null',  $out);
        $this->assertStringContainsString('12',    $out);
        // Strings get coloured.
        $this->assertMatchesRegularExpression('/\x1b\[[^m]+m"k"\x1b\[0m/', $out);
    }

    public function testUnknownLanguageFallsBackToPlainCodeBlockStyle(): void
    {
        $code = 'some random text';
        $out  = SyntaxHighlighter::highlight($code, 'klingon', Theme::ansi());
        // Should not contain the keyword / number SGR pairs but should
        // still pass through the codeBlock style (faint = SGR 2).
        $this->assertStringContainsString("\x1b[2m", $out);
        $this->assertStringContainsString($code, $out);
    }

    public function testNoLanguageHintRouteEqualsCodeBlock(): void
    {
        $code = 'plain';
        // We pass an empty language to test the explicit branch — the
        // public Renderer also short-circuits to codeBlock for empty,
        // but the highlighter alone should treat it as unknown.
        $out  = SyntaxHighlighter::highlight($code, '', Theme::ansi());
        $this->assertStringContainsString($code, $out);
    }

    public function testPlainThemeProducesUnstyledOutput(): void
    {
        $out = SyntaxHighlighter::highlight('if (x) return 1;', 'php', Theme::plain());
        $this->assertSame('if (x) return 1;', $out);
    }

    public function testHighlightsAreNonOverlapping(): void
    {
        // The keyword 'true' inside a string must NOT get keyword
        // styling — string match wins.
        $out = SyntaxHighlighter::highlight('$x = "true";', 'php', Theme::ansi());
        // The 'true' keyword highlight is `\b(true)\b` wrapped with
        // SGR; if it had matched inside the string we'd see two
        // separate SGR pairs around 'true'. Assert there's exactly
        // one styled-string match covering the whole quoted token.
        $this->assertMatchesRegularExpression('/\x1b\[[^m]+m"true"\x1b\[0m/', $out);
    }

    public function testLineNumbersDisabledByDefault(): void
    {
        $code = "if (\$x) return 1;\nreturn 0;";
        $out  = SyntaxHighlighter::highlight($code, 'php', Theme::ansi());
        // No line numbers should be present when the feature is off.
        $this->assertStringNotContainsString('1' . "\t", $out);
    }

    public function testLineNumbersEnabledAddsLineNumbers(): void
    {
        $code = "if (\$x) return 1;\nreturn 0;";
        $out  = SyntaxHighlighter::highlight($code, 'php', Theme::ansi(), lineNumbers: true);

        // Should contain styled line numbers 1 and 2, each with italic (3)
        // and some foreground color. The grey in ansi() is ANSI 8 = RGB.
        $this->assertMatchesRegularExpression('/\x1b\[3m\x1b\[38;2;\d+;\d+;\d+m1\x1b\[0m/', $out);
        $this->assertMatchesRegularExpression('/\x1b\[3m\x1b\[38;2;\d+;\d+;\d+m2\x1b\[0m/', $out);
    }

    public function testLineNumbersMultiLinePreservesHighlighting(): void
    {
        $code = "<?php\n\$x = 42;\n// comment";
        $out  = SyntaxHighlighter::highlight($code, 'php', Theme::ansi(), lineNumbers: true);

        // Number 42 should still be highlighted (yellow ANSI 11 = RGB 255,255,0).
        $this->assertMatchesRegularExpression('/\x1b\[38;2;255;255;0m42\x1b\[0m/', $out);
        // Comment should still be styled (grey ANSI 8 + italic).
        $this->assertMatchesRegularExpression('/\x1b\[3m\x1b\[38;2;\d+;\d+;\d+m\/\/ comment\x1b\[0m/', $out);
    }

    public function testLineNumbersUseCommentStyle(): void
    {
        $code = "return 1;";
        $out  = SyntaxHighlighter::highlight($code, 'php', Theme::dracula(), lineNumbers: true);

        // Dracula comment style uses #6272a4 (RGB 98,114,164) with italic.
        // The line number '1' should be styled with the comment style.
        // italic = SGR 3, comment color = 38;2;98;114;164
        $this->assertMatchesRegularExpression('/\x1b\[3m\x1b\[38;2;98;114;164m1\x1b\[0m/', $out);
    }
}

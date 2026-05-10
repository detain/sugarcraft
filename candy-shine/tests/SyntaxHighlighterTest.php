<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shine\SyntaxHighlighter;
use SugarCraft\Shine\Theme;
use SugarCraft\Sprinkles\Style;

/**
 * Comprehensive tests for the SyntaxHighlighter class.
 *
 * Covers: PHP, JavaScript/TypeScript, Python, Go, Bash, SQL, JSON
 * highlighting, line numbers, language aliases, and unknown-language fallback.
 */
final class SyntaxHighlighterTest extends TestCase
{
    /**
     * Creates a theme with all syntax-highlighting slots populated (ANSI theme).
     */
    private static function themed(): Theme
    {
        return Theme::ansi();
    }

    /**
     * Assert that a keyword is highlighted with bold + keyword foreground color.
     * The ANSI theme uses bold + magenta (255,0,255 RGB in TrueColor).
     */
    private static function assertKeywordStyled(string $needle, string $haystack): void
    {
        // Keyword style in ANSI theme: bold + foreground(ansi(13)=magenta).
        // Output format: \x1b[1m\x1b[38;2;255;0;255m{keyword}\x1b[0m
        $pattern = '/\x1b\[1m\x1b\[38;2;255;0;255m' . preg_quote($needle, '/') . '\x1b\[0m/';
        self::assertMatchesRegularExpression($pattern, $haystack, "Keyword '$needle' should be bold+magenta styled");
    }

    /**
     * Assert that a string is highlighted with the string foreground color.
     * The ANSI theme uses green (ansi(10) = RGB 0,255,0 in TrueColor).
     */
    private static function assertStringStyled(string $needle, string $haystack): void
    {
        // String style in ANSI theme: foreground(ansi(10)=green).
        // Output format: \x1b[38;2;0;255;0m{string}\x1b[0m
        $pattern = '/\x1b\[38;2;0;255;0m' . preg_quote($needle, '/') . '\x1b\[0m/';
        self::assertMatchesRegularExpression($pattern, $haystack, "String '$needle' should be green styled");
    }

    /**
     * Assert that a number is highlighted with the number foreground color.
     * The ANSI theme uses yellow (ansi(11) = RGB 255,255,0 in TrueColor).
     */
    private static function assertNumberStyled(string $needle, string $haystack): void
    {
        // Number style in ANSI theme: foreground(ansi(11)=yellow).
        // Output format: \x1b[38;2;255;255;0m{number}\x1b[0m
        $pattern = '/\x1b\[38;2;255;255;0m' . preg_quote($needle, '/') . '\x1b\[0m/';
        self::assertMatchesRegularExpression($pattern, $haystack, "Number '$needle' should be yellow styled");
    }

    /**
     * Assert that a comment is highlighted with italic + comment foreground color.
     * The ANSI theme uses italic + grey (ansi(8) = RGB 127,127,127 in TrueColor).
     */
    private static function assertCommentStyled(string $needle, string $haystack): void
    {
        // Comment style in ANSI theme: italic + foreground(ansi(8)=grey).
        // Output format: \x1b[3m\x1b[38;2;127;127;127m{comment}\x1b[0m
        $pattern = '/\x1b\[3m\x1b\[38;2;127;127;127m' . preg_quote($needle, '/') . '\x1b\[0m/';
        self::assertMatchesRegularExpression($pattern, $haystack, "Comment '$needle' should be italic+grey styled");
    }

    // -------------------------------------------------------------------------
    // PHP
    // -------------------------------------------------------------------------

    public function testPhpHighlightsKeywords(): void
    {
        $code = '<?php if ($x) return 1;';
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed());

        self::assertKeywordStyled('if', $out);
        self::assertKeywordStyled('return', $out);
    }

    public function testPhpHighlightsStrings(): void
    {
        $code = '<?php echo "hello";';
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed());

        self::assertStringStyled('"hello"', $out);
    }

    public function testPhpHighlightsComments(): void
    {
        $code = '<?php // this is a comment';
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed());

        self::assertCommentStyled('// this is a comment', $out);
    }

    public function testPhpHighlightsNumbers(): void
    {
        $code = '<?php return 42;';
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed());

        self::assertNumberStyled('42', $out);
    }

    public function testPhpHighlightsHashComments(): void
    {
        $code = '<?php # shell-style comment';
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed());

        self::assertCommentStyled('# shell-style comment', $out);
    }

    public function testPhpHighlightsBlockComments(): void
    {
        $code = '<?php /* block comment */';
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed());

        self::assertCommentStyled('/* block comment */', $out);
    }

    public function testPhpHighlightsFloatNumbers(): void
    {
        $code = '<?php return 3.14159;';
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed());

        self::assertNumberStyled('3.14159', $out);
    }

    // -------------------------------------------------------------------------
    // JavaScript / TypeScript
    // -------------------------------------------------------------------------

    public function testJsHighlightsKeywords(): void
    {
        $code = 'const x = async () => {};';
        $out  = SyntaxHighlighter::highlight($code, 'js', self::themed());

        self::assertKeywordStyled('const', $out);
        self::assertKeywordStyled('async', $out);
    }

    public function testJsHighlightsStrings(): void
    {
        $code = "const s = 'hello';";
        $out  = SyntaxHighlighter::highlight($code, 'js', self::themed());

        self::assertStringStyled("'hello'", $out);
    }

    public function testJsHighlightsTemplateStrings(): void
    {
        $code = 'const s = `hello`;';
        $out  = SyntaxHighlighter::highlight($code, 'js', self::themed());

        self::assertStringStyled('`hello`', $out);
    }

    public function testJsHighlightsNumbers(): void
    {
        $code = 'let n = 99.5;';
        $out  = SyntaxHighlighter::highlight($code, 'js', self::themed());

        self::assertNumberStyled('99.5', $out);
    }

    public function testTsHighlightsKeywords(): void
    {
        $code = 'const fn = (x: number): void => {};';
        $out  = SyntaxHighlighter::highlight($code, 'ts', self::themed());

        self::assertKeywordStyled('const', $out);
        self::assertKeywordStyled('void', $out);
    }

    public function testTsHighlightsInterfaceKeyword(): void
    {
        $code = 'interface Foo { }';
        $out  = SyntaxHighlighter::highlight($code, 'ts', self::themed());

        self::assertKeywordStyled('interface', $out);
    }

    public function testTsHighlightsTypeKeyword(): void
    {
        $code = 'type Result = string | null;';
        $out  = SyntaxHighlighter::highlight($code, 'ts', self::themed());

        self::assertKeywordStyled('type', $out);
        self::assertKeywordStyled('null', $out);
    }

    // -------------------------------------------------------------------------
    // Python
    // -------------------------------------------------------------------------

    public function testPythonHighlightsKeywords(): void
    {
        $code = 'def foo(): pass';
        $out  = SyntaxHighlighter::highlight($code, 'python', self::themed());

        self::assertKeywordStyled('def', $out);
        self::assertKeywordStyled('pass', $out);
    }

    public function testPythonHighlightsNoneTrueFalse(): void
    {
        $code = 'x = None; y = True; z = False';
        $out  = SyntaxHighlighter::highlight($code, 'python', self::themed());

        // Note: Python keywords are case-sensitive in the highlighter
        self::assertKeywordStyled('None', $out);
        self::assertKeywordStyled('True', $out);
        self::assertKeywordStyled('False', $out);
    }

    public function testPythonHighlightsStrings(): void
    {
        $code = "s = 'hello'";
        $out  = SyntaxHighlighter::highlight($code, 'python', self::themed());

        self::assertStringStyled("'hello'", $out);
    }

    public function testPythonHighlightsComments(): void
    {
        $code = '# this is a comment';
        $out  = SyntaxHighlighter::highlight($code, 'python', self::themed());

        self::assertCommentStyled('# this is a comment', $out);
    }

    public function testPythonHighlightsNumbers(): void
    {
        $code = 'x = 42';
        $out  = SyntaxHighlighter::highlight($code, 'python', self::themed());

        self::assertNumberStyled('42', $out);
    }

    // -------------------------------------------------------------------------
    // Go
    // -------------------------------------------------------------------------

    public function testGoHighlightsKeywords(): void
    {
        $code = 'func main() { return }';
        $out  = SyntaxHighlighter::highlight($code, 'go', self::themed());

        self::assertKeywordStyled('func', $out);
        self::assertKeywordStyled('return', $out);
    }

    public function testGoHighlightsPackageImport(): void
    {
        $code = 'package main';
        $out  = SyntaxHighlighter::highlight($code, 'go', self::themed());

        self::assertKeywordStyled('package', $out);
    }

    public function testGoHighlightsNilTrueFalse(): void
    {
        $code = 'var x = nil; y := true; z := false';
        $out  = SyntaxHighlighter::highlight($code, 'go', self::themed());

        self::assertKeywordStyled('nil', $out);
        self::assertKeywordStyled('true', $out);
        self::assertKeywordStyled('false', $out);
    }

    public function testGoHighlightsStrings(): void
    {
        $code = 's := "hello"';
        $out  = SyntaxHighlighter::highlight($code, 'go', self::themed());

        self::assertStringStyled('"hello"', $out);
    }

    public function testGoHighlightsNumbers(): void
    {
        $code = 'x := 42';
        $out  = SyntaxHighlighter::highlight($code, 'go', self::themed());

        self::assertNumberStyled('42', $out);
    }

    public function testGoHighlightsComments(): void
    {
        $code = '// comment';
        $out  = SyntaxHighlighter::highlight($code, 'go', self::themed());

        self::assertCommentStyled('// comment', $out);
    }

    // -------------------------------------------------------------------------
    // Bash
    // -------------------------------------------------------------------------

    public function testBashHighlightsKeywords(): void
    {
        $code = 'if true; then echo "yes"; fi';
        $out  = SyntaxHighlighter::highlight($code, 'bash', self::themed());

        self::assertKeywordStyled('if', $out);
        self::assertKeywordStyled('then', $out);
        self::assertKeywordStyled('fi', $out);
    }

    public function testBashHighlightsFunctionKeyword(): void
    {
        $code = 'function foo() { }';
        $out  = SyntaxHighlighter::highlight($code, 'bash', self::themed());

        self::assertKeywordStyled('function', $out);
    }

    public function testBashHighlightsStrings(): void
    {
        $code = 'echo "hello"';
        $out  = SyntaxHighlighter::highlight($code, 'bash', self::themed());

        self::assertStringStyled('"hello"', $out);
    }

    public function testBashHighlightsNumbers(): void
    {
        $code = 'x=42';
        $out  = SyntaxHighlighter::highlight($code, 'bash', self::themed());

        self::assertNumberStyled('42', $out);
    }

    public function testBashHighlightsDoDone(): void
    {
        $code = 'for i in 1 2 3; do echo $i; done';
        $out  = SyntaxHighlighter::highlight($code, 'bash', self::themed());

        self::assertKeywordStyled('for', $out);
        self::assertKeywordStyled('do', $out);
        self::assertKeywordStyled('done', $out);
    }

    // -------------------------------------------------------------------------
    // SQL
    // -------------------------------------------------------------------------

    public function testSqlHighlightsKeywords(): void
    {
        // Note: SQL keywords in highlighter are lowercase (case-sensitive matching)
        $code = 'select id, name from users where active = true';
        $out  = SyntaxHighlighter::highlight($code, 'sql', self::themed());

        self::assertKeywordStyled('select', $out);
        self::assertKeywordStyled('from', $out);
        self::assertKeywordStyled('where', $out);
    }

    public function testSqlHighlightsStringInWhere(): void
    {
        // Note: SQL keywords in highlighter are lowercase
        $code = "select * from users where name = 'Alice'";
        $out  = SyntaxHighlighter::highlight($code, 'sql', self::themed());

        self::assertStringStyled("'Alice'", $out);
    }

    public function testSqlHighlightsNumbers(): void
    {
        $code = 'select 1 as one, 2 as two';
        $out  = SyntaxHighlighter::highlight($code, 'sql', self::themed());

        self::assertNumberStyled('1', $out);
        self::assertNumberStyled('2', $out);
    }

    public function testSqlHighlightsJoinKeywords(): void
    {
        // Note: SQL keywords in highlighter are lowercase
        $code = 'select * from orders inner join customers on orders.cust_id = customers.id';
        $out  = SyntaxHighlighter::highlight($code, 'sql', self::themed());

        self::assertKeywordStyled('inner', $out);
        self::assertKeywordStyled('join', $out);
    }

    public function testSqlHighlightsCaseWhen(): void
    {
        // Note: SQL keywords in highlighter are lowercase
        $code = 'select case when x > 0 then 1 else 0 end';
        $out  = SyntaxHighlighter::highlight($code, 'sql', self::themed());

        self::assertKeywordStyled('case', $out);
        self::assertKeywordStyled('when', $out);
        self::assertKeywordStyled('then', $out);
        self::assertKeywordStyled('else', $out);
        self::assertKeywordStyled('end', $out);
    }

    public function testSqlUppercaseNotHighlighted(): void
    {
        // SQL keywords are case-sensitive in the highlighter (lowercase only)
        $code = 'SELECT id FROM users';
        $out  = SyntaxHighlighter::highlight($code, 'sql', self::themed());

        // Should not have keyword-styled SELECT (bold+magenta)
        $this->assertStringNotContainsString("\x1b[1m\x1b[38;2;255;0;255mSELECT\x1b[0m", $out);
        // But should still be styled with codeBlock (faint)
        $this->assertStringContainsString("\x1b[2m", $out);
    }

    // -------------------------------------------------------------------------
    // JSON
    // -------------------------------------------------------------------------

    public function testJsonHighlightsStrings(): void
    {
        $code = '{"key": "value"}';
        $out  = SyntaxHighlighter::highlight($code, 'json', self::themed());

        // JSON uses string style for all string values.
        self::assertStringStyled('"key"', $out);
        self::assertStringStyled('"value"', $out);
    }

    public function testJsonHighlightsNumbers(): void
    {
        $code = '{"n": 42, "f": 3.14}';
        $out  = SyntaxHighlighter::highlight($code, 'json', self::themed());

        self::assertNumberStyled('42', $out);
        self::assertNumberStyled('3.14', $out);
    }

    public function testJsonHighlightsTrueFalseNull(): void
    {
        $code = '{"t": true, "f": false, "n": null}';
        $out  = SyntaxHighlighter::highlight($code, 'json', self::themed());

        // JSON 'true', 'false', 'null' are highlighted via the keywords list.
        self::assertKeywordStyled('true', $out);
        self::assertKeywordStyled('false', $out);
        self::assertKeywordStyled('null', $out);
    }

    // -------------------------------------------------------------------------
    // Language aliases
    // -------------------------------------------------------------------------

    public function testJavascriptAliasResolves(): void
    {
        $code = "const x = 1;";
        $out  = SyntaxHighlighter::highlight($code, 'javascript', self::themed());

        self::assertKeywordStyled('const', $out);
    }

    public function testTypescriptAliasResolves(): void
    {
        $code = 'const fn = (x: number): void => {};';
        $out  = SyntaxHighlighter::highlight($code, 'typescript', self::themed());

        self::assertKeywordStyled('void', $out);
    }

    public function testPyAliasResolves(): void
    {
        $code = 'def foo(): pass';
        $out  = SyntaxHighlighter::highlight($code, 'py', self::themed());

        self::assertKeywordStyled('def', $out);
    }

    public function testShAliasResolves(): void
    {
        $code = 'if true; then echo "yes"; fi';
        $out  = SyntaxHighlighter::highlight($code, 'sh', self::themed());

        self::assertKeywordStyled('if', $out);
    }

    public function testShellAliasResolves(): void
    {
        $code = 'if true; then echo "yes"; fi';
        $out  = SyntaxHighlighter::highlight($code, 'shell', self::themed());

        self::assertKeywordStyled('if', $out);
    }

    public function testZshAliasResolves(): void
    {
        $code = 'if true; then echo "yes"; fi';
        $out  = SyntaxHighlighter::highlight($code, 'zsh', self::themed());

        self::assertKeywordStyled('if', $out);
    }

    public function testGolangAliasResolves(): void
    {
        $code = 'func main() { return }';
        $out  = SyntaxHighlighter::highlight($code, 'golang', self::themed());

        self::assertKeywordStyled('func', $out);
    }

    public function testJsoncAliasResolves(): void
    {
        // jsonc is JSON with comments — should behave like JSON (no keywords).
        $code = '{"key": "value"}';
        $out  = SyntaxHighlighter::highlight($code, 'jsonc', self::themed());

        self::assertStringStyled('"key"', $out);
    }

    public function testAliasIsCaseInsensitive(): void
    {
        $code = 'const x = 1;';
        $out  = SyntaxHighlighter::highlight($code, 'JAVASCRIPT', self::themed());

        self::assertKeywordStyled('const', $out);
    }

    // -------------------------------------------------------------------------
    // Unknown / unsupported languages
    // -------------------------------------------------------------------------

    public function testUnknownLanguageFallsBackToCodeBlockStyle(): void
    {
        $code = 'some random text';
        $out  = SyntaxHighlighter::highlight($code, 'klingon', self::themed());

        // Should use codeBlock style (faint = SGR 2) and pass through unchanged.
        $this->assertStringContainsString("\x1b[2m", $out);
        $this->assertStringContainsString($code, $out);
    }

    public function testEmptyLanguageFallsBackToCodeBlockStyle(): void
    {
        $code = 'plain text';
        $out  = SyntaxHighlighter::highlight($code, '', self::themed());

        $this->assertStringContainsString($code, $out);
    }

    public function testWhitespaceOnlyLanguageFallsBack(): void
    {
        $code = 'text';
        $out  = SyntaxHighlighter::highlight($code, '   ', self::themed());

        $this->assertStringContainsString($code, $out);
    }

    // -------------------------------------------------------------------------
    // Plain theme (no styling)
    // -------------------------------------------------------------------------

    public function testPlainThemeProducesUnstyledOutput(): void
    {
        $out = SyntaxHighlighter::highlight('if (x) return 1;', 'php', Theme::plain());
        $this->assertSame('if (x) return 1;', $out);
    }

    // -------------------------------------------------------------------------
    // Non-overlapping tokenisation
    // -------------------------------------------------------------------------

    public function testStringContentIsNotHighlightedAsKeyword(): void
    {
        // The keyword 'true' inside a string must NOT get keyword styling.
        $out = SyntaxHighlighter::highlight('$x = "true";', 'php', self::themed());

        // Should have styled string "true" (green), not keyword true (magenta).
        self::assertStringStyled('"true"', $out);
        // Keyword true should NOT appear outside the string.
        $this->assertStringNotContainsString("\x1b[1m\x1b[38;2;255;0;255mtrue\x1b[0m", $out);
    }

    public function testCommentContentIsNotHighlightedAsKeyword(): void
    {
        // Keyword inside a comment should not be styled.
        $out = SyntaxHighlighter::highlight('// return 1', 'php', self::themed());

        // Comment should be styled as comment, not as keyword.
        self::assertCommentStyled('// return 1', $out);
    }

    // -------------------------------------------------------------------------
    // Line numbers
    // -------------------------------------------------------------------------

    public function testLineNumbersDisabledByDefault(): void
    {
        $code = "if (\$x) return 1;\nreturn 0;";
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed());

        $this->assertStringNotContainsString('1' . "\t", $out);
    }

    public function testLineNumbersEnabledAddsLineNumbers(): void
    {
        $code = "if (\$x) return 1;\nreturn 0;";
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed(), lineNumbers: true);

        // Line 1 should have styled line number 1 (italic + grey ANSI 8 = 127,127,127).
        $this->assertMatchesRegularExpression('/\x1b\[3m\x1b\[38;2;127;127;127m1\x1b\[0m/', $out);
        // Line 2 should have styled line number 2.
        $this->assertMatchesRegularExpression('/\x1b\[3m\x1b\[38;2;127;127;127m2\x1b\[0m/', $out);
    }

    public function testLineNumbersMultiLinePreservesHighlighting(): void
    {
        $code = "<?php\n\$x = 42;\n// comment";
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed(), lineNumbers: true);

        // Number 42 should still be highlighted.
        self::assertNumberStyled('42', $out);
        // Comment should still be styled.
        self::assertCommentStyled('// comment', $out);
    }

    public function testLineNumbersUseCommentStyle(): void
    {
        $code = "return 1;";
        $out  = SyntaxHighlighter::highlight($code, 'php', Theme::dracula(), lineNumbers: true);

        // Dracula comment colour #6272a4 (RGB 98,114,164) with italic.
        $this->assertMatchesRegularExpression('/\x1b\[3m\x1b\[38;2;98;114;164m1\x1b\[0m/', $out);
    }

    public function testLineNumbersPaddedWithSpaces(): void
    {
        $code = "line1\nline2\nline3\nline4\nline5\nline6\nline7\nline8\nline9\nline10";
        $out  = SyntaxHighlighter::highlight($code, 'text', Theme::plain(), lineNumbers: true);

        // For 10 lines, line numbers should be padded to width 2 (1-9 padded with leading space).
        // Line 1 → " 1", Line 10 → "10"
        $this->assertStringContainsString(" 1\t", $out);
        $this->assertStringContainsString("10\t", $out);
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function testEmptyCodeReturnsEmptyString(): void
    {
        $out = SyntaxHighlighter::highlight('', 'php', self::themed());
        $this->assertSame('', $out);
    }

    public function testCodeWithNoRecognisedTokens(): void
    {
        // Only variable names and symbols — no keywords, strings, numbers.
        $code = '$x = $y + $z;';
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed());

        // Should still render via codeBlock style.
        $this->assertStringContainsString($code, $out);
    }

    public function testEscapedCharactersInStrings(): void
    {
        // The code contains literal backslash-n (2 chars: \ and n), not an actual newline.
        $code = '"hello\\nworld"';
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed());

        // The escaped string should be highlighted as a string containing the literal \n.
        // The string token matches the entire "hello\nworld" including the backslash-n characters.
        $this->assertStringContainsString("\x1b[38;2;0;255;0m", $out);
        $this->assertStringContainsString('hello\nworld', $out);
    }

    public function testMultilineCodeHighlight(): void
    {
        $code = "<?php\nif (\$x) {\n    echo \$x;\n}";
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed());

        // Each line with tokens should be styled appropriately.
        self::assertKeywordStyled('if', $out);
        self::assertKeywordStyled('echo', $out);
    }

    public function testSingleQuoteStrings(): void
    {
        $code = "echo 'hello';";
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed());

        self::assertStringStyled("'hello'", $out);
    }

    public function testBacktickStrings(): void
    {
        $code = '`echo $HOME`';
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed());

        self::assertStringStyled('`echo $HOME`', $out);
    }

    public function testHTMLCommentStyleInJs(): void
    {
        $code = '<!-- html comment -->';
        $out  = SyntaxHighlighter::highlight($code, 'js', self::themed());

        self::assertCommentStyled('<!-- html comment -->', $out);
    }

    public function testXmlCommentStyleInTs(): void
    {
        $code = '<!-- xml comment -->';
        $out  = SyntaxHighlighter::highlight($code, 'ts', self::themed());

        self::assertCommentStyled('<!-- xml comment -->', $out);
    }

    public function testTrailingCodeAfterLastToken(): void
    {
        $code = 'return 42; // comment';
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed());

        // Should contain both the number and the comment.
        self::assertNumberStyled('42', $out);
        self::assertCommentStyled('// comment', $out);
    }

    public function testLeadingCodeBeforeFirstToken(): void
    {
        $code = '<?php return 1;';
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed());

        // '<?php' is not styled but should appear before 'return'.
        $this->assertStringContainsString('<?php', $out);
        self::assertKeywordStyled('return', $out);
    }

    public function testMultipleStrings(): void
    {
        $code = '"one" + "two" + \'three\'';
        $out  = SyntaxHighlighter::highlight($code, 'js', self::themed());

        self::assertStringStyled('"one"', $out);
        self::assertStringStyled('"two"', $out);
        self::assertStringStyled("'three'", $out);
    }

    public function testMultipleNumbers(): void
    {
        $code = '1 + 2 + 3';
        $out  = SyntaxHighlighter::highlight($code, 'php', self::themed());

        self::assertNumberStyled('1', $out);
        self::assertNumberStyled('2', $out);
        self::assertNumberStyled('3', $out);
    }

    public function testLanguageNormalization(): void
    {
        // Uppercase, leading/trailing whitespace should be handled.
        $code = 'const x = 1;';
        $out  = SyntaxHighlighter::highlight($code, '  JS  ', self::themed());

        self::assertKeywordStyled('const', $out);
    }
}

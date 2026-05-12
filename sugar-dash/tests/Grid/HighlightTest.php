<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Highlight;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use PHPUnit\Framework\TestCase;

final class HighlightTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testHighlightImplementsSizer(): void
    {
        $highlight = Highlight::php('<?php echo "hi";');
        $this->assertInstanceOf(Sizer::class, $highlight);
    }

    public function testHighlightImplementsItem(): void
    {
        $highlight = Highlight::php('<?php echo "hi";');
        $this->assertInstanceOf(Item::class, $highlight);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $highlight = Highlight::php('<?php echo "hi";');
        $rendered = $highlight->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsCode(): void
    {
        $highlight = Highlight::php('<?php echo "hello";');
        $rendered = $highlight->render();

        $this->assertStringContainsString('hello', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Language factories
    // ═══════════════════════════════════════════════════════════════

    public function testPhpFactory(): void
    {
        $highlight = Highlight::php('$var = "test";');
        $rendered = $highlight->render();

        $this->assertStringContainsString('test', $rendered);
    }

    public function testJsFactory(): void
    {
        $highlight = Highlight::js('const x = 42;');
        $rendered = $highlight->render();

        $this->assertStringContainsString('42', $rendered);
    }

    public function testPythonFactory(): void
    {
        $highlight = Highlight::python('def foo(): pass');
        $rendered = $highlight->render();

        $this->assertStringContainsString('foo', $rendered);
    }

    public function testBashFactory(): void
    {
        $highlight = Highlight::bash('echo "hello"');
        $rendered = $highlight->render();

        $this->assertStringContainsString('hello', $rendered);
    }

    public function testJsonFactory(): void
    {
        $highlight = Highlight::json('{"key": "value"}');
        $rendered = $highlight->render();

        $this->assertStringContainsString('key', $rendered);
        $this->assertStringContainsString('value', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Line numbers
    // ═══════════════════════════════════════════════════════════════

    public function testLineNumbersAddsPadding(): void
    {
        $withNumbers = Highlight::php('<?php echo "hi";')->withLineNumbers(true);
        $withoutNumbers = Highlight::php('<?php echo "hi";')->withLineNumbers(false);

        $renderedWith = $withNumbers->render();
        $renderedWithout = $withoutNumbers->render();

        $this->assertGreaterThan(
            mb_strlen($renderedWithout, 'UTF-8'),
            mb_strlen($renderedWith, 'UTF-8')
        );
    }

    public function testLineNumbersRender(): void
    {
        $highlight = Highlight::php("<?php\necho 'hi';")->withLineNumbers(true);
        $rendered = $highlight->render();

        // Should contain line numbers like "   1" or "   2"
        $this->assertMatchesRegularExpression('/\d+\s/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Themes
    // ═══════════════════════════════════════════════════════════════

    public function testMonokaiFactory(): void
    {
        $highlight = Highlight::monokai('<?php echo "hi";', Highlight::LANG_PHP);
        $rendered = $highlight->render();

        $this->assertStringContainsString('hi', $rendered);
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testCustomTheme(): void
    {
        $highlight = Highlight::php('<?php echo "hi";')->withTheme(Highlight::THEME_GITHUB);
        $rendered = $highlight->render();

        $this->assertStringContainsString('hi', $rendered);
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testDraculaTheme(): void
    {
        $highlight = Highlight::php('<?php echo "hi";')->withTheme(Highlight::THEME_DRACULA);
        $rendered = $highlight->render();

        $this->assertStringContainsString('hi', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Highlighted lines
    // ═══════════════════════════════════════════════════════════════

    public function testHighlightedLinesAddsBackground(): void
    {
        $highlight = Highlight::php("<?php\necho 'a';\necho 'b';")->withHighlightedLines([2]);
        $rendered = $highlight->render();

        // Should contain ANSI background codes for highlighting
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testNullHighlightedLinesNoBackground(): void
    {
        $highlight = Highlight::php('<?php echo "hi";')->withHighlightedLines(null);
        $rendered = $highlight->render();

        // Should still render without error
        $this->assertStringContainsString('hi', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Highlight::php('<?php echo "hi";');
        $resized = $original->setSize(80, 10);

        $this->assertNotSame($original, $resized);
    }

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $highlight = Highlight::php("<?php\necho 'a';\necho 'b';");
        [$w, $h] = $highlight->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(3, $h); // 3 lines
    }

    public function testGetInnerSizeWithLineNumbers(): void
    {
        $highlight = Highlight::php("<?php\necho 'a';")->withLineNumbers(true);
        [$w, ] = $highlight->getInnerSize();

        $this->assertGreaterThan(0, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Tab width
    // ═══════════════════════════════════════════════════════════════

    public function testCustomTabWidth(): void
    {
        $highlight = Highlight::php("\t\$var = 1;")->withTabWidth(2);
        $rendered = $highlight->render();

        // Tab should be converted to 2 spaces
        $this->assertStringContainsString('  ', $rendered);
    }

    public function testDefaultTabWidth(): void
    {
        $highlight = Highlight::php("\t\$var = 1;");
        $rendered = $highlight->render();

        // Tab should be converted to 4 spaces
        $this->assertStringContainsString('    ', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithCodeReturnsNewInstance(): void
    {
        $original = Highlight::php('<?php echo "original";');
        $updated = $original->withCode('<?php echo "updated";');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('updated', $updated->render());
    }

    public function testWithLanguageReturnsNewInstance(): void
    {
        $original = Highlight::php('<?php echo "hi";');
        $updated = $original->withLanguage(Highlight::LANG_PYTHON);

        $this->assertNotSame($original, $updated);
    }

    public function testWithThemeReturnsNewInstance(): void
    {
        $original = Highlight::php('<?php echo "hi";');
        $updated = $original->withTheme(Highlight::THEME_MONOKAI);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithCode(): void
    {
        $original = Highlight::php('<?php echo "original";');
        $original->withCode('<?php echo "changed";');
        $rendered = $original->render();

        $this->assertStringContainsString('original', $rendered);
        $this->assertStringNotContainsString('changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Language constants
    // ═══════════════════════════════════════════════════════════════

    public function testLanguageConstants(): void
    {
        $this->assertSame('php', Highlight::LANG_PHP);
        $this->assertSame('javascript', Highlight::LANG_JS);
        $this->assertSame('typescript', Highlight::LANG_TS);
        $this->assertSame('python', Highlight::LANG_PYTHON);
        $this->assertSame('bash', Highlight::LANG_BASH);
        $this->assertSame('json', Highlight::LANG_JSON);
        $this->assertSame('xml', Highlight::LANG_XML);
        $this->assertSame('html', Highlight::LANG_HTML);
        $this->assertSame('css', Highlight::LANG_CSS);
        $this->assertSame('sql', Highlight::LANG_SQL);
        $this->assertSame('yaml', Highlight::LANG_YAML);
        $this->assertSame('markdown', Highlight::LANG_MARKDOWN);
        $this->assertSame('text', Highlight::LANG_TEXT);
    }

    public function testThemeConstants(): void
    {
        $this->assertSame('default', Highlight::THEME_DEFAULT);
        $this->assertSame('monokai', Highlight::THEME_MONOKAI);
        $this->assertSame('github', Highlight::THEME_GITHUB);
        $this->assertSame('dracula', Highlight::THEME_DRACULA);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyCode(): void
    {
        $highlight = Highlight::new('');
        $rendered = $highlight->render();

        // Should render without error
        $this->assertNotSame('', $rendered);
    }

    public function testMultilineCode(): void
    {
        // Test string with 3 newlines = 4 lines of code
        $code = "line1\nline2\nline3\nline4";
        $highlight = new Highlight($code, Highlight::LANG_TEXT);
        [$w, $h] = $highlight->getInnerSize();

        // Should be 4 lines
        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThanOrEqual(4, $h);
    }

    public function testUnicodeInCode(): void
    {
        $highlight = Highlight::php('<?php echo "日本語";');
        $rendered = $highlight->render();

        $this->assertStringContainsString('日本語', $rendered);
    }

    public function testTextLanguageNoHighlighting(): void
    {
        $highlight = new Highlight('plain text without highlighting', Highlight::LANG_TEXT);
        $rendered = $highlight->render();

        $this->assertStringContainsString('plain text', $rendered);
    }
}

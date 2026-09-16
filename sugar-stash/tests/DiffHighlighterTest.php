<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SugarCraft\Core\Syntax\TokenKind;
use SugarCraft\Stash\DiffHighlighter;
use PHPUnit\Framework\TestCase;

/**
 * Parse half of the step-10.06 diff syntax pass: extension→language mapping
 * and the opt-in tokenisation of diff body lines (null ⇒ plain render).
 * Presentation compositing is pinned separately in RendererTest.
 */
final class DiffHighlighterTest extends TestCase
{
    /** @return list<array{string, string}> */
    public static function pathProvider(): array
    {
        return [
            'php file'            => ['src/Foo.php', 'php'],
            'uppercase extension' => ['App.PHP', 'php'],
            'plain js'            => ['assets/app.js', 'js'],
            'module js'           => ['index.mjs', 'js'],
            'commonjs'            => ['legacy.cjs', 'js'],
            'react jsx'           => ['ui/Widget.jsx', 'js'],
            'typescript'          => ['src/main.ts', 'ts'],
            'react tsx'           => ['ui/Widget.tsx', 'ts'],
            'python'              => ['tools/sync.py', 'python'],
            'go'                  => ['cmd/main.go', 'go'],
            'shell'               => ['scripts/deploy.sh', 'bash'],
            'zsh'                 => ['.zshrc.local.zsh', 'bash'],
            'json'                => ['composer.json', 'json'],
            'jsonc'               => ['tsconfig.jsonc', 'json'],
            'sql'                 => ['migrations/init.sql', 'sql'],
            // Unrecognised shapes answer '' so the caller renders plain:
            'markdown'            => ['README.md', ''],
            'dotfile'             => ['.gitignore', ''],
            'no extension'        => ['LICENSE', ''],
            'unknown extension'   => ['data.xyz', ''],
            'lockfile'            => ['composer.lock', ''],
        ];
    }

    #[DataProvider('pathProvider')]
    public function testLanguageForPathMapsOnlyLexerLanguages(string $path, string $expected): void
    {
        $this->assertSame($expected, DiffHighlighter::languageForPath($path));
    }

    public function testTokenizesKeywordsStringsNumbersComments(): void
    {
        $spans = DiffHighlighter::tokenizeLine('+function f($n) { return "x"; // note }', 'php');

        $this->assertNotNull($spans);
        $kinds = array_map(static fn ($s) => $s->kind->name, $spans);
        $this->assertContains('Keyword', $kinds);
        $this->assertContains('StringToken', $kinds);
        $this->assertContains('Comment', $kinds);

        $keywordTexts = array_values(array_filter(
            $spans,
            static fn ($s) => $s->kind === TokenKind::Keyword,
        ));
        $this->assertSame('function', $keywordTexts[0]->text);
    }

    public function testSpanTextsRebuildTheContentByteForByte(): void
    {
        $line = '-$total = 42 + 1;';
        $spans = DiffHighlighter::tokenizeLine($line, 'php');

        $this->assertNotNull($spans);
        $rebuilt = implode('', array_map(static fn ($s) => $s->text, $spans));
        $this->assertSame(substr($line, 1), $rebuilt);
    }

    public function testNumbersAreTokenizedEvenWithoutKeywords(): void
    {
        $spans = DiffHighlighter::tokenizeLine('+42', 'sql');

        $this->assertNotNull($spans);
        $this->assertSame(TokenKind::Number, $spans[0]->kind);
        $this->assertSame('42', $spans[0]->text);
    }

    /** @return list<array{string}> */
    public static function nonBodyLineProvider(): array
    {
        return [
            'file header minus' => ['--- a/src/Foo.php'],
            'file header plus'  => ['+++ b/src/Foo.php'],
            // 'const' is a PHP keyword: without the header guard this line
            // WOULD tokenize, so it discriminates the metadata refusal.
            'file header plus with keyword' => ['+++ b/const.php'],
            'hunk header'       => ['@@ -1,3 +1,4 @@'],
            'diff header'       => ['diff --git a/x.php b/x.php'],
            'index line'        => ['index 1234567..89abcde 100644'],
            'no-newline marker' => ['\\ No newline at end of file'],
            'new file mode'     => ['new file mode 100644'],
        ];
    }

    #[DataProvider('nonBodyLineProvider')]
    public function testHeaderAndMetadataLinesNeverTokenize(string $line): void
    {
        $this->assertNull(DiffHighlighter::tokenizeLine($line, 'php'));
    }

    public function testEmptyLanguageYieldsNull(): void
    {
        $this->assertNull(DiffHighlighter::tokenizeLine('+function f() {}', ''));
    }

    public function testEmptyLineYieldsNull(): void
    {
        $this->assertNull(DiffHighlighter::tokenizeLine('', 'php'));
        $this->assertNull(DiffHighlighter::tokenizeLine('+', 'php'));
    }

    public function testAllPlainContentYieldsNullSoPlainPathRenders(): void
    {
        $this->assertNull(DiffHighlighter::tokenizeLine('+hello world', 'php'));
        $this->assertNull(DiffHighlighter::tokenizeLine(' just context', 'php'));
    }

    public function testUnknownLanguageContentYieldsNull(): void
    {
        // 'txt' is not in the lexer's language set: even keyword-shaped text
        // must degrade to the plain path, never to false accents.
        $this->assertNull(DiffHighlighter::tokenizeLine('+function example', 'txt'));
    }

    public function testContextAndMinusLinesAlsoTokenize(): void
    {
        $this->assertNotNull(DiffHighlighter::tokenizeLine(' return $x;', 'php'));
        $this->assertNotNull(DiffHighlighter::tokenizeLine('-return $x;', 'php'));
        $this->assertNotNull(DiffHighlighter::tokenizeLine('+return $x;', 'php'));
    }
}

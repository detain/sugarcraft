<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Syntax;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Syntax\Highlighter;
use SugarCraft\Core\Syntax\RegexHighlighter;
use SugarCraft\Core\Syntax\TokenKind;
use SugarCraft\Core\Syntax\TokenSpan;

/**
 * Tests for the pure regex tokeniser extracted from sugar-markdown.
 *
 * These assert the {@see TokenSpan} sequence (kind + text), never ANSI —
 * styling is the consumer's concern. The load-bearing invariant is that the
 * spans fully cover the input in order, so concatenating every span's text
 * reproduces the source exactly.
 */
final class RegexHighlighterTest extends TestCase
{
    private static function tokenize(string $code, string $language): array
    {
        return (new RegexHighlighter())->tokenize($code, $language);
    }

    /**
     * @param list<TokenSpan> $spans
     * @return list<array{TokenKind, string}>
     */
    private static function pairs(array $spans): array
    {
        return array_map(
            static fn(TokenSpan $s): array => [$s->kind, $s->text],
            $spans,
        );
    }

    /** Concatenating every span's text must reproduce the input byte-for-byte. */
    private static function assertFullCoverage(string $code, array $spans): void
    {
        $joined = '';
        foreach ($spans as $s) {
            $joined .= $s->text;
        }
        self::assertSame($code, $joined, 'spans must fully cover the input in order');
    }

    public function testImplementsHighlighterInterface(): void
    {
        self::assertInstanceOf(Highlighter::class, new RegexHighlighter());
    }

    public function testPhpProducesKeywordStringNumberCommentPlainSpans(): void
    {
        $code = '<?php if ($x) return "hi"; // 42';
        $spans = self::tokenize($code, 'php');

        self::assertFullCoverage($code, $spans);
        self::assertSame([
            [TokenKind::Plain, '<?php '],
            [TokenKind::Keyword, 'if'],
            [TokenKind::Plain, ' ($x) '],
            [TokenKind::Keyword, 'return'],
            [TokenKind::Plain, ' '],
            [TokenKind::StringToken, '"hi"'],
            [TokenKind::Plain, '; '],
            [TokenKind::Comment, '// 42'],
        ], self::pairs($spans));
    }

    public function testNumberSpan(): void
    {
        $spans = self::tokenize('return 3.14159;', 'php');
        self::assertContains([TokenKind::Number, '3.14159'], self::pairs($spans));
        self::assertFullCoverage('return 3.14159;', $spans);
    }

    public function testStringContentIsNotSplitIntoKeyword(): void
    {
        // The keyword 'true' inside a string must stay part of the string span.
        $spans = self::tokenize('$x = "true";', 'php');
        $pairs = self::pairs($spans);

        self::assertContains([TokenKind::StringToken, '"true"'], $pairs);
        self::assertNotContains([TokenKind::Keyword, 'true'], $pairs);
    }

    public function testCommentContentIsNotSplitIntoKeyword(): void
    {
        $spans = self::tokenize('// return 1', 'php');
        self::assertSame([[TokenKind::Comment, '// return 1']], self::pairs($spans));
    }

    public function testJsonSpecialCaseTreatsLiteralsAsKeywords(): void
    {
        $code = '{"t": true, "n": null, "x": 42}';
        $spans = self::tokenize($code, 'json');
        $pairs = self::pairs($spans);

        self::assertFullCoverage($code, $spans);
        self::assertContains([TokenKind::StringToken, '"t"'], $pairs);
        self::assertContains([TokenKind::Keyword, 'true'], $pairs);
        self::assertContains([TokenKind::Keyword, 'null'], $pairs);
        self::assertContains([TokenKind::Number, '42'], $pairs);
    }

    public function testUnknownLanguageYieldsSinglePlainSpan(): void
    {
        $code = 'some random text';
        $spans = self::tokenize($code, 'klingon');

        self::assertSame([[TokenKind::Plain, $code]], self::pairs($spans));
    }

    public function testEmptyLanguageYieldsSinglePlainSpan(): void
    {
        $code = 'plain text';
        self::assertSame([[TokenKind::Plain, $code]], self::pairs(self::tokenize($code, '')));
    }

    public function testWhitespaceOnlyLanguageYieldsSinglePlainSpan(): void
    {
        $code = 'text';
        self::assertSame([[TokenKind::Plain, $code]], self::pairs(self::tokenize($code, '   ')));
    }

    public function testEmptyCodeWithKnownLanguageYieldsNoSpans(): void
    {
        self::assertSame([], self::tokenize('', 'php'));
    }

    public function testAliasResolves(): void
    {
        // 'javascript' → 'js' keyword table.
        $pairs = self::pairs(self::tokenize('const x = 1;', 'javascript'));
        self::assertContains([TokenKind::Keyword, 'const'], $pairs);
    }

    public function testAliasIsCaseInsensitiveAndTrimmed(): void
    {
        $pairs = self::pairs(self::tokenize('const x = 1;', '  JAVASCRIPT  '));
        self::assertContains([TokenKind::Keyword, 'const'], $pairs);
    }

    public function testJsoncAliasBehavesLikeJson(): void
    {
        // jsonc → json: no language keywords beyond true/false/null.
        $pairs = self::pairs(self::tokenize('{"key": "value"}', 'jsonc'));
        self::assertContains([TokenKind::StringToken, '"key"'], $pairs);
        self::assertNotContains([TokenKind::Keyword, 'key'], $pairs);
    }

    public function testSqlKeywordMatchingIsCaseSensitive(): void
    {
        // Lowercase keyword list only: lowercase select/from ARE keywords…
        $lower = self::pairs(self::tokenize('select id from users', 'sql'));
        self::assertContains([TokenKind::Keyword, 'select'], $lower);
        self::assertContains([TokenKind::Keyword, 'from'], $lower);

        // …but the uppercase form matches nothing → one Plain span.
        $upper = self::pairs(self::tokenize('SELECT id FROM users', 'sql'));
        self::assertSame([[TokenKind::Plain, 'SELECT id FROM users']], $upper);
    }

    public function testOversizedInputYieldsSinglePlainSpan(): void
    {
        // Just over the 1 MB cap: 150_000 * 7 bytes = 1_050_000.
        $code = str_repeat('x = 1; ', 150_000);
        self::assertGreaterThan(1_000_000, strlen($code));

        $spans = self::tokenize($code, 'php');

        self::assertSame([[TokenKind::Plain, $code]], self::pairs($spans));
        // At a small size the same content WOULD get a Number span, so the
        // single-Plain-span result above is a meaningful cap, not vacuous.
        self::assertContains([TokenKind::Number, '1'], self::pairs(self::tokenize('x = 1;', 'php')));
    }

    public function testUnterminatedBlockCommentDegradesLinearly(): void
    {
        $code = '/* ' . str_repeat('x', 50000) . ' no close';
        $start = microtime(true);
        $spans = self::tokenize($code, 'php');
        $elapsed = microtime(true) - $start;

        self::assertLessThan(1.0, $elapsed, 'unterminated comment took too long — possible backtracking');
        self::assertFullCoverage($code, $spans);
    }

    public function testTokenSpanFactoryMatchesConstructor(): void
    {
        $a = TokenSpan::of(TokenKind::Keyword, 'if', 4);
        self::assertSame(TokenKind::Keyword, $a->kind);
        self::assertSame('if', $a->text);
        self::assertSame(4, $a->offset);
    }

    public function testSpanOffsetsPointIntoOriginalCode(): void
    {
        $code = 'x = 42;';
        $spans = self::tokenize($code, 'php');
        foreach ($spans as $span) {
            self::assertSame($span->text, substr($code, $span->offset, strlen($span->text)));
        }
    }

    // -----------------------------------------------------------------
    // Extended language set: per-language comment/string rules
    // -----------------------------------------------------------------

    /**
     * One representative sample per language added beyond the original seven:
     * the source plus the exact keyword / string / comment spans it must yield.
     *
     * @return array<string, array{string, string, string, string, string}> language id => [language, code, keyword, string, comment]
     */
    public static function newLanguages(): array
    {
        return [
            'rust' => ['rust', 'fn main() { let s = "hi"; /* greet */ }', 'fn', '"hi"', '/* greet */'],
            'java' => ['java', 'class App { String s = "x"; /* note */ }', 'class', '"x"', '/* note */'],
            'c' => ['c', 'int main(void) { const char* s = "x"; /* note */ }', 'int', '"x"', '/* note */'],
            'cpp' => ['cpp', 'template <typename T> T f() { return "x"; /* n */ }', 'template', '"x"', '/* n */'],
            'ruby' => ['ruby', "def f\n  s = \"x\"\n  # note\nend", 'def', '"x"', '# note'],
            'yaml' => ['yaml', "flag: true\nname: \"x\"\n# note", 'true', '"x"', '# note'],
            'html' => ['html', '<div class="x"> <!-- note --> </div>', 'div', '"x"', '<!-- note -->'],
            'css' => ['css', 'p { color: "x"; /* note */ }', 'color', '"x"', '/* note */'],
        ];
    }

    #[DataProvider('newLanguages')]
    public function testEachNewLanguageTokenizesKeywordStringAndComment(
        string $language,
        string $code,
        string $keyword,
        string $string,
        string $comment,
    ): void {
        $spans = self::tokenize($code, $language);
        $pairs = self::pairs($spans);

        self::assertFullCoverage($code, $spans);
        self::assertContains([TokenKind::Keyword, $keyword], $pairs, $language . ' keyword span');
        self::assertContains([TokenKind::StringToken, $string], $pairs, $language . ' string span');
        self::assertContains([TokenKind::Comment, $comment], $pairs, $language . ' comment span');
    }

    /**
     * @return array<string, array{string, string, string}> alias => [alias, code, keyword]
     */
    public static function newLanguageAliases(): array
    {
        return [
            'rs' => ['rs', 'fn main() {}', 'fn'],
            'rb' => ['rb', 'def f; end', 'def'],
            'yml' => ['yml', 'flag: true', 'true'],
            'cc' => ['cc', 'int main() {}', 'int'],
            'hpp' => ['hpp', 'class X {}', 'class'],
            'h' => ['h', 'int x;', 'int'],
            'htm' => ['htm', '<div></div>', 'div'],
            'jsx' => ['jsx', 'const x = 1;', 'const'],
            'tsx' => ['tsx', 'interface I {}', 'interface'],
        ];
    }

    #[DataProvider('newLanguageAliases')]
    public function testEachNewAliasResolvesToItsLanguageTable(string $alias, string $code, string $keyword): void
    {
        $pairs = self::pairs(self::tokenize($code, $alias));
        self::assertContains([TokenKind::Keyword, $keyword], $pairs, $alias . ' alias must reach its keyword table');
    }

    public function testRustHashIsNotALineCommentMarker(): void
    {
        // Rust attributes start with '#': only the // line rule may comment.
        $code = '#[derive(Debug)] fn main() {}';
        $pairs = self::pairs(self::tokenize($code, 'rust'));

        foreach ($pairs as [$kind, $text]) {
            self::assertNotSame(TokenKind::Comment, $kind, 'rust treated ' . $text . ' as a comment');
        }
        self::assertContains([TokenKind::Keyword, 'fn'], $pairs);
    }

    public function testDoubleSlashLanguagesAllProduceLineComments(): void
    {
        // Positive pin for the '//' line rule: rust, java, c and cpp share it.
        // Without it a neutered line marker would pass silently.
        foreach (['rust', 'java', 'c', 'cpp'] as $language) {
            $pairs = self::pairs(self::tokenize('x // trailing', $language));
            self::assertContains([TokenKind::Comment, '// trailing'], $pairs, $language);
        }
    }

    public function testBacktickStringRuleIsSetPerLanguage(): void
    {
        // Backticks are a per-language rule: rust must not swallow them...
        $pairs = self::pairs(self::tokenize('let x = `y`;', 'rust'));
        self::assertNotContains([TokenKind::StringToken, '`y`'], $pairs);

        // ...while ruby's backtick:true must actually produce the string —
        // without this positive leg, flipping the rule to false stays green.
        $ruby = self::pairs(self::tokenize('x = `y`', 'ruby'));
        self::assertContains([TokenKind::StringToken, '`y`'], $ruby, 'ruby backtick string must tokenise');
    }

    public function testCssHashIsNotALineCommentMarker(): void
    {
        $code = 'body { color: #fff; /* real */ }';
        $pairs = self::pairs(self::tokenize($code, 'css'));

        $comments = array_values(array_filter($pairs, static fn(array $p): bool => $p[0] === TokenKind::Comment));
        self::assertCount(1, $comments);
        self::assertSame('/* real */', $comments[0][1]);
    }

    public function testHtmlOnlyCommentFormIsTheHtmlComment(): void
    {
        // '<!--' is the html rule; '// x' stays plain text inside html.
        $pairs = self::pairs(self::tokenize('<p> // x </p> <!-- real -->', 'html'));

        $comments = array_values(array_filter($pairs, static fn(array $p): bool => $p[0] === TokenKind::Comment));
        self::assertCount(1, $comments);
        self::assertSame('<!-- real -->', $comments[0][1]);
    }

    public function testUnterminatedStringInNewLanguageDegradesLinearly(): void
    {
        $code = '"' . str_repeat('a', 20000);
        $start = microtime(true);
        $spans = self::tokenize($code, 'rust');
        $elapsed = microtime(true) - $start;

        self::assertLessThan(1.0, $elapsed, 'unterminated string took too long — possible backtracking');
        self::assertFullCoverage($code, $spans);
    }

    public function testUnterminatedHtmlCommentInHtmlDegradesLinearly(): void
    {
        $code = '<!--' . str_repeat('-', 20000);
        $start = microtime(true);
        $spans = self::tokenize($code, 'html');
        $elapsed = microtime(true) - $start;

        self::assertLessThan(1.0, $elapsed, 'unterminated HTML comment took too long — possible backtracking');
        self::assertFullCoverage($code, $spans);
    }

    // -----------------------------------------------------------------
    // languageForPath: extension → language mapping
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{string, string|null}> case label => [path, expected language]
     */
    public static function pathExtensions(): array
    {
        return [
            'php' => ['src/Bootstrap.php', 'php'],
            'js' => ['web/app.js', 'js'],
            'mjs' => ['lib/mod.mjs', 'js'],
            'cjs' => ['lib/legacy.cjs', 'js'],
            'jsx' => ['ui/View.jsx', 'js'],
            'ts' => ['src/main.ts', 'ts'],
            'tsx' => ['src/App.tsx', 'ts'],
            'json' => ['package.json', 'json'],
            'jsonc' => ['tsconfig.jsonc', 'json'],
            'py' => ['tools/build.py', 'python'],
            'pyw' => ['tools/gui.pyw', 'python'],
            'go' => ['cmd/main.go', 'go'],
            'sh' => ['scripts/deploy.sh', 'bash'],
            'bash' => ['scripts/deploy.bash', 'bash'],
            'zsh' => ['conf/zsh/zdotdir.zsh', 'bash'],
            'sql' => ['db/schema.sql', 'sql'],
            'rs' => ['crate/lib.rs', 'rust'],
            'java' => ['pkg/App.java', 'java'],
            'c' => ['src/util.c', 'c'],
            'h' => ['include/util.h', 'c'],
            'cpp' => ['src/engine.cpp', 'cpp'],
            'cc' => ['src/engine.cc', 'cpp'],
            'cxx' => ['src/engine.cxx', 'cpp'],
            'hpp' => ['include/engine.hpp', 'cpp'],
            'hh' => ['include/engine.hh', 'cpp'],
            'hxx' => ['include/engine.hxx', 'cpp'],
            'ruby' => ['lib/gem.rb', 'ruby'],
            'yaml' => ['conf/app.yaml', 'yaml'],
            'yml' => ['docker-compose.yml', 'yaml'],
            'html' => ['site/index.html', 'html'],
            'htm' => ['site/old.htm', 'html'],
            'css' => ['web/style.css', 'css'],
            'uppercase' => ['SRC/MAIN.RS', 'rust'],
            'no-extension' => ['Makefile', null],
            'dotfile' => ['.bashrc', null],
            'unknown-extension' => ['notes.xyz', null],
            'double-extension' => ['archive.tar.gz', null],
            'empty-path' => ['', null],
        ];
    }

    #[DataProvider('pathExtensions')]
    public function testLanguageForPathMapsExtensionToRecognisedLanguage(string $path, ?string $expected): void
    {
        self::assertSame($expected, RegexHighlighter::languageForPath($path));

        if ($expected !== null) {
            // The returned id must be one tokenize() actually recognises: a
            // string rule fires for every recognised language, while an
            // unknown id collapses to a single Plain span.
            $pairs = self::pairs(self::tokenize('probe "lit"', $expected));
            self::assertContains([TokenKind::StringToken, '"lit"'], $pairs, $expected . ' must be recognised by tokenize');
        }
    }
}

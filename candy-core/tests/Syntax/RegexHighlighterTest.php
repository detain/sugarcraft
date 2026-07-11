<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Syntax;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Syntax\Highlighter;
use SugarCraft\Core\Syntax\RegexHighlighter;
use SugarCraft\Core\Syntax\TokenKind;
use SugarCraft\Core\Syntax\TokenSpan;

/**
 * Tests for the pure regex tokeniser extracted from candy-shine.
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
}

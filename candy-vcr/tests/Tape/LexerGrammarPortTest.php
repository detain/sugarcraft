<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;

/**
 * E15 / tracker #81 — the vhs lexical grammar facts ported into the tape
 * lexer: TOKEN_JSON and TOKEN_REGEX kinds, the five `#`-literal delimiter
 * pairs, the synthesized JSON closer, and the token-KIND gates.
 *
 * Every assertion here re-states a fact measured against the real `vhs`
 * binary by sugar-crush/tests/VhsTapeContractTest.php (the three-oracle
 * sweep); that file owns the oracle evidence, this one owns the port.
 */
final class LexerGrammarPortTest extends TestCase
{
    private Lexer $lexer;

    private Parser $parser;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
        $this->parser = new Parser();
    }

    /** @return list<\SugarCraft\Vcr\Tape\Token> */
    private function lex(string $source): array
    {
        return $this->lexer->tokenize($source);
    }

    public function testTheNewTokenKindsExist(): void
    {
        self::assertSame('JSON', Lexer::TOKEN_JSON);
        self::assertSame('REGEX', Lexer::TOKEN_REGEX);
    }

    // ---------------------------------------------------------------- JSON

    public function testSetThemeJsonKeepsItsShapeAndStopsAtTheCloser(): void
    {
        $tokens = $this->lex('Set Theme {"background": "#1e1e2e"}');

        self::assertCount(1, $tokens);
        self::assertSame(Lexer::TOKEN_SET, $tokens[0]->type);
        [$key, $value] = explode("\x00", $tokens[0]->value, 2);
        self::assertSame('Theme', $key);
        self::assertSame('{"background": "#1e1e2e"}', $value);
    }

    public function testJsonCloserIsSynthesizedWhenTheRunNeverCloses(): void
    {
        $tokens = $this->lex('Set Theme {"a": {');

        self::assertCount(1, $tokens);
        [$key, $value] = explode("\x00", $tokens[0]->value, 2);
        self::assertSame('Theme', $key);
        self::assertSame('{"a": {}}', $value, 'upstream readJSON fabricates the missing closers at EOF');
    }

    public function testAHashInsideJsonIsLiteralAndTheLaterDirectiveStaysLive(): void
    {
        // Upstream probe: `Set Theme {a#b} Set Shell "sh"` is TWO Sets — the
        // brace-delimited `#` must not be mistaken for a comment.
        $tokens = $this->lex('Set Theme {a#b} Set Shell "sh"');

        self::assertCount(2, $tokens);
        self::assertSame(Lexer::TOKEN_SET, $tokens[0]->type);
        self::assertSame(Lexer::TOKEN_SET, $tokens[1]->type);
        self::assertSame('Shell', explode("\x00", $tokens[1]->value, 2)[0]);
    }

    public function testBracesInsideJsonStringsDoNotAffectDepth(): void
    {
        $tokens = $this->lex('Set Theme {"s": "}{"} Sleep 1s');

        self::assertCount(2, $tokens);
        self::assertSame(Lexer::TOKEN_SET, $tokens[0]->type);
        [, $value] = explode("\x00", $tokens[0]->value, 2);
        self::assertSame('{"s": "}{"}', $value);
        self::assertSame(Lexer::TOKEN_SLEEP, $tokens[1]->type);
    }

    // ---------------------------------------------------------------- REGEX

    public function testRegexValueEndsAtItsCloserSoTheNextDirectiveSurvives(): void
    {
        // The exact delimiter-swallow case the oracle sweep pinned: vhs reads
        // `Set WaitPattern /a#b/ Set Shell "sh"` as two directives and then
        // aborts on the shell — a lexer that runs the value to EOL sees one.
        $tokens = $this->lex('Set WaitPattern /a#b/ Set Shell "sh"');

        self::assertCount(2, $tokens);
        [, $pattern] = explode("\x00", $tokens[0]->value, 2);
        self::assertSame('/a#b/', $pattern);
        [, $shell] = explode("\x00", $tokens[1]->value, 2);
        self::assertSame('"sh"', $shell, 'the trailing quoted value rides along inside the Set composite');
    }

    public function testQuotesInsideARegexAreLiteral(): void
    {
        foreach (['"', "'", '`'] as $quote) {
            $tokens = $this->lex("Set WaitPattern /a{$quote}b/ Set Shell \"sh\"");
            self::assertCount(2, $tokens, "a {$quote} inside a regex must not open a string");
        }
    }

    public function testAnEscapedSlashDoesNotCloseARegex(): void
    {
        $tokens = $this->lex('Set WaitPattern /a\\/b/');

        [, $value] = explode("\x00", $tokens[0]->value, 2);
        self::assertSame('/a\\/b/', $value);
    }

    public function testASlashMidValueDoesNotOpenARegex(): void
    {
        // `/` is identifier-run-class material upstream — `Set Shell /bin/sh`
        // is ONE free-form value, not `/bin/` + `sh`, because no whitespace
        // follows the would-be closer.
        $tokens = $this->lex('Set Shell /bin/sh');

        self::assertCount(1, $tokens);
        self::assertSame(Lexer::TOKEN_SET, $tokens[0]->type);
        [, $value] = explode("\x00", $tokens[0]->value, 2);
        self::assertSame('/bin/sh', $value);
    }

    public function testABareRegexAtAnyTokenStartLexesAsRegexKindNotUnknown(): void
    {
        $tokens = $this->lex('Wait /prompt$/');

        self::assertSame(Lexer::TOKEN_REGEX, $tokens[0]->type);
        self::assertSame('/prompt$/', $tokens[0]->value);
        $ast = $this->parseOne($tokens[0]);
        self::assertInstanceOf(
            \SugarCraft\Vcr\Tape\Ast\WaitDirective::class,
            $ast,
            'E668: the pattern wait has its AST directive now',
        );
        self::assertSame('/prompt$/', $ast->pattern, 'the delimited source rides verbatim');
        self::assertSame(0.0, $ast->seconds, 'no timeout was spelled');
    }

    // ------------------------------------------------------- other ported facts

    public function testTabIsWhitespaceBetweenDirectives(): void
    {
        $tokens = $this->lex("Type \"x\"\tSleep 1s");

        self::assertCount(2, $tokens);
        self::assertSame(Lexer::TOKEN_TYPE, $tokens[0]->type);
        self::assertSame(Lexer::TOKEN_SLEEP, $tokens[1]->type);
    }

    #[DataProvider('timeUnitProvider')]
    public function testTheThreeTimeUnitsEachCarryTheirOwnScale(string $unit, float $expected): void
    {
        $tokens = $this->lex("Sleep 2{$unit}");

        self::assertSame(Lexer::TOKEN_SLEEP, $tokens[0]->type);
        self::assertSame((string) $expected, $tokens[0]->value);
    }

    /** @return array<string, array{string, float}> */
    public static function timeUnitProvider(): array
    {
        return [
            'seconds' => ['s', 2.0],
            'millis'  => ['ms', 0.002],
            'minutes' => ['m', 120.0],
        ];
    }

    #[DataProvider('quoteProvider')]
    public function testAllThreeQuoteKindsCloseATypeArgument(string $quote): void
    {
        $tokens = $this->lex("Type {$quote}a#b{$quote}");

        self::assertCount(1, $tokens);
        self::assertSame(Lexer::TOKEN_TYPE, $tokens[0]->type);
        self::assertSame('a#b', $tokens[0]->value);
    }

    /** @return array<string, array{string}> */
    public static function quoteProvider(): array
    {
        return [
            'double' => ['"'],
            'single' => ["'"],
            'backtick' => ['`'],
        ];
    }

    // ------------------------------------------------------------ regression guard

    public function testWellFormedStandaloneSetLinesAreUnchanged(): void
    {
        // The forms every existing tape in the corpus uses must keep lexing to
        // exactly one TOKEN_SET with the verbatim value.
        foreach ([
            'Set FontSize 14',
            'Set Shell "sh"',
            'Set Theme {"background": "#fff"}',
        ] as $line) {
            $tokens = $this->lex($line);
            self::assertCount(1, $tokens, $line);
            self::assertSame(Lexer::TOKEN_SET, $tokens[0]->type, $line);
        }
    }

    private function parseOne(\SugarCraft\Vcr\Tape\Token $token): mixed
    {
        $directives = $this->parser->parse([$token]);

        return $directives[0] ?? null;
    }
}

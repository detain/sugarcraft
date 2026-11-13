<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Token;

/**
 * Escape law and size law (findings 006 / 032 / 091): quoted runs scan
 * escape-aware, Env back-references its opener, and no tape read door
 * slurps unbounded bytes. The companion case law lives in
 * {@see CaseMatrixTest}.
 */
final class LexerEscapeTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    /**
     * @return list<Token>
     */
    private function lex(string $source): array
    {
        return $this->lexer->tokenize($source);
    }

    /**
     * The headline repro from finding 006: `Type "echo \"hello\" world"`
     * used to compile a Type of `echo \` and silently drop the rest.
     */
    public function testTypeWithEscapedQuotesCompilesTheFullString(): void
    {
        $tokens = $this->lex('Type "echo \\"hello\\" world"');
        $this->assertCount(1, $tokens, 'the escaped quotes must not split or strand the line');
        $this->assertSame(Lexer::TOKEN_TYPE, $tokens[0]->type);
        $this->assertSame('echo \\"hello\\" world', $tokens[0]->value, 'the token carries the raw escaped spelling');

        $cassette = (new Compiler())->compile(Compiler::parseSource('Type "echo \\"hello\\" world"')['ast'], '/test.tape');
        $bytes = '';
        foreach ($cassette->events as $event) {
            if ($event->kind === EventKind::Input) {
                $bytes .= (string) $event->payload['b'];
            }
        }
        $this->assertSame('echo "hello" world', $bytes, 'compile-time unescape resolves to the typed bytes');
    }

    public function testTypeWithEscapedBackslashTypesOneBackslash(): void
    {
        $source = 'Type "a\\\\b"'; // tape bytes: Type "a\\b"
        $tokens = $this->lex($source);
        $this->assertCount(1, $tokens);
        $this->assertSame('a\\\\b', $tokens[0]->value, 'the doubled backslash rides raw through the token');

        $cassette = (new Compiler())->compile(Compiler::parseSource($source)['ast'], '/test.tape');
        $bytes = '';
        foreach ($cassette->events as $event) {
            if ($event->kind === EventKind::Input) {
                $bytes .= (string) $event->payload['b'];
            }
        }
        $this->assertSame('a\\b', $bytes, 'the escape pair resolves to the single backslash it names');
    }

    public function testUnknownEscapeSequencesStayLiteral(): void
    {
        // Only `\\` and the quote classes are escapes; `\t` remains the two
        // bytes backslash-t, exactly as the port typed it before.
        $tokens = $this->lex('Type "a\\tb"');
        $this->assertCount(1, $tokens);
        $this->assertSame('a\\tb', $tokens[0]->value);
        $this->assertSame('a\\tb', Lexer::unescapeValue($tokens[0]->value), 'no expansion for a non-escape backslash run');
    }

    public function testApostropheInsideDoubleQuotesIsLiteralData(): void
    {
        $tokens = $this->lex("Type \"don't run this\"");
        $this->assertCount(1, $tokens, 'a lone apostrophe cannot re-lex as an opener (finding 032)');
        $this->assertSame(Lexer::TOKEN_TYPE, $tokens[0]->type);
        $this->assertSame("don't run this", $tokens[0]->value);
    }

    public function testTypeLineContinuesAfterAnEscapedClose(): void
    {
        // The shape the old regex shredded: TYPE(a) + UNKNOWN residue.
        $tokens = $this->lex('Type "a\\"b" Sleep 1s');
        $this->assertCount(2, $tokens);
        $this->assertSame(Lexer::TOKEN_TYPE, $tokens[0]->type);
        $this->assertSame('a\\"b', $tokens[0]->value);
        $this->assertSame(Lexer::TOKEN_SLEEP, $tokens[1]->type);
    }

    public function testBacktickDelimitedTypeEscapesItsOwnOpener(): void
    {
        $tokens = $this->lex('Type `a\\`b`');
        $this->assertCount(1, $tokens);
        $this->assertSame('a\\`b', $tokens[0]->value);
    }

    public function testEnvBackReferencesItsOpener(): void
    {
        $tokens = $this->lex('Env FOO "bar"');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_ENV, $tokens[0]->type);
        [$key, $value] = explode("\x00", $tokens[0]->value);
        $this->assertSame('FOO', $key);
        $this->assertSame('bar', $value);

        $tokens = $this->lex("Env FOO 'bar'");
        $this->assertSame(Lexer::TOKEN_ENV, $tokens[0]->type);
    }

    public function testMismatchedEnvQuotesFallToUnknownNotABogusValue(): void
    {
        // The finding 091 repro: this used to compile value `bar`.
        $tokens = $this->lex('Env FOO "bar\'');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_UNKNOWN, $tokens[0]->type, 'a " opened run must not answer \' as its closer');

        $cassette = (new Compiler())->compile(Compiler::parseSource('Env FOO "bar\'')['ast'], '/test.tape', strict: false);
        $this->assertArrayNotHasKey('FOO', $cassette->header->env, 'the refused Env never populates');
    }

    public function testEnvParseErrorNamesTheMismatchedLine(): void
    {
        // The refused remainder walks the UNKNOWN → ParseError route S05
        // opened: non-strict compile answers the head word, never a value.
        $result = Compiler::parseSource('Env FOO "bar\'');
        $this->assertCount(0, $result['ast'], 'no directive survives the refusal');
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Unknown directive: Env', $result['errors'][0]->message);
    }

    public function testEnvValueMayCarryTheOtherQuoteUnescaped(): void
    {
        $tokens = $this->lex('Env FOO "bar\' baz"');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_ENV, $tokens[0]->type);
        [, $value] = explode("\x00", $tokens[0]->value);
        $this->assertSame("bar' baz", $value);
    }

    public function testEnvIsAnchoredLikeTypeSoDirectivesFollow(): void
    {
        $tokens = $this->lex('Env FOO "bar" Sleep 1s');
        $this->assertCount(2, $tokens);
        $this->assertSame(Lexer::TOKEN_ENV, $tokens[0]->type);
        $this->assertSame(Lexer::TOKEN_SLEEP, $tokens[1]->type);
    }

    public function testEnvCompileResolvesEscapes(): void
    {
        $result = Compiler::parseSource('Env FOO "say \\"hi\\""');
        $cassette = (new Compiler())->compile($result['ast'], '/test.tape');
        $this->assertSame('say "hi"', $cassette->header->env['FOO']);
    }

    public function testCopyBodyIsEscapeAwareAndCompilesUnescaped(): void
    {
        $tokens = $this->lex('Copy "a\\"b"');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_COPY, $tokens[0]->type);
        $this->assertSame('a\\"b', $tokens[0]->value);

        $result = Compiler::parseSource('Copy "a\\"b"');
        $cassette = (new Compiler())->compile($result['ast'], '/test.tape');
        $bytes = '';
        foreach ($cassette->events as $event) {
            if ($event->kind === EventKind::Input) {
                $bytes .= (string) $event->payload['b'];
            }
        }
        $this->assertSame("\x1b]52;c;" . base64_encode('a"b') . "\x07", $bytes, 'OSC-52 carries the resolved payload');
    }

    public function testLineCapRefusesAPathologicalLineByName(): void
    {
        $line = 'Type "' . str_repeat('a', Lexer::MAX_LINE_BYTES) . '"';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tape line 1 exceeds the ' . Lexer::MAX_LINE_BYTES . '-byte line cap');
        $this->lex($line);
    }

    public function testOffsetAnchoredScanIsLinearOnDirectiveDenseLines(): void
    {
        // Quadracy probe (finding 032): 20k directives on ONE line. The old
        // per-peel suffix copy cost O(directives × line) — tens of seconds
        // here; the anchored scan must stay comfortably inside the budget.
        $line = str_repeat('Type "x" ', 20_000);
        $start = microtime(true);
        $tokens = $this->lex($line);
        $elapsed = microtime(true) - $start;

        $this->assertCount(20_000, $tokens);
        $this->assertLessThan(2.0, $elapsed, 'a directive-dense line must lex in linear time (took ' . round($elapsed, 3) . 's)');
    }

    public function testReadDoorsRefuseAnOversizedTapeByName(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vcr-cap') . '.tape';
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);
        // Sparse allocation: the stat gate must refuse before any slurp.
        $this->assertTrue(ftruncate($handle, Lexer::MAX_TAPE_BYTES + 1));
        fclose($handle);

        try {
            $loader = new \SugarCraft\Vcr\Format\CassetteLoader();
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('exceeds the ' . Lexer::MAX_TAPE_BYTES . '-byte tape cap');
            $this->invokeLoad($loader, $path);
        } finally {
            @unlink($path);
        }
    }

    private function invokeLoad(\SugarCraft\Vcr\Format\CassetteLoader $loader, string $path): void
    {
        $loader->load($path);
    }
}

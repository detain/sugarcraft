<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Token;

/**
 * Case law (finding 092): the original port's keyword arms disagreed with
 * each other — Sleep/Wait/Screen/Screenshot/Source carried `/i` while
 * Type/Set/Enter/Tab/Ctrl/arrows were strict — so tape validity depended on
 * which keyword happened to be lowercased. Upstream vhs is strict-case
 * everywhere; this matrix pins that single rule across the whole roster.
 * A lowercased directive is not legal-by-accident: it is an UNKNOWN head
 * riding the ParseError route (S05).
 */
final class CaseMatrixTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    /**
     * The five arms that used to answer lowercase (finding 092's `/i` set),
     * plus the always-strict family, each re-probed as a control.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function keywordPairProvider(): array
    {
        return [
            'sleep'       => ['Sleep 1s', 'sleep 1s', Lexer::TOKEN_SLEEP],
            'wait'        => ['Wait 2s', 'wait 2s', Lexer::TOKEN_WAIT],
            'screen'      => ['Screen 80x24', 'screen 80x24', Lexer::TOKEN_SCREEN],
            'screenshot'  => ['Screenshot shot.png', 'screenshot shot.png', Lexer::TOKEN_SCREENSHOT],
            'source'      => ['Source other.tape', 'source other.tape', Lexer::TOKEN_SOURCE],
            'type'        => ['Type "hi"', 'type "hi"', Lexer::TOKEN_TYPE],
            'set'         => ['Set Width 800', 'set Width 800', Lexer::TOKEN_SET],
            'env'         => ['Env FOO "bar"', 'env FOO "bar"', Lexer::TOKEN_ENV],
            'output'      => ['Output demo.gif', 'output demo.gif', Lexer::TOKEN_OUTPUT],
            'enter'       => ['Enter', 'enter', Lexer::TOKEN_ENTER],
            'tab'         => ['Tab', 'tab', Lexer::TOKEN_TAB],
            'ctrl'        => ['Ctrl+A', 'ctrl+A', Lexer::TOKEN_CTRL],
            'backspace'   => ['Backspace', 'backspace', Lexer::TOKEN_BACKSPACE],
            'up'          => ['Up', 'up', Lexer::TOKEN_ARROW],
            'delete'      => ['Delete', 'delete', Lexer::TOKEN_KEY],
            'alt'         => ['Alt', 'alt', Lexer::TOKEN_MODIFIER],
            'copy'        => ['Copy "x"', 'copy "x"', Lexer::TOKEN_COPY],
            'paste'       => ['Paste', 'paste', Lexer::TOKEN_PASTE],
            'require'     => ['Require git', 'require git', Lexer::TOKEN_REQUIRE],
            'scrollup'    => ['ScrollUp', 'scrollup', Lexer::TOKEN_KEY],
        ];
    }

    /**
     * @dataProvider keywordPairProvider
     */
    public function testCanonicalSpellingLexesTheDirective(string $canonical, string $lowercase, string $expectedType): void    {
        $tokens = $this->lexer->tokenize($canonical);
        $this->assertNotSame(Lexer::TOKEN_UNKNOWN, $tokens[0]->type, "'{$canonical}' must be a real directive");
        $this->assertSame($expectedType, $tokens[0]->type);
    }

    /**
     * @dataProvider keywordPairProvider
     */
    public function testLowercaseSpellingIsUnknownForEveryArm(string $canonical, string $lowercase, string $expectedType): void
    {
        $tokens = $this->lexer->tokenize($lowercase);
        $this->assertSame(Lexer::TOKEN_UNKNOWN, $tokens[0]->type, "'{$lowercase}' is not upstream-legal; the strict-case law admits no favourite arms");
    }

    /**
     * @dataProvider mixedCaseHeadProvider
     */
    public function testMixedCaseHeadsAreUnknown(string $mixed): void
    {
        $tokens = $this->lexer->tokenize($mixed);
        $this->assertSame(Lexer::TOKEN_UNKNOWN, $tokens[0]->type, "case-folding leniency is gone: '{$mixed}'");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function mixedCaseHeadProvider(): array
    {
        return [
            'SLEEP'      => ['SLEEP 1s'],
            'sLeEp'      => ['sLeEp 1s'],
            'WAIT'       => ['WAIT 2s'],
            'SCREEN'     => ['SCREEN 80x24'],
            'TYPE'       => ['TYPE "hi"'],
            'ENTER'      => ['ENTER'],
            'SET'        => ['SET Width 800'],
        ];
    }

    public function testDurationUnitsAreStrictCaseToo(): void
    {
        // The `/i` on the duration arms also folded the unit letters; upstream
        // reads exactly s / ms / m.
        foreach (['Sleep 1S', 'Sleep 1MS', 'Wait 2M', 'Sleep 1Ms'] as $loweredUnit) {
            $tokens = $this->lexer->tokenize($loweredUnit);
            $this->assertSame(Lexer::TOKEN_UNKNOWN, $tokens[0]->type, "'{$loweredUnit}' must not advance the tape clock");
        }
    }

    public function testLowercasedSleepCompilesToANamedProblemNotASilentDrop(): void
    {
        // Acceptance from the annotation: `sleep 1s` → UNKNOWN route.
        $result = Compiler::parseSource('sleep 1s');
        $this->assertCount(0, $result['ast']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Unknown directive: sleep', $result['errors'][0]->message);
    }

    public function testStrictCompileAbortsOnALowercasedKeyword(): void
    {
        // parseSource() splits ParseErrors out of the ast; the strict gate
        // must see the raw parse() output for the abort to fire.
        $ast = (new \SugarCraft\Vcr\Tape\Parser())->parse($this->lexer->tokenize('sleep 1s'));
        $this->expectException(\RuntimeException::class);
        $compiler = new Compiler();
        $compiler->compile($ast, '/test.tape', strict: true);
    }

    /**
     * Every token the matrix produces for a lowercase line carries the whole
     * remainder — the stop-don't-spin rule is unchanged by the case law.
     */
    public function testUnknownTokenCarriesTheRemainderVerbatim(): void
    {
        $tokens = $this->lexer->tokenize('sleep 1s Type "after"');
        $this->assertCount(1, $tokens, 'UNKNOWN terminates the peel');
        /** @var Token $token */
        $token = $tokens[0];
        $this->assertSame('sleep 1s Type "after"', $token->value);
    }
}

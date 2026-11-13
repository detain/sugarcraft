<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Token;

/**
 * Duration trust doors on the tape lexer (finding 001, door half).
 *
 * A `Sleep`/`Wait` arm whose seconds value leaves the trusted interval —
 * absurd digit runs, overflow to INF, 400-year minute counts — must NOT
 * produce its token. The line falls through to the UNKNOWN path instead,
 * so a corrupted duration never advances the tape clock to geological time
 * (and with S05's UNKNOWN→ParseError gate landed, the tape then refuses to
 * compile at all).
 */
final class LexerDurationTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    public function testOrdinaryDurationsStillLex(): void
    {
        $tokens = $this->lexer->tokenize('Sleep 2s');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_SLEEP, $tokens[0]->type);
        $this->assertSame('2', $tokens[0]->value);

        $tokens = $this->lexer->tokenize('Sleep 200ms');
        $this->assertSame(Lexer::TOKEN_SLEEP, $tokens[0]->type);

        $tokens = $this->lexer->tokenize('Wait 30s');
        $this->assertSame(Lexer::TOKEN_WAIT, $tokens[0]->type);
    }

    public function testCeilingBoundaryIsInclusive(): void
    {
        $cap = (string) Event::MAX_TIMESTAMP_SECONDS; // '86400'
        $tokens = $this->lexer->tokenize("Sleep {$cap}s");
        $this->assertSame(Lexer::TOKEN_SLEEP, $tokens[0]->type, 'exactly 24 h is the ceiling, not past it');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function absurdDurations(): array
    {
        return [
            'epoch-seconds typed directly' => ['Sleep 1e17s'], // digit-run arm rejects exponent; still must not SLEEP-tokenize
            'long digit run' => ['Sleep 99999999999999999999s'],
            'overflow float cast' => ['Sleep 9999999999999999999999999999999999999999s'],
            'years in minutes' => ['Sleep 999999999m'],
            'just past the ceiling' => ['Sleep 86401s'],
            'wait past the ceiling' => ['Wait 100000s'],
            'wait long digit run' => ['Wait 99999999999999999999s'],
        ];
    }

    public function testAbsurdDurationsNeverBecomeTokens(): void
    {
        foreach (self::absurdDurations() as [$line]) {
            $tokens = $this->lexer->tokenize($line);
            foreach ($tokens as $token) {
                self::assertNotSame(
                    Lexer::TOKEN_SLEEP,
                    $token->type,
                    "absurd duration '{$line}' must not yield a SLEEP token"
                );
                self::assertNotSame(
                    Lexer::TOKEN_WAIT,
                    $token->type,
                    "absurd duration '{$line}' must not yield a WAIT token"
                );
            }
        }
    }

    public function testTokenClassIsTheCompanionUnderTest(): void
    {
        // Smoke the type used above so a namespace slip in this file can
        // never render the assertions vacuous.
        $token = new Token(Lexer::TOKEN_SLEEP, '2', 1);
        $this->assertSame('2', $token->value);
    }
}

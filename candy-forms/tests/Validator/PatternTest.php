<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Validator;

use SugarCraft\Forms\Validator\Pattern;
use PHPUnit\Framework\TestCase;

final class PatternTest extends TestCase
{
    public function testValidMatchingPattern(): void
    {
        $v = new Pattern('/^[a-z]+$/');
        $this->assertSame(true, $v->validate('hello'));
        $this->assertSame(true, $v->validate('abc'));
    }

    public function testInvalidNonMatchingPattern(): void
    {
        $v = new Pattern('/^[a-z]+$/');
        $this->assertSame('Input does not match required format', $v->validate('HELLO'));
        $this->assertSame('Input does not match required format', $v->validate('hello123'));
    }

    public function testEmptyStringIsValidForPattern(): void
    {
        // Empty string is valid (use Required for mandatory fields).
        $v = new Pattern('/^[a-z]+$/');
        $this->assertSame(true, $v->validate(''));
    }

    public function testEmptyStringIsValid(): void
    {
        $v = new Pattern('/^[a-z]+$/');
        $this->assertSame(true, $v->validate(''));
    }

    public function testCustomErrorMessage(): void
    {
        $v = new Pattern('/^\d+$/', 'Must contain only digits');
        $this->assertSame(true, $v->validate('12345'));
        $this->assertSame('Must contain only digits', $v->validate('abc'));
    }

    /**
     * Step 10: Pattern validates the regex eagerly at construction time.
     * An unclosed group or character class throws at construction, not at match time.
     */
    public function testPatternThrowsOnUnclosedGroup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pattern('(abc');
    }

    /**
     * Step 10: An empty pattern string is invalid in PCRE.
     */
    public function testPatternThrowsOnEmptyPattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pattern('');
    }

    // ---- ReDoS backtrack-limit guard ------------------------------------

    /**
     * SECURITY: a caller-supplied catastrophic-backtracking pattern must fail
     * safe (return the message) instead of burning unbounded CPU. We use an
     * "evil" pattern that DOES match its input, but only after ~2^15 backtracks.
     *
     * Under a tiny backtrack budget the guard trips first → the input is
     * rejected. Under a generous budget the SAME pattern/input matches — which
     * proves the rejection is the GUARD firing, not a genuine non-match, and
     * (revert-proof) that removing the scoped ini guard would let the match
     * through (returning true) and break the first assertion. The budget is
     * kept small so CI never actually hangs.
     */
    public function testCatastrophicPatternFailsSafeUnderBacktrackGuard(): void
    {
        $pattern = '/^(a?){15}a{15}$/';
        $input   = str_repeat('a', 15);

        $guarded = new Pattern($pattern, 'too complex', backtrackLimit: 1000, recursionLimit: 1000);
        $this->assertSame('too complex', $guarded->validate($input));

        $generous = new Pattern($pattern, 'too complex', backtrackLimit: 5_000_000, recursionLimit: 5_000_000);
        $this->assertTrue($generous->validate($input));
    }

    public function testDefaultBacktrackAndRecursionLimitsExposed(): void
    {
        $v = new Pattern('/^[a-z]+$/');
        $this->assertSame(Pattern::DEFAULT_BACKTRACK_LIMIT, $v->backtrackLimit);
        $this->assertSame(Pattern::DEFAULT_RECURSION_LIMIT, $v->recursionLimit);
    }

    public function testCustomLimitsStored(): void
    {
        $v = new Pattern('/^[a-z]+$/', null, 4242, 2424);
        $this->assertSame(4242, $v->backtrackLimit);
        $this->assertSame(2424, $v->recursionLimit);
    }

    public function testNormalPatternUnaffectedByGuard(): void
    {
        // Ordinary patterns keep working under the bounded budget.
        $v = new Pattern('/^\d{5}$/');
        $this->assertTrue($v->validate('12345'));
        $this->assertSame('Input does not match required format', $v->validate('abc'));
    }

    public function testGuardRestoresPcreIniAfterValidate(): void
    {
        $backBefore = ini_get('pcre.backtrack_limit');
        $recBefore  = ini_get('pcre.recursion_limit');

        (new Pattern('/^[a-z]+$/', null, 1234, 1234))->validate('hello');

        $this->assertSame($backBefore, ini_get('pcre.backtrack_limit'));
        $this->assertSame($recBefore, ini_get('pcre.recursion_limit'));
    }
}

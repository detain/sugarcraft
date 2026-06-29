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
}

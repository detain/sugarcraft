<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Validator;

use SugarCraft\Forms\Validator\MaxLength;
use PHPUnit\Framework\TestCase;

final class MaxLengthTest extends TestCase
{
    public function testValidWhenBelowMaxLength(): void
    {
        $v = new MaxLength(5);
        $this->assertSame(true, $v->validate(''));
        $this->assertSame(true, $v->validate('abc'));
        $this->assertSame(true, $v->validate('abcde'));
    }

    public function testInvalidWhenAboveMaxLength(): void
    {
        $v = new MaxLength(5);
        $this->assertSame('Must be no more than 5 characters', $v->validate('abcdef'));
        $this->assertSame('Must be no more than 5 characters', $v->validate('very long string'));
    }

    public function testUnicodeCharacters(): void
    {
        $v = new MaxLength(2);
        $this->assertSame(true, $v->validate('日'));
        $this->assertSame('Must be no more than 2 characters', $v->validate('日本a'));
    }
}

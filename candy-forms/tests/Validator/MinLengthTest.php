<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Validator;

use SugarCraft\Forms\Validator\MinLength;
use PHPUnit\Framework\TestCase;

final class MinLengthTest extends TestCase
{
    public function testValidWhenAboveMinLength(): void
    {
        $v = new MinLength(3);
        $this->assertSame(true, $v->validate('abc'));
        $this->assertSame(true, $v->validate('abcd'));
        $this->assertSame(true, $v->validate('long enough string'));
    }

    public function testInvalidWhenBelowMinLength(): void
    {
        $v = new MinLength(3);
        $this->assertSame('Must be at least 3 characters', $v->validate(''));
        $this->assertSame('Must be at least 3 characters', $v->validate('a'));
        $this->assertSame('Must be at least 3 characters', $v->validate('ab'));
    }

    public function testEmptyString(): void
    {
        $v = new MinLength(0);
        $this->assertSame(true, $v->validate(''));
    }

    public function testUnicodeCharacters(): void
    {
        $v = new MinLength(2);
        $this->assertSame(true, $v->validate('日本'));
        $this->assertSame('Must be at least 2 characters', $v->validate('日'));
    }
}

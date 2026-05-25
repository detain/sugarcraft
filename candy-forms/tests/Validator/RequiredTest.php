<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Validator;

use SugarCraft\Forms\Validator\Required;
use PHPUnit\Framework\TestCase;

final class RequiredTest extends TestCase
{
    public function testValidWhenNotEmpty(): void
    {
        $v = new Required();
        $this->assertSame(true, $v->validate('hello'));
        $this->assertSame(true, $v->validate('a'));
        $this->assertSame(true, $v->validate('   '));
    }

    public function testInvalidWhenEmpty(): void
    {
        $v = new Required();
        $this->assertSame('Value is required', $v->validate(''));
    }
}

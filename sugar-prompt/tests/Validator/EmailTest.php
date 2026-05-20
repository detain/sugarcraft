<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Tests\Validator;

use SugarCraft\Prompt\Validator\Email;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    private Email $v;

    protected function setUp(): void
    {
        $this->v = new Email();
    }

    public function testValidEmailAddresses(): void
    {
        $this->assertSame(true, $this->v->validate('a@b.com'));
        $this->assertSame(true, $this->v->validate('user@example.org'));
        $this->assertSame(true, $this->v->validate('test+label@domain.co.uk'));
    }

    public function testEmptyIsValid(): void
    {
        // Empty is considered valid (use Required for mandatory fields).
        $this->assertSame(true, $this->v->validate(''));
    }

    public function testInvalidEmailAddresses(): void
    {
        $this->assertSame('Must be a valid email address', $this->v->validate('notanemail'));
        $this->assertSame('Must be a valid email address', $this->v->validate('missing@'));
        $this->assertSame('Must be a valid email address', $this->v->validate('@nodomain.com'));
        $this->assertSame('Must be a valid email address', $this->v->validate('spaces in@email.com'));
    }
}

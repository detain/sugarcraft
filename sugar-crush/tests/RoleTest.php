<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Role;

final class RoleTest extends TestCase
{
    public function testSystemCaseExists(): void
    {
        $this->assertSame('system', Role::System->value);
    }

    public function testUserCaseExists(): void
    {
        $this->assertSame('user', Role::User->value);
    }

    public function testAssistantCaseExists(): void
    {
        $this->assertSame('assistant', Role::Assistant->value);
    }

    public function testAllCasesAreStrings(): void
    {
        foreach (Role::cases() as $case) {
            $this->assertIsString($case->value);
        }
    }

    public function testCaseCount(): void
    {
        $this->assertCount(3, Role::cases());
    }

    public function testTryFromValidValues(): void
    {
        $this->assertSame(Role::System, Role::tryFrom('system'));
        $this->assertSame(Role::User, Role::tryFrom('user'));
        $this->assertSame(Role::Assistant, Role::tryFrom('assistant'));
    }

    public function testTryFromInvalidValueReturnsNull(): void
    {
        $this->assertNull(Role::tryFrom('invalid'));
    }

    public function testCasesAreInExpectedOrder(): void
    {
        $cases = Role::cases();
        $this->assertSame(Role::System, $cases[0]);
        $this->assertSame(Role::User, $cases[1]);
        $this->assertSame(Role::Assistant, $cases[2]);
    }
}

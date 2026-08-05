<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\PermissionAction;

final class PermissionActionTest extends TestCase
{
    public function testAllThreeCasesExist(): void
    {
        $cases = PermissionAction::cases();

        $this->assertCount(3, $cases);

        $names = array_column($cases, 'name');
        $this->assertContains('Allow', $names);
        $this->assertContains('Deny', $names);
        $this->assertContains('Ask', $names);
    }

    public function testAllowHasCorrectBackingValue(): void
    {
        $this->assertSame('allow', PermissionAction::Allow->value);
    }

    public function testDenyHasCorrectBackingValue(): void
    {
        $this->assertSame('deny', PermissionAction::Deny->value);
    }

    public function testAskHasCorrectBackingValue(): void
    {
        $this->assertSame('ask', PermissionAction::Ask->value);
    }

    /**
     * @dataProvider caseProvider
     */
    public function testFromWorksForEachBackingValue(PermissionAction $case): void
    {
        $action = PermissionAction::from($case->value);

        $this->assertSame($case, $action);
    }

    /**
     * @return iterable<string, array{PermissionAction}>
     */
    public static function caseProvider(): iterable
    {
        foreach (PermissionAction::cases() as $case) {
            yield $case->name => [$case];
        }
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        $result = PermissionAction::tryFrom('invalid-value');

        $this->assertNull($result);
    }

    public function testTryFromReturnsNullForEmptyString(): void
    {
        $result = PermissionAction::tryFrom('');

        $this->assertNull($result);
    }
}

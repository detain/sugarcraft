<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\PermissionMode;

final class PermissionModeTest extends TestCase
{
    public function testAllSixCasesExist(): void
    {
        $cases = PermissionMode::cases();

        $this->assertCount(6, $cases);

        $names = array_column($cases, 'name');
        $this->assertContains('Default', $names);
        $this->assertContains('AcceptEdits', $names);
        $this->assertContains('Plan', $names);
        $this->assertContains('Auto', $names);
        $this->assertContains('DontAsk', $names);
        $this->assertContains('BypassPermissions', $names);
    }

    /**
     * @dataProvider caseProvider
     */
    public function testFromWorksForEachBackingValue(PermissionMode $case): void
    {
        $mode = PermissionMode::from($case->value);

        $this->assertSame($case, $mode);
    }

    /**
     * @return iterable<string, array{PermissionMode}>
     */
    public static function caseProvider(): iterable
    {
        foreach (PermissionMode::cases() as $case) {
            yield $case->name => [$case];
        }
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        $result = PermissionMode::tryFrom('invalid-value');

        $this->assertNull($result);
    }

    public function testTryFromReturnsNullForEmptyString(): void
    {
        $result = PermissionMode::tryFrom('');

        $this->assertNull($result);
    }
}

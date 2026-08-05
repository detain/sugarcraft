<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionRule;

final class PermissionRuleTest extends TestCase
{
    public function testConstructorSetsRuleProperty(): void
    {
        $rule = new PermissionRule('Bash(composer *)', PermissionAction::Ask);

        $this->assertSame('Bash(composer *)', $rule->pattern);
    }

    public function testConstructorSetsActionProperty(): void
    {
        $rule = new PermissionRule('Read(./.env)', PermissionAction::Deny);

        $this->assertSame(PermissionAction::Deny, $rule->action);
    }

    public function testRulePropertyIsReadonly(): void
    {
        $rule = new PermissionRule('mcp__git__*', PermissionAction::Allow);

        $this->assertSame('mcp__git__*', $rule->pattern);
    }

    public function testActionPropertyIsReadonly(): void
    {
        $rule = new PermissionRule('Bash(rm *)', PermissionAction::Deny);

        $this->assertSame(PermissionAction::Deny, $rule->action);
    }

    /**
     * @dataProvider actionCaseProvider
     */
    public function testWorksWithAllPermissionActionCases(PermissionAction $action): void
    {
        $rule = new PermissionRule('Test(pattern)', $action);

        $this->assertSame($action, $rule->action);
    }

    /**
     * @return iterable<string, array{PermissionAction}>
     */
    public static function actionCaseProvider(): iterable
    {
        foreach (PermissionAction::cases() as $case) {
            yield $case->name => [$case];
        }
    }

    public function testVariousRulePatterns(): void
    {
        $patterns = [
            'Bash(composer update *)',
            'Read(./.env)',
            'mcp__git__*',
            'Edit(*)',
            'Write(./README.md)',
        ];

        foreach ($patterns as $pattern) {
            $rule = new PermissionRule($pattern, PermissionAction::Allow);

            $this->assertSame($pattern, $rule->pattern);
        }
    }
}

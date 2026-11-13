<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\ToolCall;

/**
 * S-T01: fnmatch() refuses subjects past 4096 chars (warning + false). A Deny
 * or Ask rule must treat such a subject as MATCHING; Allow must not grant it.
 */
final class PermissionRuleLongSubjectTest extends TestCase
{
    private static function long(string $head): string
    {
        return $head . str_repeat('a', 5000);
    }

    public function testDenyMatchesFiveKilobyteCommand(): void
    {
        $rule = new PermissionRule('Bash(rm *)', PermissionAction::Deny);
        $call = new ToolCall('Bash', ['command' => self::long('rm -rf ')]);

        $this->assertTrue($rule->matches($call));
        $decision = (new PermissionGate(PermissionMode::BypassPermissions, [$rule]))->evaluate($call);
        $this->assertSame(PermissionDecision::Deny, $decision);
    }

    public function testAskMatchesFiveKilobyteCommand(): void
    {
        $rule = new PermissionRule('Bash(git *)', PermissionAction::Ask);

        $this->assertTrue($rule->matches(new ToolCall('Bash', ['command' => self::long('git log ')])));
    }

    public function testAllowDoesNotGrantFiveKilobyteCommand(): void
    {
        $rule = new PermissionRule('Bash(git *)', PermissionAction::Allow);

        $this->assertFalse($rule->matches(new ToolCall('Bash', ['command' => self::long('git log ')])));
    }

    public function testDenyMatchesLongPathSubject(): void
    {
        $rule = new PermissionRule('Read(secrets/*)', PermissionAction::Deny);

        $this->assertTrue($rule->matches(new ToolCall('Read', ['file_path' => self::long('secrets/')]), true, '/tmp'));
    }

    public function testNoFnmatchWarningRaised(): void
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $str) use (&$warnings): bool {
            $warnings[] = $str;

            return true;
        });
        try {
            foreach ([PermissionAction::Deny, PermissionAction::Ask, PermissionAction::Allow] as $action) {
                (new PermissionRule('Bash(rm *)', $action))
                    ->matches(new ToolCall('Bash', ['command' => self::long('rm -rf ')]));
            }
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
    }

    public function testShortSubjectsUnchanged(): void
    {
        $rule = new PermissionRule('Bash(rm *)', PermissionAction::Deny);

        $this->assertTrue($rule->matches(new ToolCall('Bash', ['command' => 'rm -rf x'])));
        $this->assertFalse($rule->matches(new ToolCall('Bash', ['command' => 'ls -la'])));
    }
}

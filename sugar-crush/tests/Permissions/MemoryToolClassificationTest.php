<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Crush\Hooks\HookConfig;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Permissions\SafetyClassifier;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Tools\BuiltIn\MemoryTool;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;

/**
 * Roadmap 5.1-2: the `Memory` tool is no-ask. Its writes touch only the
 * harness-owned memory directories, so every mode allows every action — a
 * `plan` or `dont-ask` session can still keep its notes — while an explicit
 * Deny rule still wins.
 */
final class MemoryToolClassificationTest extends TestCase
{
    public function testTheCatalogDeclaresMemoryNoAsk(): void
    {
        self::assertSame(ToolPermissionClass::NoAsk, ToolCatalog::permissionOf(MemoryTool::NAME));
    }

    /** @return iterable<string, array{PermissionMode, string}> */
    public static function modesAndActions(): iterable
    {
        foreach (PermissionMode::cases() as $mode) {
            foreach (['view', 'save', 'str_replace', 'delete', 'recall'] as $action) {
                yield $mode->value . ' ' . $action => [$mode, $action];
            }
        }
    }

    #[DataProvider('modesAndActions')]
    public function testEveryModeAllowsEveryAction(PermissionMode $mode, string $action): void
    {
        $gate = new PermissionGate($mode, [], new SafetyClassifier());

        self::assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall(MemoryTool::NAME, ['action' => $action, 'content' => 'x', 'id' => 'abc'])),
        );
    }

    public function testADenyRuleStillTurnsTheToolOff(): void
    {
        $gate = new PermissionGate(PermissionMode::BypassPermissions, [
            new PermissionRule('Memory', PermissionAction::Deny),
        ]);

        self::assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall(MemoryTool::NAME, ['action' => 'save'])));
    }

    public function testNoAskIsNotAReadOrAWrite(): void
    {
        self::assertNotContains(MemoryTool::NAME, ToolCatalog::namesOf(ToolPermissionClass::Read));
        self::assertNotContains(MemoryTool::NAME, ToolCatalog::namesOf(ToolPermissionClass::Write));
    }

    public function testProtectFilesHookIsNotAskedAboutMemoryCalls(): void
    {
        // A note's content is prose, not a path: `.env` in a note about the
        // project's env file is not a read of `.env`.
        self::assertSame(0, preg_match(HookConfig::pattern((new ProtectFilesHook())->matcher()), MemoryTool::NAME));
    }
}

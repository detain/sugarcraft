<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Code\Hooks\HookConfig;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\SafetyClassifier;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\Tools\BuiltIn\MemoryTool;
use SugarCraft\Code\Tools\Catalog\ToolCatalog;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;

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

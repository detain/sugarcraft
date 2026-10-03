<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;

/**
 * Step 0.8: the rest of the policy surface - the settings tiers, `.mcp.json`
 * and the skills / commands / rules / workflows directories - is write-
 * protected like `hooks.yaml` and `agents/` already were, in every mode
 * (including the shipped `bypass-permissions`), while reads stay allowed.
 */
final class ProtectFilesPolicySurfacesTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: array<string, mixed>}> */
    public static function policyWrites(): iterable
    {
        yield 'Write project settings' => ['Write', ['file_path' => '.sugar-crush/settings.json', 'content' => '{}']];
        yield 'Edit local settings' => ['Edit', ['file_path' => 'repo/.sugar-crush/settings.local.json', 'old_string' => 'a', 'new_string' => 'b']];
        yield 'Write user settings' => ['Write', ['file_path' => '/home/u/.sugar-crush/settings.json', 'content' => '{}']];
        yield 'Write .mcp.json' => ['Write', ['file_path' => '.mcp.json', 'content' => '{}']];
        yield 'Bash append .mcp.json' => ['Bash', ['command' => 'echo x >> ./.mcp.json']];
        yield 'Bash quoted settings' => ['Bash', ['command' => 'cp x ".sugar-crush/settings.json"']];
        yield 'Write a skill' => ['Write', ['file_path' => '.sugar-crush/skills/deploy/SKILL.md', 'content' => 'x']];
        yield 'Write a command' => ['Write', ['file_path' => '.sugar-crush/commands/ship.md', 'content' => 'x']];
        yield 'Write a rule' => ['Write', ['file_path' => '/home/u/.sugar-crush/rules/always.md', 'content' => 'x']];
        yield 'Write a workflow' => ['Write', ['file_path' => '.sugar-crush/workflows/pwn.php', 'content' => '<?php']];
        yield 'Bash mv onto the skills dir' => ['Bash', ['command' => 'mv /tmp/x .sugar-crush/skills']];
        yield 'MCP write of a command' => ['mcp__fs__write_file', ['path' => '.sugar-crush/commands/x.md', 'content' => 'x']];
    }

    /** @param array<string, mixed> $args */
    #[DataProvider('policyWrites')]
    public function testPolicyWritesAreRefusedInEveryMode(string $tool, array $args): void
    {
        foreach (PermissionMode::cases() as $mode) {
            $verdict = self::chainVerdict($tool, $args, $mode);

            self::assertTrue($verdict->isDenied(), "{$mode->value}: {$tool} " . json_encode($args));
            self::assertStringContainsString('prevents modification of files matching', (string) $verdict->message);
        }
    }

    public function testReadingThePolicySurfaceStaysAllowed(): void
    {
        $mode = PermissionMode::BypassPermissions;

        foreach (['.sugar-crush/settings.json', '.mcp.json', '.sugar-crush/skills/deploy/SKILL.md', '.sugar-crush/workflows/x.php'] as $path) {
            self::assertTrue(self::chainVerdict('Read', ['file_path' => $path], $mode)->isAllowed(), "Read {$path}");
        }
        self::assertTrue(self::chainVerdict('Grep', ['pattern' => 'x', 'path' => '.sugar-crush/rules'], $mode)->isAllowed());
        self::assertTrue(self::chainVerdict('Glob', ['pattern' => '*.md', 'path' => '.sugar-crush/commands'], $mode)->isAllowed());
    }

    public function testLookalikeNamesAreOtherFiles(): void
    {
        $mode = PermissionMode::BypassPermissions;

        foreach ([
            'foo.mcp.json',
            '.mcp.json.example',
            '.sugar-crush/settings.dev.json',
            '.sugar-crush/skills-archive/x.md',
            '.sugar-crush/memory/notes.md',
            'docs/skills/x.md',
            'src/rules/Rule.php',
        ] as $path) {
            self::assertTrue(
                self::chainVerdict('Write', ['file_path' => $path, 'content' => 'x'], $mode)->isAllowed(),
                "Write {$path} was refused",
            );
        }
    }

    public function testTheNewPatternsAreWriteOnlyAndInTheDefaults(): void
    {
        foreach ([
            '#(^|/)\.sugar-crush/settings(?:\.local)?\.json(?![\w.-])#',
            '#(?<![\w.-])\.mcp\.json(?![\w.-])#',
            '#(^|/)\.sugar-crush/(?:skills|commands|rules|workflows)(?![\w.-])#',
        ] as $pattern) {
            self::assertContains($pattern, ProtectFilesHook::WRITE_ONLY_PATTERNS);
            self::assertContains($pattern, ProtectFilesHook::DEFAULT_PROTECTED_PATTERNS);
        }
    }

    /** @param array<string, mixed> $args */
    private static function chainVerdict(string $tool, array $args, PermissionMode $mode): HookResult
    {
        $manager = new HookManager(new HookRegistry());
        $manager->registerBuiltIns();
        $manager->register(new PermissionGateHook(new PermissionGate($mode)));

        return $manager->preToolUse(new HookContext(
            sessionId: 'test-session',
            toolName: $tool,
            toolArgs: $args,
            toolInput: (string) json_encode($args),
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/test-project',
        ));
    }
}

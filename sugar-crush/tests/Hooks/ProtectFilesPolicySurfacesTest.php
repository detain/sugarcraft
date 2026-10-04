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
 * Steps 0.8 + 0.8b: the rest of the policy surface - the settings tiers,
 * `.mcp.json`, the skills / commands / rules / workflows directories, and the
 * `.claude/` / `.opencode/` skill, agent and command trees sugar-crush imports
 * from - is never written unprompted: `protect-files` ASKS about every write,
 * in every mode (the shipped `bypass-permissions` included), while reads stay
 * allowed. `hooks.yaml`, `config.json` and `agents/` stay deny-class.
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
        yield 'Write a Claude skill' => ['Write', ['file_path' => '.claude/skills/deploy/SKILL.md', 'content' => 'x']];
        yield 'Edit a Claude agent' => ['Edit', ['file_path' => '/home/u/.claude/agents/reviewer.md', 'old_string' => 'a', 'new_string' => 'b']];
        yield 'Write a Claude command' => ['Write', ['file_path' => 'repo/.claude/commands/ship.md', 'content' => 'x']];
        yield 'Write an opencode agent' => ['Write', ['file_path' => '.opencode/agents/x.md', 'content' => 'x']];
        yield 'Write an opencode agent (singular dir)' => ['Write', ['file_path' => '.opencode/agent/x.md', 'content' => 'x']];
        yield 'Bash into an opencode command dir' => ['Bash', ['command' => 'cp x.md .opencode/command/']];
        yield 'Bash into the opencode skills dir' => ['Bash', ['command' => 'tee .opencode/skills/x/SKILL.md < y']];
    }

    /** @param array<string, mixed> $args */
    #[DataProvider('policyWrites')]
    public function testPolicyWritesAreAskedAboutInEveryMode(string $tool, array $args): void
    {
        foreach (PermissionMode::cases() as $mode) {
            $verdict = self::chainVerdict($tool, $args, $mode);
            $call = "{$mode->value}: {$tool} " . json_encode($args);

            self::assertFalse($verdict->permitsExecution(), "{$call} ran unprompted");
            if ($verdict->isDenied()) {
                // Only a mode that refuses the call on its own may turn the
                // question into a refusal - never protect-files' own verdict.
                self::assertStringStartsWith("Permission mode '{$mode->value}'", (string) $verdict->message, $call);

                continue;
            }

            self::assertTrue($verdict->isAsk(), $call);
            self::assertFalse($verdict->askedOnlyBy('permission-gate'), "{$call}: an \"always\" grant would remember it");
            self::assertStringContainsString('may change a policy file', (string) $verdict->message, $call);
        }
    }

    public function testTheShippedBypassModeAsksRatherThanRefusing(): void
    {
        foreach (self::policyWrites() as $name => [$tool, $args]) {
            self::assertTrue(self::chainVerdict($tool, $args, PermissionMode::BypassPermissions)->isAsk(), $name);
        }
    }

    public function testTheTrustSurfaceStaysDenied(): void
    {
        foreach ([
            ['Write', ['file_path' => '.sugar-crush/hooks.yaml', 'content' => 'x']],
            ['Write', ['file_path' => '/home/u/.sugar-crush/config.json', 'content' => '{}']],
            ['Write', ['file_path' => '.sugar-crush/agents/reviewer.md', 'content' => 'x']],
            ['Bash', ['command' => 'cp x .git/hooks/pre-commit']],
            // A call naming both a deny-class and an ask-class file is refused,
            // not put to the human.
            ['Bash', ['command' => 'cp .mcp.json .sugar-crush/hooks.yaml']],
        ] as [$tool, $args]) {
            $verdict = self::chainVerdict($tool, $args, PermissionMode::BypassPermissions);

            self::assertTrue($verdict->isDenied(), json_encode($args));
            self::assertStringContainsString('prevents modification of files matching', (string) $verdict->message);
        }
    }

    public function testReadingThePolicySurfaceStaysAllowed(): void
    {
        $mode = PermissionMode::BypassPermissions;

        foreach (['.sugar-crush/settings.json', '.mcp.json', '.sugar-crush/skills/deploy/SKILL.md', '.sugar-crush/workflows/x.php', '.claude/agents/x.md', '.opencode/skills/x/SKILL.md'] as $path) {
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
            '.claude/settings.json',
            '.claude/skills-old/x.md',
            'my.claude/skills/x.md',
            '.opencode/memory/notes.md',
        ] as $path) {
            self::assertTrue(
                self::chainVerdict('Write', ['file_path' => $path, 'content' => 'x'], $mode)->isAllowed(),
                "Write {$path} was refused",
            );
        }
    }

    public function testThePolicyPatternsAreTheAskClassAndNotDenied(): void
    {
        foreach ([
            '#(^|/)\.sugar-crush/settings(?:\.local)?\.json(?![\w.-])#',
            '#(?<![\w.-])\.mcp\.json(?![\w.-])#',
            '#(^|/)\.sugar-crush/(?:skills|commands|rules|workflows)(?![\w.-])#',
            '#(^|/)\.(?:claude|opencode)/(?:skills|agents?|commands?)(?![\w.-])#',
        ] as $pattern) {
            self::assertContains($pattern, ProtectFilesHook::POLICY_ASK_PATTERNS);
            self::assertContains($pattern, (new ProtectFilesHook())->askPatterns());
            self::assertNotContains($pattern, ProtectFilesHook::WRITE_ONLY_PATTERNS);
            self::assertNotContains($pattern, ProtectFilesHook::DEFAULT_PROTECTED_PATTERNS);
        }
    }

    public function testAskPatternsAreConfigurableAndImmutable(): void
    {
        $hook = new ProtectFilesHook();
        $quiet = $hook->withAskPatterns([]);
        $context = new HookContext(
            sessionId: 's',
            toolName: 'Write',
            toolArgs: ['file_path' => '.mcp.json', 'content' => '{}'],
            toolInput: '{}',
            toolOutput: '',
            model: 'm',
            provider: 'p',
            projectRoot: '/tmp/test-project',
        );

        self::assertTrue($hook->execute($context)->isAsk());
        self::assertTrue($quiet->execute($context)->isAllowed());
        self::assertSame($hook->protectedPatterns(), $quiet->protectedPatterns());
        self::assertTrue($hook->withProtectedPatterns([])->execute($context)->isAsk(), 'replacing the deny list keeps the ask list');
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

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\ForeignAgentPresetRegistry;
use SugarCraft\Code\Agents\Live\ToolSummary;
use SugarCraft\Code\Context\Compaction\FilesTouched;
use SugarCraft\Code\Context\Pruning\PruningPolicy;
use SugarCraft\Code\Context\Pruning\Strategies\StaleReadStrategy;
use SugarCraft\Code\Context\TurnContextBlock;
use SugarCraft\Code\Hooks\BuiltIn\AutoCommitHook;
use SugarCraft\Code\Hooks\BuiltIn\EditedFiles;
use SugarCraft\Code\Hooks\BuiltIn\PostEditDiagnosticsHook;
use SugarCraft\Code\Hooks\BuiltIn\PostEditLintHook;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\ToolResult;
use SugarCraft\Code\Tools\ToolCall;

/**
 * An `ApplyPatch` is an edit to every file its patch names, so each roster
 * that follows edits by their `file_path` follows a patch's paths too (W10
 * integration, alongside the permission-rule fix).
 */
final class ApplyPatchEditedFilesTest extends TestCase
{
    private const PATCH = "*** Begin Patch\n*** Add File: src/new.php\n+<?php\n*** Update File: src/old.php\n*** Move to: src/moved.php\n@@\n-a\n+b\n*** Delete File: src/gone.php\n*** End Patch";

    public function testEditedFilesListsEveryPathAPatchTouches(): void
    {
        self::assertSame(['src/new.php', 'src/old.php', 'src/moved.php', 'src/gone.php'], EditedFiles::of(self::context('ApplyPatch', ['patch' => self::PATCH])));
        self::assertSame(['a.php'], EditedFiles::of(self::context('Edit', ['file_path' => 'a.php'])));
        self::assertSame([], EditedFiles::of(self::context('ApplyPatch', ['patch' => 'not a patch'])));
    }

    public function testThePostEditHooksMatchAPatch(): void
    {
        foreach ([AutoCommitHook::class, PostEditLintHook::class, PostEditDiagnosticsHook::class] as $class) {
            $matcher = (new \ReflectionClass($class))->newInstanceWithoutConstructor()->matcher();
            self::assertMatchesRegularExpression('/' . $matcher . '/', 'ApplyPatch', $class);
            self::assertDoesNotMatchRegularExpression('/' . $matcher . '/', 'Read', $class);
        }
    }

    public function testTheContextRostersFollowAPatchsPaths(): void
    {
        $touched = FilesTouched::new()->withResult(new ToolResult('ApplyPatch', 'ok', arguments: ['patch' => self::PATCH]));
        self::assertSame(['src/new.php', 'src/old.php', 'src/moved.php', 'src/gone.php'], $touched->modified);

        $messages = [
            new AssistantMessage('', [new ToolCall('p1', 'ApplyPatch', ['patch' => self::PATCH])]),
            new ToolResultMessage('p1', 'ok'),
        ];
        self::assertSame(['src/new.php', 'src/old.php', 'src/moved.php', 'src/gone.php'], TurnContextBlock::recentlyModifiedIn($messages));

        self::assertContains('ApplyPatch', PruningPolicy::PROTECTED_TOOLS);
        self::assertContains('ApplyPatch', StaleReadStrategy::WRITERS);
        self::assertSame('src/new.php +3', ToolSummary::of('ApplyPatch', ['patch' => self::PATCH]));
    }

    public function testAnOpencodePatchPermissionMapsOntoApplyPatch(): void
    {
        $map = (new \ReflectionClassConstant(ForeignAgentPresetRegistry::class, 'OPENCODE_TOOL_NAMES'))->getValue();
        self::assertSame('ApplyPatch', $map['patch']);
    }

    /** @param array<string, mixed> $args */
    private static function context(string $tool, array $args): HookContext
    {
        return new HookContext(
            sessionId: 's',
            toolName: $tool,
            toolArgs: $args,
            toolInput: (string) json_encode($args),
            toolOutput: '',
            model: 'm',
            provider: 'p',
            projectRoot: '/tmp/test-project',
        );
    }
}

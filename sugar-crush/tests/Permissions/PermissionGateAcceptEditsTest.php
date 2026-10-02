<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\ToolCall;

/**
 * `accept-edits` grants the edit TOOLS inside the project (audit F-P4).
 *
 * Before the fix this mode asked about every `Edit` and `Write` — a hard
 * refusal wherever no prompt is attached — while `rm ./src/Main.php` ran
 * unprompted. The shell half of the inversion is pinned in
 * {@see PermissionGateScopedWriteTest::testDestructiveShellVerbsPrompt()};
 * this file pins the tool half, with a real root on disk so a resolved path
 * and a symlink can be shown to matter.
 */
final class PermissionGateAcceptEditsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/gae-' . bin2hex(random_bytes(6));
        $this->root = $base . '/proj';
        mkdir($this->root . '/src', 0777, true);
        mkdir($this->root . '/.git/hooks', 0777, true);
        mkdir($base . '/outside', 0777, true);
        file_put_contents($this->root . '/src/Main.php', '<?php');
        symlink($base . '/outside', $this->root . '/escape');
    }

    protected function tearDown(): void
    {
        $base = \dirname($this->root);
        if ($base === '' || !is_dir($base)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $path = $item->getPathname();
            is_link($path) || !$item->isDir() ? unlink($path) : rmdir($path);
        }
        rmdir($base);
    }

    private function decide(string $tool, mixed $filePath, ?string $root = null): PermissionDecision
    {
        return (new PermissionGate(PermissionMode::AcceptEdits))->evaluate(
            new ToolCall($tool, ['file_path' => $filePath]),
            $root ?? $this->root,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function editTools(): iterable
    {
        yield 'Edit' => ['Edit'];
        yield 'Write' => ['Write'];
    }

    #[DataProvider('editTools')]
    public function testAnInRootEditRunsWithoutAsking(string $tool): void
    {
        self::assertSame(PermissionDecision::Allow, $this->decide($tool, 'src/Main.php'));
        self::assertSame(PermissionDecision::Allow, $this->decide($tool, './src/Main.php'));
        self::assertSame(
            PermissionDecision::Allow,
            $this->decide($tool, $this->root . '/src/Main.php'),
            'an absolute path inside the root is inside — the root is known, so it can be compared',
        );
    }

    public function testWriteCreatingNewDirectoriesInsideTheRootRuns(): void
    {
        self::assertSame(PermissionDecision::Allow, $this->decide('Write', 'docs/new/notes.md'));
    }

    /**
     * Everything the grant must NOT reach: each is an edit, and each asks.
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function targetsThatStillAsk(): iterable
    {
        foreach (['Edit', 'Write'] as $tool) {
            yield "{$tool} absolute outside"   => [$tool, '/etc/hosts'];
            yield "{$tool} dotdot escape"      => [$tool, '../outside/x.php'];
            yield "{$tool} through a symlink"  => [$tool, 'escape/x.php'];
            yield "{$tool} tilde"              => [$tool, '~/.bashrc'];
            yield "{$tool} into a git hook"    => [$tool, '.git/hooks/pre-commit'];
            yield "{$tool} into .git config"   => [$tool, './.git/config'];
            yield "{$tool} policy settings"    => [$tool, '.sugar-crush/settings.json'];
            yield "{$tool} MCP roster"         => [$tool, '.mcp.json'];
            yield "{$tool} no file_path"       => [$tool, null];
            yield "{$tool} non-string path"    => [$tool, ['src/Main.php']];
        }
    }

    #[DataProvider('targetsThatStillAsk')]
    public function testEditsOutsideTheProjectOrIntoProtectedPathsAsk(string $tool, mixed $path): void
    {
        self::assertSame(PermissionDecision::Ask, $this->decide($tool, $path));
    }

    /**
     * Without a root (a sub-agent's gate today) only the spelling can be
     * judged: a relative contained path is granted, an absolute one asks —
     * even one that happens to be inside the project, since nothing says so.
     */
    public function testWithoutARootOnlyARelativeContainedSpellingIsGranted(): void
    {
        $gate = new PermissionGate(PermissionMode::AcceptEdits);

        self::assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall('Edit', ['file_path' => 'src/Main.php'])),
        );
        self::assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(new ToolCall('Edit', ['file_path' => $this->root . '/src/Main.php'])),
        );
        self::assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(new ToolCall('Write', ['file_path' => '../x.php'])),
        );
    }

    /**
     * Rules still come first: a deny on a path beats the mode's grant.
     */
    public function testADenyRuleStillBeatsTheGrant(): void
    {
        $gate = new PermissionGate(
            PermissionMode::AcceptEdits,
            [new PermissionRule('Write(src/*)', PermissionAction::Deny)],
        );

        self::assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('Write', ['file_path' => 'src/Main.php']), $this->root),
        );
    }

    /**
     * The grant is `accept-edits`' alone: `default` still asks for the same
     * in-root edit.
     */
    public function testDefaultModeStillAsksForAnInRootEdit(): void
    {
        self::assertSame(
            PermissionDecision::Ask,
            (new PermissionGate(PermissionMode::Default))->evaluate(
                new ToolCall('Edit', ['file_path' => 'src/Main.php']),
                $this->root,
            ),
        );
    }

    /**
     * End to end through the hook the live chain runs: the root comes from
     * `HookContext::$projectRoot`, so an absolute in-root path is granted and
     * the symlink out is not.
     */
    public function testTheLiveHookChainHandsTheGateTheRoot(): void
    {
        $hook = new PermissionGateHook(new PermissionGate(PermissionMode::AcceptEdits));
        $context = fn (string $path): HookContext => new HookContext(
            sessionId: 's',
            toolName: 'Write',
            toolArgs: ['file_path' => $path, 'content' => 'x'],
            toolInput: '',
            toolOutput: '',
            model: 'm',
            provider: 'p',
            projectRoot: $this->root,
        );

        self::assertTrue($hook->execute($context($this->root . '/src/New.php'))->isAllowed());
        self::assertTrue($hook->execute($context('escape/x.php'))->isAsk());
    }
}

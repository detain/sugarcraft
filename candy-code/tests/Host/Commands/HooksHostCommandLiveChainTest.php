<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\HooksHostCommand;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-EXT-7 / VIS-3-P2: `/hooks` rides the chain ALREADY BUILT for the launch —
 * it reads `$context->workspace?->hooks` and never calls the Bootstrap
 * builder, because building a chain is not a pure read (it re-runs
 * configuration and carries launch side effects: the secret-env allowlist,
 * the LSP client, the team manager's launch-hook handover).
 *
 * The regression shape pinned here is the smuggle the old code allowed: a
 * `~/.candy-code/hooks.yaml` appearing in the panel even when the launch
 * chain the session actually registers never contained it. The command must
 * show ONLY what the carried manager holds; with no carried manager it says
 * "not measured" plus the no-launch-chain row and builds nothing.
 */
final class HooksHostCommandLiveChainTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/candy-code-hookslive-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->wipeTree($this->tempDir);
        parent::tearDown();
    }

    private function runPanel(CommandContext $context): string
    {
        $reply = (new HooksHostCommand())->run($context, '/hooks');

        return implode("\n", array_map(static fn (Message $m): string => $m->content, $reply->rows));
    }

    public function testThePanelListsTheManagerCarriedOnTheWorkspace(): void
    {
        $registry = new HookRegistry();
        $registry->register($this->chainHook('live-chain-panel-' . getmypid(), HookEvent::PreToolUse, 'Bash'));

        $text = $this->runPanel(CommandContext::new(
            history: [Message::user('go')],
            root: $this->tempDir . '/project',
            workspace: WorkspaceContext::new(hooks: new HookManager($registry)),
        ));

        self::assertStringContainsString('live-chain-panel-', $text, 'the carried chain must reach the panel verbatim');
        self::assertStringContainsString('PreToolUse', $text);
        self::assertStringNotContainsString('not measured', $text);
    }

    public function testAUserHooksFileNeverSmugglesItselfIntoThePanel(): void
    {
        $home = $this->useHomeSandbox($this->tempDir . '/home');
        mkdir($home . '/.candy-code', 0o700, true);
        $smuggled = 'rebuild-smuggled-' . getmypid();
        file_put_contents(
            $home . '/.candy-code/hooks.yaml',
            "hooks:\n  PreToolUse:\n    - name: {$smuggled}\n      matcher: 'Bash'\n      command: 'true'\n",
        );

        $registry = new HookRegistry();
        $registry->register($this->chainHook('carried-only-' . getmypid(), HookEvent::PreToolUse, 'Edit'));

        $text = $this->runPanel(CommandContext::new(
            history: [Message::user('go')],
            root: $this->tempDir . '/project',
            workspace: WorkspaceContext::new(hooks: new HookManager($registry)),
        ));
        $this->restoreHomeSandbox();

        self::assertStringContainsString('carried-only-', $text, 'the non-vacuity arm: the panel really did run');
        self::assertStringNotContainsString(
            $smuggled,
            $text,
            'a hooks.yaml on disk is NOT the launch chain — reading the builder here would have registered it (side effects included) and shown it',
        );
    }

    public function testWithoutAWorkspaceChainThePanelSaysNotMeasuredAndBuildsNothing(): void
    {
        $home = $this->useHomeSandbox($this->tempDir . '/home');
        mkdir($home . '/.candy-code', 0o700, true);
        $smuggled = 'rebuild-smuggled-null-' . getmypid();
        file_put_contents(
            $home . '/.candy-code/hooks.yaml',
            "hooks:\n  PreToolUse:\n    - name: {$smuggled}\n      matcher: 'Bash'\n      command: 'true'\n",
        );

        $text = $this->runPanel(CommandContext::new(
            history: [Message::user('go')],
            root: $this->tempDir . '/project',
        ));
        $this->restoreHomeSandbox();

        self::assertStringContainsString('not measured', $text, 'no carried chain must answer not-measured, never an empty table');
        self::assertStringContainsString('no launch hook chain', $text, 'the row names the reason: the session carries no chain and the panel never builds one');
        self::assertStringNotContainsString($smuggled, $text, 'the null-workspace path rebuilds nothing from disk either');
    }

    public function testTheCommandSourceCarriesNoBootstrapChainBuilderReference(): void
    {
        $src = file_get_contents((new \ReflectionClass(HooksHostCommand::class))->getFileName());
        self::assertIsString($src);

        self::assertStringNotContainsString(
            'Bootstrap::hooks',
            $src,
            'VIS-3-P2 root cause: the panel must never go back to the builder — the launch chain or nothing',
        );
        self::assertStringContainsString(
            'workspace' . \chr(63) . '->hooks',
            $src,
            'the read must be the structural null-safe chain access, not a re-derivation',
        );
    }

    private function chainHook(string $name, HookEvent $event, string $matcher): HookInterface
    {
        return new class ($name, $event, $matcher) implements HookInterface {
            public function __construct(
                private readonly string $chainName,
                private readonly HookEvent $chainEvent,
                private readonly string $chainMatcher,
            ) {
            }

            public function name(): string
            {
                return $this->chainName;
            }

            public function event(): HookEvent
            {
                return $this->chainEvent;
            }

            public function matcher(): string
            {
                return $this->chainMatcher;
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::allow();
            }
        };
    }

    private function wipeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->wipeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}

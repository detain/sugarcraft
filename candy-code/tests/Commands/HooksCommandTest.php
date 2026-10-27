<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\HooksCommand;
use SugarCraft\Code\Hooks\BuiltIn\AuditHook;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap VIS-3a: the read-only `/hooks` command.
 *
 * Three layers get pinned here: {@see HookRegistry::all()} (the value copies
 * the panel reads), {@see HooksCommand::compose()} (pure wording over a hand
 * -built inspection array, so every row shape is pinned without a chain),
 * and the host half end-to-end — a real `~/.candy-code/hooks.yaml` under a
 * sandboxed HOME, built through the launch's own builder and CARRIED on the
 * workspace the panel reads (C-EXT-7: the panel itself never builds).
 * Dispatch of the command row itself rides SlashDispatchTest, which
 * drives every visible registry row through a real `Chat::update()`.
 */
final class HooksCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/crush-hooks-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);
        parent::tearDown();
    }

    // ---- HookRegistry::all() ------------------------------------------------

    public function testAllGroupsValueCopiesByEventAndNeverLeaksHookObjects(): void
    {
        $registry = new HookRegistry();
        $registry->register($this->probeHook('alpha', HookEvent::PreToolUse, 'Bash'));
        $registry->register($this->probeHook('beta', HookEvent::PreToolUse, 'Write|Edit'));
        $registry->register($this->probeHook('gamma', HookEvent::Stop, 'Any|Tool'));

        $all = $registry->all();

        self::assertSame(['PreToolUse', 'Stop'], array_keys($all), 'events keep registration order and empty events drop out');
        self::assertSame(['alpha', 'beta'], array_column($all['PreToolUse'], 'name'));
        self::assertSame(
            ['name' => 'alpha', 'matcher' => 'Bash', 'disabled' => false, 'builtIn' => false],
            $all['PreToolUse'][0],
        );
    }

    public function testAllCarriesTheDisabledFlagAndMarksShippedCodeBuiltIn(): void
    {
        $registry = new HookRegistry();
        $registry->register($this->probeHook('alpha', HookEvent::PreToolUse, 'Bash'));
        $registry->disable('alpha');
        $registry->register(new AuditHook($this->tempDir . '/audit.log'));

        $all = $registry->all();

        self::assertTrue($all['PreToolUse'][0]['disabled'], 'a disabled hook must show as disabled, not vanish');
        $auditEvent = (new AuditHook($this->tempDir . '/audit.log'))->event()->value;
        self::assertTrue($all[$auditEvent][0]['builtIn'], 'a class under Hooks\\BuiltIn\\ is shipped code — the panel says so');
        self::assertFalse($all['PreToolUse'][0]['builtIn'], 'a user-tier hook is not built-in just because the chain also carries built-ins');
    }

    // ---- HookManager::inspect() ----------------------------------------------

    public function testInspectPassesTheRegistryThroughWholly(): void
    {
        $registry = new HookRegistry();
        $registry->register($this->probeHook('alpha', HookEvent::PreToolUse, 'Bash'));
        $manager = new HookManager($registry);

        $inspect = $manager->inspect();

        self::assertSame($registry->all(), $inspect['hooks']);
        self::assertSame($registry->matcherFailures(), $inspect['matcherFailures'], 'inspect must not invent or drop failure rows');
    }

    // ---- compose(): pure wording ---------------------------------------------

    public function testComposeNullReportsNotMeasured(): void
    {
        $report = HooksCommand::compose(null, 120);
        $bold = \chr(42) . \chr(42);

        self::assertStringContainsString('not measured', $report);
        self::assertStringNotContainsString($bold.'Hooks'.$bold, $report, 'a chain that was never read must not print the table shape it never proved');
    }

    public function testComposeRendersEveryRowFlagAndFailure(): void
    {
        $inspect = [
            'hooks' => [
                'PreToolUse' => [
                    ['name' => 'deploy-check', 'matcher' => 'Bash', 'disabled' => false, 'builtIn' => false],
                    ['name' => 'protect-files', 'matcher' => 'Write|Edit', 'disabled' => true, 'builtIn' => true],
                ],
            ],
            'matcherFailures' => ['([unclosed' => 'compile failed'],
        ];

        $report = HooksCommand::compose($inspect, 120);
        $bold = \chr(42) . \chr(42);

        self::assertStringContainsString($bold.'Hooks'.$bold, $report);
        self::assertStringContainsString('Event', $report);
        self::assertStringContainsString('deploy-check', $report);
        self::assertStringContainsString('enabled', $report);
        self::assertStringContainsString('disabled', $report);
        self::assertStringContainsString('(built-in)', $report);
        self::assertStringContainsString('([unclosed', $report);
        self::assertStringContainsString('compile failed', $report);
    }

    public function testComposeEmptyChainPrintsTheNoneSentinel(): void
    {
        $report = HooksCommand::compose(['hooks' => [], 'matcherFailures' => []], 120);

        self::assertStringContainsString("  - none\n", $report . "\n", 'an empty chain is a read result — it says none, never an empty table');
        self::assertStringNotContainsString('Event', $report);
    }

    // ---- the host half, end to end -------------------------------------------

    public function testTheHostCommandReadsTheLaunchChainFromARealHooksFile(): void
    {
        $home = $this->useHomeSandbox($this->tempDir . '/home');
        mkdir($home . '/.candy-code', 0o700, true);
        file_put_contents(
            $home . '/.candy-code/hooks.yaml',
            "hooks:\n  PreToolUse:\n    - name: demo-hook\n      matcher: 'Bash'\n      command: 'true'\n",
        );

        // C-EXT-7: the host command reads the workspace's launch chain, so the
        // fixture builds it through the launch builder FIRST and carries it on
        // the context — the panel itself never rebuilds anything.
        $manager = \SugarCraft\Code\Cli\Bootstrap::hooks(null, $this->tempDir . '/project');
        $this->restoreHomeSandbox();

        $reply = (new \SugarCraft\Code\Host\Commands\HooksHostCommand())->run(
            \SugarCraft\Code\Host\Commands\CommandContext::new(
                history: [Message::user('go')],
                root: $this->tempDir . '/project',
                workspace: \SugarCraft\Code\Host\WorkspaceContext::new(hooks: $manager),
            ),
            '/hooks',
        );
        $text = implode("\n", array_map(static fn (Message $m): string => $m->content, $reply->rows));

        self::assertStringContainsString('demo-hook', $text, 'the user-tier entry must reach the panel through the chain carried on the workspace');
        self::assertStringContainsString('PreToolUse', $text);
        self::assertStringNotContainsString('not measured', $text);
    }

    // ---- the dispatch surface claim -------------------------------------------

    public function testHooksIsAReadOnlySafeCommand(): void
    {
        $allowlist = (new \ReflectionClass(Chat::class))
            ->getReflectionConstant('READ_ONLY_COMMANDS')
            ->getValue();

        self::assertContains('hooks', $allowlist, '/hooks reads a chain and prints — it must answer in a read-only session');
    }

    private function probeHook(string $name, HookEvent $event, string $matcher): HookInterface
    {
        return new class ($name, $event, $matcher) implements HookInterface {
            public function __construct(
                private readonly string $hookName,
                private readonly HookEvent $hookEvent,
                private readonly string $hookMatcher,
            ) {
            }

            public function name(): string
            {
                return $this->hookName;
            }

            public function event(): HookEvent
            {
                return $this->hookEvent;
            }

            public function matcher(): string
            {
                return $this->hookMatcher;
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::allow();
            }
        };
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}

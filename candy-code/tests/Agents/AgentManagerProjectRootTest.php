<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Agents\SuspendedDelegations;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Messages\Message;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall as WireToolCall;
use SugarCraft\Code\Tools\ToolResult;

/**
 * Audit F-J3-rem(a): a sub-agent's path rules judge the file the tool opens.
 *
 * The main tool loop has handed its gate the project root since `3b7d2fd33`,
 * so `Deny Read(/proj/secret.txt)` there stops `secret.txt`, `./secret.txt`
 * and a symlink to it. {@see AgentManager} judged the same call with no root —
 * the session gate's `evaluate()` and the agent's own `tools` /
 * `disallowedTools` grant both — so every one of those spellings walked past
 * the rule on the sub-agent path.
 *
 * Every sub-agent run here is a Task delegation (CC1C-c2b): TaskTool's engine
 * path, the one every production registration binds, where the session gate
 * and the agent's declaration ({@see \SugarCraft\Code\Hooks\BuiltIn\SubAgentGrantHook})
 * judge each call with the root the engine hands its hook context. A refused
 * call is that call's error result and the run goes on, so each test reads
 * the refusal off the tool turn the model received and checks that the tool
 * never ran.
 *
 * Fixture tree (a fresh temp dir per test):
 *
 *     root/secret.txt
 *     root/notes        -> secret.txt
 *     root/sub/
 *     root/src/a.php
 *     root/src/link     -> ../../outside/target.php
 *     outside/target.php
 */
final class AgentManagerProjectRootTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;

    private string $tempDir;
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_agent_root_' . bin2hex(random_bytes(6));
        $this->root = $this->tempDir . '/root';
        mkdir($this->root . '/sub', 0o700, true);
        mkdir($this->root . '/src', 0o700, true);
        mkdir($this->tempDir . '/outside', 0o700, true);
        // Canonical from here on, so an absolute pattern spelled with it names
        // the same path the resolver reaches (macOS's /tmp is a symlink).
        $this->root = (string) realpath($this->root);

        file_put_contents($this->root . '/secret.txt', 'secret');
        file_put_contents($this->root . '/src/a.php', '<?php');
        file_put_contents($this->tempDir . '/outside/target.php', '<?php');
        symlink('secret.txt', $this->root . '/notes');
        symlink('../../outside/target.php', $this->root . '/src/link');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);

        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function secretSpellings(): iterable
    {
        yield 'relative' => ['secret.txt'];
        yield 'dot-relative' => ['./secret.txt'];
        yield 'dot-dot' => ['sub/../secret.txt'];
        yield 'symlink' => ['notes'];
    }

    /**
     * The session gate: a `permissionRules` deny written with the absolute
     * path the user sees stops every other name for the same file.
     */
    #[DataProvider('secretSpellings')]
    public function testTheSessionGateDeniesEverySpellingOfAnAbsolutePathRule(string $spelling): void
    {
        $refusal = $this->runCall(
            new PermissionGate(
                PermissionMode::BypassPermissions,
                [new PermissionRule('Read(' . $this->root . '/secret.txt)', PermissionAction::Deny)],
            ),
            $this->root,
            $this->agent(),
            'Read',
            ['file_path' => $spelling],
        );

        $this->assertNotNull($refusal, "`{$spelling}` names the denied file and must be refused");
        $this->assertStringContainsString("Permission mode 'bypass-permissions' does not allow Read", $refusal);
    }

    /**
     * The contrast that makes the fix visible: the same gate on an engine
     * given no root judges a relative spelling against the process directory,
     * not the project, so `secret.txt` is not the denied file there. Kept
     * because that is the documented behaviour of every root-less embedder,
     * not because it is good.
     */
    public function testWithoutARootTheSessionGateDoesNotJudgeTheSpellingAtTheProject(): void
    {
        $this->assertNull($this->runCall(
            new PermissionGate(
                PermissionMode::BypassPermissions,
                [new PermissionRule('Read(' . $this->root . '/secret.txt)', PermissionAction::Deny)],
            ),
            null,
            $this->agent(),
            'Read',
            ['file_path' => 'secret.txt'],
        ));
    }

    /**
     * The agent's own denylist is matched with the root too: a preset's
     * `disallowedTools: [Read(/proj/secret.txt)]` stops a symlink to it.
     */
    public function testTheAgentsDenylistStopsASymlinkToADeniedFile(): void
    {
        $agent = $this->agent(disallowedTools: ['Read(' . $this->root . '/secret.txt)']);

        $refusal = $this->runCall(new PermissionGate(PermissionMode::BypassPermissions), $this->root, $agent, 'Read', ['file_path' => 'notes']);

        $this->assertNotNull($refusal, 'a symlink to a file the agent itself refuses must be refused');
        $this->assertStringContainsString('is refused by the denylist', $refusal);
    }

    /**
     * The agent's own grant cannot be laundered through a symlink: `src/link`
     * passes the spelling half of `Write(src/*)` and fails the resolved half,
     * because the file it writes is outside `src/`. An ordinary file under
     * `src/` is still granted.
     */
    public function testTheAgentsGrantDoesNotFollowASymlinkOutOfTheGrantedTree(): void
    {
        $agent = $this->agent(tools: ['Write(src/*)']);

        $refusal = $this->runCall(new PermissionGate(PermissionMode::BypassPermissions), $this->root, $agent, 'Write', ['file_path' => 'src/link', 'content' => 'x']);
        $this->assertNotNull($refusal, 'a write through a symlink out of src/ is not inside the Write(src/*) grant');
        $this->assertStringContainsString('is outside the tool grant', $refusal);

        $this->assertNull(
            $this->runCall(new PermissionGate(PermissionMode::BypassPermissions), $this->root, $agent, 'Write', ['file_path' => 'src/a.php', 'content' => 'x']),
            'a real file under src/ is still granted',
        );
    }

    /**
     * The root reaches the mode evaluator as well as the rules: under
     * `accept-edits` a sub-agent's write through a symlink out of the project
     * is not an in-root edit, so it asks — and with no approver attached, an
     * Ask is refused. Root-less, `src/link` read as a contained relative path
     * and was granted.
     */
    public function testAcceptEditsAsksAboutASubAgentWriteThroughASymlinkOutOfTheRoot(): void
    {
        $refusal = $this->runCall(new PermissionGate(PermissionMode::AcceptEdits), $this->root, $this->agent(), 'Write', ['file_path' => 'src/link', 'content' => 'x']);
        $this->assertNotNull($refusal, 'a write that lands outside the root is not an in-root edit');
        $this->assertStringContainsString('no approver is attached to this run: Allow Write to run?', $refusal);

        $this->assertNull(
            $this->runCall(new PermissionGate(PermissionMode::AcceptEdits), $this->root, $this->agent(), 'Write', ['file_path' => 'src/a.php', 'content' => 'x']),
            'an in-root edit is still granted without asking',
        );
    }

    /**
     * Production wiring: {@see Bootstrap::agentManager()} hands the manager the
     * session root. The built-in `coder`'s gate — built from the same
     * `permissionRules` the main loop reads — denies `secret.txt` under an
     * absolute rule when judged at that root, and the manager's own grant
     * check ({@see AgentManager::grantRefusalFor()}) falls back to it when its
     * caller hands in none, so an agent's absolute denylist entry stops the
     * relative spelling. Driven through those seams directly, since the
     * launch's provider returns no tool calls.
     */
    public function testBootstrapHandsTheManagerTheSessionRoot(): void
    {
        $home = $this->tempDir . '/home';
        mkdir($home . '/.candy-code', 0o700, true);
        file_put_contents($home . '/.candy-code/config.json', (string) json_encode([
            'permissionRules' => [['pattern' => 'Read(' . $this->root . '/secret.txt)', 'action' => 'deny']],
        ]));

        $originalHome = getenv('HOME');
        $originalServerHome = $_SERVER['HOME'] ?? null;
        $originalMode = getenv('CANDY_CODE_PERMISSION_MODE');
        putenv('HOME=' . $home);
        $_SERVER['HOME'] = $home;
        putenv('CANDY_CODE_PERMISSION_MODE');
        $this->clearBackendSelectionEnv();

        try {
            $manager = Bootstrap::agentManager($this->root);
            $read = new ToolCall('Read', ['file_path' => 'secret.txt']);

            $coder = $manager->createSubAgent('coder', 'read it', PermissionMode::BypassPermissions);
            $this->assertNotNull($coder->permissionGate);
            $this->assertSame(
                PermissionDecision::Deny,
                $coder->permissionGate->evaluate($read, $this->root),
                'the launch-built sub-agent gate must judge `secret.txt` as the file under --root',
            );

            $manager->register($this->agent(disallowedTools: ['Read(' . $this->root . '/secret.txt)']));
            $rooted = $manager->createSubAgent('rooted', 'read it', PermissionMode::BypassPermissions);
            $refusal = $manager->grantRefusalFor($read, $rooted);
            $this->assertNotNull($refusal, 'the manager judges the agent\'s own denylist at the session root it was handed');
            $this->assertStringContainsString('is refused by the denylist', $refusal);
        } finally {
            $this->restoreBackendSelectionEnv();
            $originalHome === false ? putenv('HOME') : putenv('HOME=' . $originalHome);
            if ($originalServerHome === null) {
                unset($_SERVER['HOME']);
            } else {
                $_SERVER['HOME'] = $originalServerHome;
            }
            $originalMode === false
                ? putenv('CANDY_CODE_PERMISSION_MODE')
                : putenv('CANDY_CODE_PERMISSION_MODE=' . $originalMode);
        }
    }

    /**
     * Delegate one run of $agent through {@see TaskTool} whose first step
     * makes the single call $tool($args) and whose second reports, on an
     * engine whose session gate is $gate and whose root is $projectRoot.
     * Returns the refusal the model received for the call, or null when the
     * call ran.
     *
     * @param array<string, mixed> $args
     */
    private function runCall(PermissionGate $gate, ?string $projectRoot, Agent $agent, string $tool, array $args): ?string
    {
        $manager = new AgentManager(
            provider: new ScriptedProvider([]),
            skillRegistry: new SkillRegistry(),
            permissionGateFactory: static fn(): PermissionGate => $gate,
            projectRoot: $projectRoot,
        );
        $manager->register($agent);

        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'calling', toolCalls: [new WireToolCall('call-1', $tool, $args)]),
            new CompleteResponse(content: 'reported'),
        ]);
        $recorder = new class ($tool) implements Tool {
            public int $runs = 0;

            public function __construct(private readonly string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return "records {$this->name} runs";
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $args): ToolResult
            {
                $this->runs++;

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: $this->name . ' ran');
            }
        };
        $engine = EngineBackend::new($provider, 'm')
            ->withTools([$recorder])
            ->withPermissionGate($gate)
            ->withRoot($projectRoot);

        $result = (new TaskTool($manager, suspended: new SuspendedDelegations($this->tempDir . '/suspended')))
            ->withEngine($engine)
            ->execute(['description' => 'Rooted check', 'prompt' => 'do the thing', 'agent' => $agent->name, 'id' => 'task-call']);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertCount(2, $provider->requests, 'the run carried the call\'s result back to the model');
        $runs = $manager->runs();
        $this->assertCount(1, $runs);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $runs[0]->status, 'a refused call is not a failed run');

        $turns = array_values(array_filter(
            $provider->requests[1]->messages,
            static fn (mixed $message): bool => $message instanceof Message && $message->role() === 'tool',
        ));
        $this->assertCount(1, $turns);
        if ($recorder->runs === 1) {
            return null;
        }
        $this->assertSame(0, $recorder->runs, 'a refused call never ran');

        return $turns[0]->content();
    }

    /**
     * @param list<string> $tools
     * @param list<string> $disallowedTools
     */
    private function agent(array $tools = [], array $disallowedTools = []): Agent
    {
        return new Agent(
            name: 'rooted',
            description: 'rooted description',
            prompt: 'Test prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: $tools,
            skillNames: [],
            hooks: [],
            isActive: true,
            disallowedTools: $disallowedTools,
        );
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}

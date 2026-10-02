<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Crush\ToolCall;

/**
 * Audit F-J3-rem(a): a sub-agent's path rules judge the file the tool opens.
 *
 * The main tool loop has handed its gate the project root since `3b7d2fd33`,
 * so `Deny Read(/proj/secret.txt)` there stops `secret.txt`, `./secret.txt`
 * and a symlink to it. {@see AgentManager} judged the same call with no root —
 * the session gate's `evaluate()` and the agent's own `tools` /
 * `disallowedTools` grant both — so every one of those spellings walked past
 * the rule on the sub-agent path. Each test below fails with the root dropped
 * from either the manager's three match sites or Bootstrap's construction.
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

    /** @var list<ToolCall> the calls the next provider response carries */
    private array $pendingCalls = [];

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
        $manager = $this->manager(
            new PermissionGate(
                PermissionMode::BypassPermissions,
                [new PermissionRule('Read(' . $this->root . '/secret.txt)', PermissionAction::Deny)],
            ),
            projectRoot: $this->root,
        );

        $caught = $this->runCall($manager, $this->agent(), new ToolCall('Read', ['file_path' => $spelling]));

        $this->assertNotNull($caught, "`{$spelling}` names the denied file and must be refused");
        $this->assertStringContainsString('denied by permission gate', $caught->getMessage());
    }

    /**
     * The contrast that makes the fix visible: the same gate on a manager
     * given no root still judges the spelling only. Kept because that is the
     * documented behaviour of every root-less embedder, not because it is good.
     */
    public function testWithoutARootTheSessionGateStillJudgesTheSpellingOnly(): void
    {
        $manager = $this->manager(
            new PermissionGate(
                PermissionMode::BypassPermissions,
                [new PermissionRule('Read(' . $this->root . '/secret.txt)', PermissionAction::Deny)],
            ),
            projectRoot: null,
        );

        $this->assertNull($this->runCall($manager, $this->agent(), new ToolCall('Read', ['file_path' => 'secret.txt'])));
    }

    /**
     * The agent's own denylist is matched with the root too: a preset's
     * `disallowedTools: [Read(/proj/secret.txt)]` stops a symlink to it.
     */
    public function testTheAgentsDenylistStopsASymlinkToADeniedFile(): void
    {
        $manager = $this->manager(new PermissionGate(PermissionMode::BypassPermissions), projectRoot: $this->root);
        $agent = $this->agent(disallowedTools: ['Read(' . $this->root . '/secret.txt)']);

        $caught = $this->runCall($manager, $agent, new ToolCall('Read', ['file_path' => 'notes']));

        $this->assertNotNull($caught, 'a symlink to a file the agent itself refuses must be refused');
        $this->assertStringContainsString('is refused by the denylist', $caught->getMessage());
    }

    /**
     * The agent's own grant cannot be laundered through a symlink: `src/link`
     * passes the spelling half of `Write(src/*)` and fails the resolved half,
     * because the file it writes is outside `src/`. An ordinary file under
     * `src/` is still granted.
     */
    public function testTheAgentsGrantDoesNotFollowASymlinkOutOfTheGrantedTree(): void
    {
        $manager = $this->manager(new PermissionGate(PermissionMode::BypassPermissions), projectRoot: $this->root);
        $agent = $this->agent(tools: ['Write(src/*)']);

        $caught = $this->runCall($manager, $agent, new ToolCall('Write', ['file_path' => 'src/link', 'content' => 'x']));
        $this->assertNotNull($caught, 'a write through a symlink out of src/ is not inside the Write(src/*) grant');
        $this->assertStringContainsString('is outside the tool grant', $caught->getMessage());

        $manager = $this->manager(new PermissionGate(PermissionMode::BypassPermissions), projectRoot: $this->root);
        $this->assertNull(
            $this->runCall($manager, $agent, new ToolCall('Write', ['file_path' => 'src/a.php', 'content' => 'x'])),
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
        $manager = $this->manager(new PermissionGate(PermissionMode::AcceptEdits), projectRoot: $this->root);

        $caught = $this->runCall($manager, $this->agent(), new ToolCall('Write', ['file_path' => 'src/link', 'content' => 'x']));
        $this->assertNotNull($caught, 'a write that lands outside the root is not an in-root edit');
        $this->assertStringContainsString('requires approval', $caught->getMessage());

        $manager = $this->manager(new PermissionGate(PermissionMode::AcceptEdits), projectRoot: $this->root);
        $this->assertNull(
            $this->runCall($manager, $this->agent(), new ToolCall('Write', ['file_path' => 'src/a.php', 'content' => 'x'])),
            'an in-root edit is still granted without asking',
        );
    }

    /**
     * Production wiring: {@see Bootstrap::agentManager()} hands the manager the
     * session root, so the built-in `coder`'s gate — built from the same
     * `permissionRules` the main loop reads — denies `secret.txt` under an
     * absolute rule. Driven through the manager's own evaluation seam, since
     * the launch's provider returns no tool calls.
     */
    public function testBootstrapHandsTheManagerTheSessionRoot(): void
    {
        $home = $this->tempDir . '/home';
        mkdir($home . '/.sugar-crush', 0o700, true);
        file_put_contents($home . '/.sugar-crush/config.json', (string) json_encode([
            'permissionRules' => [['pattern' => 'Read(' . $this->root . '/secret.txt)', 'action' => 'deny']],
        ]));

        $originalHome = getenv('HOME');
        $originalServerHome = $_SERVER['HOME'] ?? null;
        $originalMode = getenv('SUGARCRUSH_PERMISSION_MODE');
        putenv('HOME=' . $home);
        $_SERVER['HOME'] = $home;
        putenv('SUGARCRUSH_PERMISSION_MODE');
        $this->clearBackendSelectionEnv();

        try {
            $manager = Bootstrap::agentManager($this->root);
            $subAgent = $manager->createSubAgent('coder', 'read it', PermissionMode::BypassPermissions);

            $evaluate = new \ReflectionMethod(AgentManager::class, 'evaluateToolCalls');
            $caught = null;
            try {
                $evaluate->invoke($manager, [new ToolCall('Read', ['file_path' => 'secret.txt'])], $subAgent);
            } catch (\RuntimeException $e) {
                $caught = $e;
            }

            $this->assertNotNull($caught, 'the launch-built sub-agent gate must judge `secret.txt` as the file under --root');
            $this->assertStringContainsString('denied by permission gate', $caught->getMessage());
        } finally {
            $this->restoreBackendSelectionEnv();
            $originalHome === false ? putenv('HOME') : putenv('HOME=' . $originalHome);
            if ($originalServerHome === null) {
                unset($_SERVER['HOME']);
            } else {
                $_SERVER['HOME'] = $originalServerHome;
            }
            $originalMode === false
                ? putenv('SUGARCRUSH_PERMISSION_MODE')
                : putenv('SUGARCRUSH_PERMISSION_MODE=' . $originalMode);
        }
    }

    private function manager(PermissionGate $gate, ?string $projectRoot): AgentManager
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('supportsStreaming')->willReturn(false);
        $provider->method('complete')->willReturnCallback(
            fn() => new CompleteResponse(content: 'done', toolCalls: $this->pendingCalls),
        );

        return new AgentManager(
            provider: $provider,
            skillRegistry: new SkillRegistry(),
            permissionGateFactory: static fn(): PermissionGate => $gate,
            projectRoot: $projectRoot,
        );
    }

    /**
     * Run one sub-agent whose single response carries `$call`, and return what
     * it threw (null when the call was let through and the run completed).
     */
    private function runCall(AgentManager $manager, Agent $agent, ToolCall $call): ?\RuntimeException
    {
        $this->pendingCalls = [$call];
        $manager->register($agent);
        $subAgent = $manager->createSubAgent($agent->name, 'do the thing');

        // Held outside the try: a failing assertion is-a RuntimeException
        // (see SwallowingCatchCensusTest).
        $caught = null;
        try {
            iterator_to_array($manager->executeSubAgent($subAgent->id));
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        if ($caught === null) {
            $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
        }

        return $caught;
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

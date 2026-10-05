<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\Isolation;
use SugarCraft\Crush\Agents\WorktreeManager;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\DelegatedOutputFence;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Sessions\BackgroundSessionRunner;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Workspace\GitRunner;

/**
 * Roadmap 4.3-2, the daemon half: a background `Task` session runs the roster
 * agent through `TaskTool` — the preset's grant, the step loop, the resume id —
 * rather than the one bare completion a `/bg` session makes, and never under a
 * wider permission mode than the session that started it.
 */
final class BackgroundAgentRunnerTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_bg_agent_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700, true);
        $this->useHomeSandbox($this->dir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeTree($this->dir);
    }

    public function testTheDaemonRunsTheRosterAgentAndBuffersItsFencedReportAndResumeId(): void
    {
        $probe = self::probe('Grep', 'two matches');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Grep', ['pattern' => 'TODO'])], tokensUsed: 300, costUsd: 0.01),
            new CompleteResponse(content: 'found two TODOs', tokensUsed: 200, costUsd: 0.005),
        ]);

        $exit = $this->runner(['agent' => 'reviewer', 'description' => 'Find TODOs'])
            ->executeTask(EngineBackend::new($provider, 'm')->withoutHooks()->withTools([$probe]), null, $this->manager($probe, ['Grep']));

        $buffer = $this->buffer();
        $this->assertSame(0, $exit, $buffer);
        $this->assertCount(1, $probe->calls, 'a whole agentic run, not one completion');
        $this->assertStringContainsString(DelegatedOutputFence::wrap('found two TODOs'), $buffer);
        $this->assertMatchesRegularExpression('/\[sub-agent "reviewer" finished; .*"resume": "[0-9a-f]{16}"/', $buffer, 'the result names the id a later Task call continues it by');
        $this->assertMatchesRegularExpression('/^\[session:usage\] tokens=[1-9]\d* cost=0\.0\d+$/m', $buffer, 'what the run cost reaches the stats line');
        $this->assertStringEndsWith("[session:task:complete]\n", $buffer);
        $this->assertStringContainsString('You are reviewer.', serialize($provider->requests[0]->messages), 'the preset\'s own prompt');
    }

    public function testARefusedDelegationSettlesFailedWithItsReason(): void
    {
        $exit = $this->runner(['agent' => 'ghost'])
            ->executeTask(EngineBackend::new(new ScriptedProvider([]), 'm')->withoutHooks(), null, $this->manager(self::probe('Grep', ''), ['Grep']));

        $this->assertSame(1, $exit);
        $this->assertMatchesRegularExpression('/^\[session:task:failed\] agent "ghost" is not in the session roster/m', $this->buffer());
    }

    /**
     * Roadmap 4.1-1, relaxed: a model the session's provider cannot serve —
     * here a Claude Code tier name on a non-Claude session — does not fail
     * the background run; it runs on the session's model and the buffered
     * result (what the parent conversation reads) says so.
     */
    public function testAModelTheProviderCannotServeRunsOnTheSessionModelWithANote(): void
    {
        $probe = self::probe('Grep', '');
        $provider = new ScriptedProvider([new CompleteResponse(content: 'found nothing')]);

        $exit = $this->runner(['agent' => 'reviewer', 'model' => 'sonnet'])
            ->executeTask(EngineBackend::new($provider, 'Qwen/Qwen3.8-Flash-Next')->withoutHooks()->withTools([$probe]), null, $this->manager($probe, ['Grep']));

        $buffer = $this->buffer();
        $this->assertSame(0, $exit, $buffer);
        $this->assertSame('Qwen/Qwen3.8-Flash-Next', $provider->requests[0]->model);
        $this->assertStringContainsString('[model: Qwen/Qwen3.8-Flash-Next (this Task call asked for "sonnet", a Claude Code tier name', $buffer);
        $this->assertStringEndsWith("[session:task:complete]\n", $buffer);
    }

    /**
     * The daemon's gate is the headless launch's (`bypass-permissions` by
     * default) and the agent's own mode does not narrow it here, so the only
     * thing that can stop the write is the delegating session's mode.
     */
    public function testTheSessionsStricterPermissionModeGovernsTheDaemon(): void
    {
        foreach (['plan' => 0, '' => 1] as $sessionMode => $writes) {
            $write = self::probe('Write', 'wrote it');
            $provider = new ScriptedProvider([
                new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Write', ['file_path' => 'x.txt', 'content' => 'x'])]),
                new CompleteResponse(content: 'done'),
            ]);
            $backend = EngineBackend::new($provider, 'm')
                ->withTools([$write])
                ->withRoot($this->dir)
                ->withPermissionGate(new PermissionGate(PermissionMode::BypassPermissions));
            $delegation = $sessionMode === '' ? ['agent' => 'writer'] : ['agent' => 'writer', 'permissionMode' => $sessionMode];

            $exit = $this->runner($delegation)->executeTask($backend, null, $this->manager($write, ['Write'], PermissionMode::BypassPermissions));

            $this->assertSame(0, $exit, $this->buffer());
            $this->assertCount($writes, $write->calls, $sessionMode === ''
                ? 'with no session mode passed the daemon\'s own gate decides (control)'
                : 'a `plan` session\'s background agent cannot write, whatever the daemon\'s own mode');
            @unlink($this->dir . '/session.buffer');
        }
    }

    /**
     * Roadmap 4.9 in the daemon: a background `Task` for an
     * `isolation: worktree` preset gets a git worktree of the daemon's working
     * tree, as the launch's own `Task` does — not a refusal for want of a
     * worktree manager. Needs a real `git`.
     */
    public function testAnIsolatedPresetRunsInAWorktreeOfTheDaemonsCheckout(): void
    {
        if (!GitRunner::available()) {
            self::markTestSkipped('needs a git executable');
        }
        $saved = [];
        foreach (['SUGARCRUSH_WORKTREES_DIR', 'SUGAR_CRUSH_WORKTREES_DIR'] as $name) {
            $saved[$name] = getenv($name);
            putenv($name);
        }
        try {
            $repo = $this->dir . '/repo';
            mkdir($repo, 0o700, true);
            $git = GitRunner::new($repo);
            self::assertTrue($git->run('init', '-q', '-b', 'main')['ok']);
            file_put_contents($repo . '/a.txt', "one\n");
            self::assertTrue($git->run('add', 'a.txt')['ok']);
            self::assertTrue($git->run('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'initial')['ok']);

            $probe = self::probe('Grep', 'two matches');
            $provider = new ScriptedProvider([
                new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Grep', ['pattern' => 'TODO'])]),
                new CompleteResponse(content: 'found two TODOs'),
            ]);
            $agents = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: [$probe], toolUniverse: [$probe]);
            $agents->register(RosterAgent::named('isolated', ['Grep'], maxTurns: 5, isolation: Isolation::Worktree));

            $exit = $this->runner(['agent' => 'isolated'], $repo)
                ->executeTask(EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($repo)->withTools([$probe]), null, $agents);

            $buffer = $this->buffer();
            $this->assertStringNotContainsString('no worktree manager', $buffer);
            $this->assertSame(0, $exit, $buffer);
            $this->assertCount(1, $probe->calls, 'the isolated run did its work');
            $this->assertStringContainsString(DelegatedOutputFence::wrap('found two TODOs'), $buffer);
            $this->assertDirectoryExists($repo . '/.sugar-crush/worktrees', 'its tree was made under the daemon\'s checkout');
            $this->assertSame([], WorktreeManager::new($repo)->listWorktrees(), 'and removed with the run, which left no work');
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    public function testABackendWithNoEngineFailsNamingWhy(): void
    {
        $exit = $this->runner(['agent' => 'reviewer'])->executeTask(new EchoBackend(), null, $this->manager(self::probe('Grep', ''), ['Grep']));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('needs the engine backend', $this->buffer());
    }

    public function testTheSpawnConfigCarriesTheDelegationAndOnlyStrings(): void
    {
        $code = (new BackgroundSupervisor(tempRoot: $this->dir))->buildSessionDaemonCode(
            '/s.sock', '/b.buffer', '/t.token', 'sess_1', 'task', $this->dir, '', '', 60,
            delegation: ['agent' => 'reviewer', 'resume' => 'abc'],
        );
        $this->assertStringContainsString('"delegation":{"agent":"reviewer","resume":"abc"}', $code);

        $runner = BackgroundSessionRunner::fromConfig([
            'sessionId' => 'sess_1',
            'bufferPath' => '/b',
            'socketPath' => '/s',
            'delegation' => ['agent' => 'reviewer', 'model' => ['x'], 'scope' => 'sess-a', 'junk' => 'y'],
        ]);
        $this->assertSame(['agent' => 'reviewer', 'scope' => 'sess-a'], $runner->delegation);
        $this->assertSame([], BackgroundSessionRunner::fromConfig(['delegation' => ['model' => 'm']])->delegation, 'no agent, no delegation');
        $this->assertSame([], BackgroundSessionRunner::fromConfig([])->delegation);
    }

    // ── harness ─────────────────────────────────────────────────────────

    /** @param array<string, string> $delegation */
    private function runner(array $delegation, ?string $workingDirectory = null): BackgroundSessionRunner
    {
        return new BackgroundSessionRunner(
            sessionId: 'sess_20261004120000_abcdef01',
            socketPath: $this->dir . '/s.sock',
            bufferPath: $this->dir . '/session.buffer',
            task: 'look for TODOs in src/',
            workingDirectory: $workingDirectory ?? $this->dir,
            delegation: $delegation,
        );
    }

    /** @param list<string> $grant */
    private function manager(Tool $tool, array $grant, ?PermissionMode $writerMode = null): AgentManager
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: [$tool], toolUniverse: [$tool]);
        $manager->register(RosterAgent::named('reviewer', $grant, maxTurns: 5));
        if ($writerMode !== null) {
            $manager->register(new Agent(
                name: 'writer',
                description: 'test agent writer',
                prompt: 'You are writer.',
                model: 'test-model',
                provider: 'test',
                tools: $grant,
                skillNames: [],
                hooks: [],
                isActive: true,
                permissionMode: $writerMode,
                maxTurns: 5,
                inheritsModel: true,
            ));
        }

        return $manager;
    }

    private function buffer(): string
    {
        return (string) @file_get_contents($this->dir . '/session.buffer');
    }

    /**
     * @return Tool&object{calls: list<array<string, mixed>>}
     */
    private static function probe(string $name, string $output): Tool
    {
        return new class ($name, $output) implements Tool {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct(private string $name, private string $output)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'records its calls';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $id = (string) ($args['id'] ?? '');
                unset($args['id']);
                $this->calls[] = $args;

                return new ToolResult(toolCallId: $id, content: $this->output);
            }
        };
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }
}

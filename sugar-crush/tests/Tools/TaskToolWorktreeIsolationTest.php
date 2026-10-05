<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\Isolation;
use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Agents\WorktreeManager;
use SugarCraft\Crush\BackgroundSessionSpawnedMsg;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Host\Commands\BackgroundCommand;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\DiscardsErrorLogTrait;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\AcceptsWorktreeJail;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Workspace\GitRunner;

/**
 * Roadmap 4.9: an agent whose preset says `isolation: worktree` runs in a git
 * worktree of its own — its tools re-jailed there — and the tree is removed
 * with its branch when the run leaves no work, kept and named when it does,
 * and continued in by a resume. A run that cannot be isolated is refused, not
 * run in the shared checkout. Needs a real `git`.
 */
final class TaskToolWorktreeIsolationTest extends TestCase
{
    use DiscardsErrorLogTrait;

    private string $root;

    private string $repo;

    private SuspendedDelegations $store;

    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        if (!GitRunner::available()) {
            self::markTestSkipped('needs a git executable');
        }
        foreach (['SUGARCRUSH_WORKTREES_DIR', 'SUGAR_CRUSH_WORKTREES_DIR'] as $name) {
            $this->env[$name] = getenv($name);
            putenv($name);
        }
        $this->root = sys_get_temp_dir() . '/sc-wt-iso-' . bin2hex(random_bytes(5));
        $this->repo = $this->root . '/repo';
        mkdir($this->repo, 0o700, true);
        $git = GitRunner::new($this->repo);
        self::assertTrue($git->run('init', '-q', '-b', 'main')['ok']);
        file_put_contents($this->repo . '/a.txt', "one\n");
        self::assertTrue($git->run('add', 'a.txt')['ok']);
        self::assertTrue($git->run('commit', '-q', '-m', 'initial')['ok']);
        $this->store = new SuspendedDelegations($this->root . '/suspended');
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        if (isset($this->root) && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testARunThatChangesNothingWorksInItsOwnTreeWhichIsRemovedWithItsBranch(): void
    {
        $probe = self::probe(false);
        $result = $this->task($probe, $this->manager())->execute(self::call());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertCount(1, $probe->log->roots);
        $tree = $probe->log->roots[0];
        $this->assertStringStartsWith($this->repo . '/.sugar-crush/worktrees/', $tree, 'the run\'s tools were re-jailed to a tree under the project');
        $this->assertDirectoryDoesNotExist($tree, 'a tree with no work is removed when the run ends');
        $this->assertStringNotContainsString('worktree', $result->content());
        $this->assertSame([], $this->agentBranches(), 'and its branch with it');
        $this->assertSame([], $this->manager()->listWorktrees());
    }

    public function testATreeWithWorkIsKeptNamedAndContinuedByAResume(): void
    {
        $probe = self::probe(true);
        $manager = $this->manager();
        $task = $this->task($probe, $manager);

        $first = $task->execute(self::call());

        $this->assertFalse($first->isError(), $first->content());
        $tree = $probe->log->roots[0];
        $this->assertFileExists($tree . '/made-by-agent.txt', 'the run wrote in its own tree');
        $this->assertFileDoesNotExist($this->repo . '/made-by-agent.txt', 'and not in the checkout');
        $this->assertStringContainsString('its changes were kept there, not in this checkout: ' . $tree . ' on branch agent-', $first->content());
        $this->assertCount(1, $this->agentBranches());

        preg_match('/"resume": "([0-9a-f]{16})"/', $first->content(), $m);
        $this->assertArrayHasKey(1, $m);
        $saved = $this->store->load($m[1]);
        $this->assertIsString($saved['worktree'] ?? null, 'the suspension names the kept tree');

        $probe->log->write = false;
        // A resume in a later turn runs on a manager that loaded the registry
        // before the tree existed — the forked turn child's position.
        $stale = $this->task($probe, $this->managerLoadedBefore());
        $followUp = $stale->execute(self::call(['resume' => $m[1], 'prompt' => 'carry on']));

        $this->assertFalse($followUp->isError(), $followUp->content());
        $this->assertSame([$tree, $tree], $probe->log->roots, 'the resume works in the same tree');
        $this->assertDirectoryExists($tree, 'which still holds the first run\'s work, so it stays');
        $this->assertStringContainsString('its changes were kept there', $followUp->content());
    }

    public function testAPresetWithoutIsolationRunsInTheCheckout(): void
    {
        $probe = self::probe(false);
        $result = $this->task($probe, $this->manager(), null)->execute(self::call());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame([null], $probe->log->roots, 'no jail: the tools answer from the session\'s checkout');
        $this->assertDirectoryDoesNotExist($this->repo . '/.sugar-crush/worktrees');
    }

    public function testARunThatCannotBeIsolatedIsRefusedRatherThanRunInTheCheckout(): void
    {
        $probe = self::probe(true);

        $noManager = $this->task($probe, null)->execute(self::call());
        $this->assertTrue($noManager->isError());
        $this->assertStringContainsString('declares `isolation: worktree`, but this session has no worktree manager', $noManager->content());

        $notARepo = $this->root . '/plain';
        mkdir($notARepo);
        $failed = null;
        $log = self::withErrorLogDiscarded(function () use ($probe, $notARepo, &$failed): void {
            $failed = $this->task($probe, WorktreeManager::new($notARepo))->execute(self::call());
        });
        $this->assertStringContainsString('git worktree add failed', $log, 'the manager said why on its notice seam');
        $this->assertTrue($failed->isError());
        $this->assertStringContainsString('its worktree could not be created', $failed->content());

        $this->assertSame([], $probe->log->roots, 'the run never started');
    }

    public function testABackgroundSessionSpawnNoticeNamesItsWorktree(): void
    {
        $notice = BackgroundCommand::spawnedNotice(new BackgroundSessionSpawnedMsg('/bg', 'job', 'bg_1', worktree: '/r/.sugar-crush/worktrees/bg-1'));

        $this->assertStringContainsString('It works in its own git worktree at /r/.sugar-crush/worktrees/bg-1', $notice);
        $this->assertStringNotContainsString('worktree', BackgroundCommand::spawnedNotice(new BackgroundSessionSpawnedMsg('/bg', 'job', 'bg_1')));
    }

    public function testTheWorktreeBaseIgnoresItselfSoTheCheckoutStaysClean(): void
    {
        $manager = $this->manager();
        $manager->createWorktree('probe-1');

        $status = GitRunner::new($this->repo)->run('status', '--porcelain');
        $this->assertTrue($status['ok']);
        $this->assertSame('', trim($status['stdout']), 'a tree under the project shows nowhere in the checkout\'s status');
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function manager(): WorktreeManager
    {
        return WorktreeManager::new($this->repo);
    }

    /** A manager whose in-memory registry predates every tree on disk. */
    private function managerLoadedBefore(): WorktreeManager
    {
        $manager = WorktreeManager::new($this->repo);
        (new \ReflectionProperty(WorktreeManager::class, 'registry'))->setValue($manager, []);

        return $manager;
    }

    private function task(Tool $probe, ?WorktreeManager $manager, ?Isolation $isolation = Isolation::Worktree): TaskTool
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'probe', [])]),
            new CompleteResponse(content: 'done'),
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c2', 'probe', [])]),
            new CompleteResponse(content: 'done again'),
        ], contextWindow: 1_000_000);
        $agents = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: [$probe], toolUniverse: [$probe]);
        $agents->register(RosterAgent::named('coder', ['probe'], maxTurns: 5, isolation: $isolation));

        return (new TaskTool($agents, suspended: $this->store))
            ->withEngine(EngineBackend::new($provider, 'm')->withRoot($this->repo)->withTools([$probe]))
            ->withWorktreeManager($manager);
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private static function call(array $overrides = []): array
    {
        return $overrides + ['description' => 'Fix it', 'prompt' => 'Fix the bug', 'agent' => 'coder'];
    }

    /** @return list<string> */
    private function agentBranches(): array
    {
        $out = GitRunner::new($this->repo)->run('branch', '--list', 'agent-*', '--format=%(refname:short)');

        return array_values(array_filter(explode("\n", trim($out['stdout']))));
    }

    /**
     * A path-resolving tool: records the root it was jailed to on each call
     * (null when it was not), and, while `$log->write` is set, writes a file
     * there. Every jailed copy shares the original's `$log`.
     *
     * @return Tool&AcceptsWorktreeJail&object{log: \stdClass}
     */
    private static function probe(bool $write): Tool
    {
        $log = new \stdClass();
        $log->roots = [];
        $log->write = $write;

        return new class ($log) implements Tool, AcceptsWorktreeJail {
            public function __construct(public \stdClass $log, private ?AgentPathJail $jail = null)
            {
            }

            public function withWorktreeJail(AgentPathJail $jail): static
            {
                return new static($this->log, $jail);
            }

            public function name(): string
            {
                return 'probe';
            }

            public function description(): string
            {
                return 'records the root it works in';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $root = $this->jail?->root();
                $this->log->roots[] = $root;
                if ($this->log->write && $root !== null) {
                    file_put_contents($root . '/made-by-agent.txt', "work\n");
                }

                return new ToolResult('', 'ok');
            }
        };
    }
}

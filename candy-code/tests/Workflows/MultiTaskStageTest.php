<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\ExecutorInterface;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * Roadmap 4.10-1: a regular stage runs EVERY task it lists, in order.
 *
 * `WorkflowEngine::executeStage()` used to run `$tasks[0]` and drop the rest
 * without a word, and a YAML stage could only ever hold one task. A stage now
 * chains its tasks — `{{prevResult}}` is the task before's output, each task's
 * result is named — fails fast, and YAML spells it as a `tasks:` list.
 */
final class MultiTaskStageTest extends TestCase
{
    private string $tempDir;

    /** @var list<string> every prompt the executor was handed, in order */
    private array $prompts = [];

    /** @var array<string, AgentStatus> prompt prefix => status to settle with */
    private array $statusFor = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_multitask_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir . '/fanenv');
        foreach (glob($this->tempDir . '/workflows/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir . '/workflows');
        @rmdir($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /** @internal the fake executor's body; public only so the anonymous class can reach it */
    public function dispatch(SubAgent $subAgent): AgentResult
    {
        $this->prompts[] = $subAgent->task;

        $status = AgentStatus::Completed;
        foreach ($this->statusFor as $prefix => $settled) {
            if (str_starts_with($subAgent->task, $prefix)) {
                $status = $settled;
            }
        }

        return new AgentResult(
            agentId: $subAgent->id,
            status: $status,
            output: 'OUT[' . $subAgent->task . ']',
            error: $status === AgentStatus::Completed ? null : new \RuntimeException('agent gave up'),
            tokensUsed: 5,
            costUsd: 0.25,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    public function testEveryTaskInAStageRunsInOrderAndChainsPrevResult(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $registry->register(
            (new WorkflowBuilder())
                ->name('chain')
                ->stage('work', [
                    Tasks::agent('architect')->name('plan')->prompt('plan it'),
                    Tasks::agent('coder')->prompt('build from {{prevResult}}'),
                    Tasks::agent('reviewer')->prompt('review {{prevResult}} against {{plan.results}}'),
                ])
                ->stage('after', Tasks::agent('coder')->prompt('ship {{work.output}}'))
                ->build(),
        );

        $result = $this->engine($registry)->run('chain');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(
            [
                'plan it',
                'build from OUT[plan it]',
                'review OUT[build from OUT[plan it]] against OUT[plan it]',
                "ship OUT[plan it]\nOUT[build from OUT[plan it]]\nOUT[review OUT[build from OUT[plan it]] against OUT[plan it]]",
            ],
            $this->prompts,
            'all three tasks ran, each seeing the one before; the next stage sees all three outputs',
        );

        $work = $result->stageResults[0];
        self::assertCount(3, $work->agents, 'every task\'s agent is carried, so tokens and cost add up');
        self::assertSame(20, $result->totalTokens, 'three tasks plus the next stage, 5 tokens each');

        $results = $result->context[WorkflowEngine::RESULTS_CONTEXT_KEY];
        self::assertSame('OUT[plan it]', $results['plan'], 'a named task is recorded by its name');
        self::assertArrayHasKey('work_2', $results, 'an unnamed task falls back to <stage>_<n>');
        self::assertArrayHasKey('work_3', $results);
    }

    public function testTheFirstTaskThatFailsStopsTheStage(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $registry->register(
            (new WorkflowBuilder())
                ->name('stops')
                ->stage('work', [
                    Tasks::agent('coder')->prompt('first'),
                    Tasks::agent('coder')->prompt('second breaks'),
                    Tasks::agent('coder')->prompt('third'),
                ])
                ->stage('never', Tasks::agent('coder')->prompt('not reached'))
                ->build(),
        );
        $this->statusFor = ['second' => AgentStatus::TimedOut];

        $result = $this->engine($registry)->run('stops');

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame(['first', 'second breaks'], $this->prompts, 'the third task and the next stage never start');
        self::assertSame('agent gave up', $result->firstFailure()?->error);
        self::assertCount(2, $result->stageResults[0]->agents);
    }

    public function testEveryTasksDeclarationIsCheckedBeforeTheFirstRuns(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $registry->register(
            (new WorkflowBuilder())
                ->name('refused')
                ->stage('work', [
                    Tasks::agent('reviewer')->prompt('look')->tools(['Read']),
                    Tasks::agent('coder')->prompt('shell out')->tools(['Bash']),
                ])
                ->build(),
        );

        $result = (new WorkflowEngine(
            $registry,
            new AgentWorkerPool(1, $this->executor()),
            permissionGate: new PermissionGate(PermissionMode::DontAsk),
        ))->run('refused');

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame([], $this->prompts, 'the allowed first task must not run on the way to a refused second');
        self::assertStringContainsString('Bash', (string) $result->firstFailure()?->error);
    }

    public function testALaterStagesSecondTaskIsRefusedBeforeTheFirstStageRuns(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $registry->register(
            (new WorkflowBuilder())
                ->name('late-refusal')
                ->stage('first', Tasks::agent('reviewer')->prompt('harmless')->tools(['Read']))
                ->stage('second', [
                    Tasks::agent('reviewer')->prompt('look')->tools(['Read']),
                    Tasks::agent('coder')->prompt('shell out')->tools(['Bash']),
                ])
                ->build(),
        );

        $result = (new WorkflowEngine(
            $registry,
            new AgentWorkerPool(1, $this->executor()),
            permissionGate: new PermissionGate(PermissionMode::DontAsk),
        ))->run('late-refusal');

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame([], $this->prompts, 'no stage may run on the way to a refused task in a later stage');
        $failure = $result->firstFailure();
        self::assertSame('second', $failure?->stageName);
        self::assertStringContainsString("Stage 'second' task #1", (string) $failure?->error);
        self::assertStringContainsString('Bash', (string) $failure?->error);
    }

    public function testAOneTaskStageIsUnchanged(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $registry->register(
            (new WorkflowBuilder())
                ->name('single')
                ->stage('only', Tasks::agent('coder')->prompt('just this'))
                ->build(),
        );

        $result = $this->engine($registry)->run('single');

        self::assertSame(WorkflowStatus::Completed, $result->status);
        self::assertSame('OUT[just this]', $result->stageResults[0]->output);
        self::assertSame(['only' => 'OUT[just this]'], $result->context[WorkflowEngine::RESULTS_CONTEXT_KEY]);
    }

    public function testTheBuilderRefusesAnEmptyOrMistypedTaskList(): void
    {
        try {
            (new WorkflowBuilder())->stage('empty', []);
            self::fail('an empty task list must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Stage 'empty' needs at least one task", $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Stage 'mixed' task #1 must be a TaskBuilder, got string");
        (new WorkflowBuilder())->stage('mixed', [Tasks::agent('coder'), 'nope']);
    }

    // -------------------------------------------------------------------------
    // C8: `from:` dynamic waves
    // -------------------------------------------------------------------------

    public function testAFanOutStageExpandsAtEntryAndNamesEachItemByItsPath(): void
    {
        $envRoot = $this->tempDir . '/fanenv';
        mkdir($envRoot . '/src', 0755, true);
        file_put_contents($envRoot . '/a.txt', 'one');
        file_put_contents($envRoot . '/b.txt', 'two');
        file_put_contents($envRoot . '/src/c.txt', 'deep');

        // The glob is assembled from chr(42) on purpose: a bare wildcard
        // string literal here would be harvested into the GlobDialect corpus.
        // Stars keep PathGlob's fnmatch answer, which crosses "/" for a lone
        // `*`: all three files fan out, each named by its root-relative path
        // in sorted order — the nested match proves the walk is a real descent.
        $star = chr(42);
        $this->yaml('fanout', "name: fanout\n"
            . "stages:\n"
            . "  - name: sweep\n"
            . "    parallel: true\n"
            . "    from:\n"
            . "      glob: '" . $star . ".txt'\n"
            . "      chunk: 2\n"
            . "    agent: coder\n"
            . "    prompt: \"fix {{path}}\"\n");

        $result = (new WorkflowEngine(
            $this->registry(),
            new AgentWorkerPool(1, $this->executor()),
            environmentRoot: $envRoot,
        ))->run('fanout');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(
            ['fix a.txt', 'fix b.txt', 'fix src/c.txt'],
            $this->prompts,
            'one task per match in sorted order — a lone star crosses separators (fnmatch lineage), so the nested file joins the sweep',
        );

        $sweep = $result->stageResults[0];
        self::assertCount(3, $sweep->agents, 'all three items are carried, so tokens and cost add up');
        self::assertSame("OUT[fix a.txt]\nOUT[fix b.txt]\nOUT[fix src/c.txt]", $sweep->output);

        $results = $result->context[WorkflowEngine::RESULTS_CONTEXT_KEY];
        self::assertSame('OUT[fix a.txt]', $results['a.txt'], 'each item is recorded under its relative path');
        self::assertSame('OUT[fix b.txt]', $results['b.txt']);
        self::assertSame('OUT[fix src/c.txt]', $results['src/c.txt']);
    }

    public function testAStaticParallelStageLeavesPathBracesAsWritten(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $registry->register(
            (new WorkflowBuilder())
                ->name('staticpair')
                ->parallel('pair', [Tasks::agent('coder')->prompt('keep {{path}} intact')])
                ->build(),
        );

        $result = $this->engine($registry)->run('staticpair');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(['keep {{path}} intact'], $this->prompts, 'no path is invented for a static stage');
    }

    public function testTheFanOutChunkIsACeilingNeverAFloor(): void
    {
        // The fake pool cannot report the width a stage asked for, so the cap
        // formula itself is pinned: chunk meets the workflow width through
        // min(), never max() — a fan-out may narrow concurrency, not widen it.
        $source = (string) file_get_contents((new \ReflectionClass(WorkflowEngine::class))->getFileName());
        self::assertSame(1, substr_count($source, 'min($workflow->maxConcurrent, $chunk)'));
        self::assertSame(0, substr_count($source, 'max($workflow->maxConcurrent, $chunk)'));
        // A recorded chunk wins on replay, not the re-read YAML: the width
        // of a materialised wave was decided when it expanded. (A record
        // written with a null chunk keeps no ceiling to win with — that leg
        // falls back to the current YAML, and a ceiling can only narrow.)
        self::assertSame(1, substr_count($source, "\$chunk = \$recorded['chunk'] ?? \$from['chunk'];"));
    }

    // -------------------------------------------------------------------------
    // YAML: `tasks:`
    // -------------------------------------------------------------------------

    public function testAYamlStageListingTasksRunsThemInSequence(): void
    {
        $this->yaml('seq', <<<'YAML'
            name: seq
            stages:
              - name: fix
                tasks:
                  - name: lint
                    agent: reviewer
                    prompt: lint the tree
                    tools: [Read]
                  - prompt: "fix what {{prevResult}} found"
                    retries: 1
            YAML);

        $workflow = $this->registry()->load('seq');
        $tasks = $workflow->stages[0]['tasks'];
        self::assertSame('stage', $workflow->stages[0]['type']);
        self::assertCount(2, $tasks);
        self::assertSame('lint', $tasks[0]->name);
        self::assertSame('reviewer', $tasks[0]->agentType);
        self::assertSame(['Read'], $tasks[0]->tools);
        self::assertSame('coder', $tasks[1]->agentType, 'agent defaults to coder, as on a one-task stage');
        self::assertSame(1, $tasks[1]->retries);

        $result = $this->engine($this->registry())->run('seq');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(['lint the tree', 'fix what OUT[lint the tree] found'], $this->prompts);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function malformedTaskLists(): iterable
    {
        yield 'empty list' => ["tasks: []", '"tasks" must list at least one task'];
        yield 'a map' => ["tasks: {a: {prompt: x}}", '"tasks" must be a list, got a map'];
        yield 'a scalar' => ["tasks: go", '"tasks" must be a list, got string'];
        yield 'an entry that is not a map' => ["tasks: [just-a-string]", 'task #0 must be a map, got string'];
        yield 'a mistyped prompt' => ["tasks:\n      - prompt: [1]", 'task #0 "prompt" must be a string'];
        yield 'a stage-level prompt beside tasks' => ["prompt: shared\n    tasks:\n      - prompt: x", '"prompt" belongs on each task, not on the stage'];
        yield 'tasks on a parallel stage' => ["parallel: true\n    tasks:\n      - prompt: x", 'is parallel, so it lists "agents", not "tasks"'];
    }

    /**
     * @dataProvider malformedTaskLists
     */
    public function testAMalformedTaskListIsRefusedNotGuessed(string $stageBody, string $expected): void
    {
        $this->yaml('bad', "name: bad\nstages:\n  - name: s\n    {$stageBody}\n");

        try {
            $this->registry()->load('bad');
            self::fail('a malformed tasks: stage must not load');
        } catch (WorkflowLoadException $e) {
            self::assertStringContainsString("stage 's'", $e->getMessage());
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function yaml(string $name, string $body): void
    {
        file_put_contents($this->tempDir . '/workflows/' . $name . '.yaml', $body);
    }

    private function registry(): WorkflowRegistry
    {
        return new WorkflowRegistry($this->tempDir . '/workflows');
    }

    private function engine(WorkflowRegistry $registry): WorkflowEngine
    {
        return new WorkflowEngine($registry, new AgentWorkerPool(1, $this->executor()));
    }

    private function executor(): ExecutorInterface
    {
        return new class ($this) implements ExecutorInterface {
            public function __construct(private readonly MultiTaskStageTest $test)
            {
            }

            public function execute(SubAgent $subAgent, CompleteRequest $request): AgentResult
            {
                return $this->test->dispatch($subAgent);
            }

            public function executeStream(SubAgent $subAgent, CompleteRequest $request): \Generator
            {
                yield $this->execute($subAgent, $request);
            }

            public function cancel(string $agentId): void
            {
            }

            public function cancelAll(): void
            {
            }
        };
    }
}

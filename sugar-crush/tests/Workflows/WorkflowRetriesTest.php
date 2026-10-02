<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Workflows\StageResult;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\Workflow;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowLoadException;
use SugarCraft\Crush\Workflows\WorkflowRegistry;
use SugarCraft\Crush\Workflows\WorkflowStatus;
use SugarCraft\Crush\Workflows\WorkflowTask;

/**
 * Audit WF-1(b), engine and loader half: a workflow task's `retries` re-runs
 * a failed agent, the stage sees only the final attempt, and the run's totals
 * include every attempt's spend.
 *
 * Before the fix `retries()` reached `SubAgent::$maxRetries` and nothing read
 * it, so a stage whose agent failed once failed the whole run; YAML had no
 * spelling for the key at all.
 */
final class WorkflowRetriesTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/wf1b-engine-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/workflows', 0700, true);
        $this->useHomeSandbox($this->home);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeTree($this->home);
    }

    public function testASequentialStageWhoseAgentFailsThenSucceedsCompletesAndCountsEveryAttempt(): void
    {
        $executor = $this->failingThenPassingExecutor(failures: 2);
        $engine = new WorkflowEngine($this->retryRegistry(), new AgentWorkerPool(executor: $executor));

        $result = $engine->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('wf1b-stage')
            ->stage('flaky', Tasks::agent('coder')->prompt('p')->retries(2))
            ->build());

        self::assertSame(3, $executor->calls, 'the stage did not re-run its failed agent');
        self::assertSame(WorkflowStatus::Completed, $result->status, 'a stage whose last attempt succeeded must complete');
        self::assertSame(3, $result->stageResults[0]->agents[0]->attempts);
        self::assertSame(15, $result->totalTokens, 'the failed attempts\' tokens are missing from the run total');
    }

    public function testAStageRunThroughTheAgentManagerIsRetriedToo(): void
    {
        $executor = $this->failingThenPassingExecutor(failures: 1);
        $engine = new WorkflowEngine($this->retryRegistry(), new AgentWorkerPool(executor: $executor));
        $engine->setAgentManager(new AgentManager($this->createMock(ProviderInterface::class), new SkillRegistry()));

        $result = $engine->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('wf1b-managed')
            ->stage('flaky', Tasks::agent('coder')->prompt('p')->retries(1))
            ->build());

        self::assertSame(WorkflowStatus::Completed, $result->status);
        self::assertSame(2, $result->stageResults[0]->agents[0]->attempts);
        self::assertSame(10, $result->totalTokens);
    }

    public function testAParallelStagePoolKeepsTheEnginePoolsRetryFloor(): void
    {
        // The engine rebuilds a pool per parallel stage; a floor it did not
        // carry across would apply to sequential stages only.
        $executor = $this->failingThenPassingExecutor(failures: 1);
        $engine = new WorkflowEngine($this->retryRegistry(), (new AgentWorkerPool(executor: $executor))->withMaxRetries(1));

        $result = $engine->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('wf1b-parallel')
            ->parallel('fan', [Tasks::agent('coder')->name('only')->prompt('p')])
            ->build());

        self::assertSame(WorkflowStatus::Completed, $result->status, 'the parallel stage pool dropped the engine pool\'s retry floor');
        self::assertSame(2, $result->stageResults[0]->agents[0]->attempts);
    }

    public function testThePauseFileKeepsEachAgentsAttemptCount(): void
    {
        $engine = new WorkflowEngine($this->retryRegistry(), new AgentWorkerPool());
        $stage = new StageResult(
            stageName: 's',
            status: WorkflowStatus::Completed,
            agents: [new AgentResult(agentId: 'a', status: AgentStatus::Completed, attempts: 3)],
        );

        $serialize = new \ReflectionMethod(WorkflowEngine::class, 'serializeStageResult');
        $deserialize = new \ReflectionMethod(WorkflowEngine::class, 'deserializeStageResult');
        $row = json_decode((string) json_encode($serialize->invoke($engine, $stage)), true);
        $restored = $deserialize->invoke($engine, $row);

        self::assertSame(3, $restored->agents[0]->attempts);

        unset($row['agents'][0]['attempts']);
        self::assertSame(1, $deserialize->invoke($engine, $row)->agents[0]->attempts, 'a pause file from before retries means one attempt');
    }

    public function testYamlRetriesReachTheTaskOnAStageAndOnAParallelAgent(): void
    {
        $workflow = $this->loadYaml('retrying', <<<'YAML'
            name: retrying
            stages:
              - name: lint
                prompt: review
                retries: 2
              - name: fan
                parallel: true
                agents:
                  - name: a
                    prompt: one
                    retries: "1"
                  - name: b
                    prompt: two
            YAML);

        self::assertSame(2, $this->firstTask($workflow, 0)->retries);
        $fan = $workflow->stages[1]['tasks'];
        self::assertSame(1, $fan[0]->retries, 'a quoted integer means what the bare one does');
        self::assertNull($fan[1]->retries, 'an agent without the key keeps the engine default');
    }

    /**
     * @dataProvider malformedRetries
     */
    public function testMalformedYamlRetriesAreRefused(string $value, string $message): void
    {
        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage($message);

        $this->loadYaml('bad', "name: bad\nstages:\n  - name: s\n    prompt: p\n    retries: {$value}\n");
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function malformedRetries(): array
    {
        return [
            'negative' => ['-1', '"retries" must be at least 0, got -1'],
            'word' => ['lots', '"retries" must be a whole number, got string'],
            'null' => ['~', '"retries" must be a whole number, got null'],
            'float' => ['1.5', '"retries" must be a whole number, got float'],
        ];
    }

    public function testTheShippedExampleRetriesItsReviewStageOnly(): void
    {
        $example = \dirname(__DIR__, 2) . '/examples/workflows/lint-then-fix.yaml';
        copy($example, $this->home . '/workflows/lint-then-fix.yaml');

        $workflow = $this->retryRegistry()->load('lint-then-fix');

        self::assertSame(1, $this->firstTask($workflow, 0)->retries, 'the example no longer demonstrates retries on its read-only stage');
        foreach ($workflow->stages[1]['tasks'] as $fixer) {
            self::assertNull($fixer->retries, 'a fixer edits files and must not be retried by the example');
        }
    }

    private function firstTask(Workflow $workflow, int $stage): WorkflowTask
    {
        $task = $workflow->stages[$stage]['tasks'][0];
        self::assertInstanceOf(WorkflowTask::class, $task);

        return $task;
    }

    private function loadYaml(string $name, string $yaml): Workflow
    {
        file_put_contents($this->home . '/workflows/' . $name . '.yaml', $yaml);

        return $this->retryRegistry()->load($name);
    }

    private function retryRegistry(): WorkflowRegistry
    {
        return new WorkflowRegistry($this->home . '/workflows');
    }

    /**
     * Fails its first $failures calls, then succeeds; 5 tokens a call.
     */
    private function failingThenPassingExecutor(int $failures): ExecutorInterface
    {
        return new class ($failures) implements ExecutorInterface {
            public int $calls = 0;

            public function __construct(private readonly int $failures) {}

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                $this->calls++;
                $failed = $this->calls <= $this->failures;

                return new AgentResult(
                    agentId: $agent->id,
                    status: $failed ? AgentStatus::Failed : AgentStatus::Completed,
                    output: $failed ? null : 'fixed',
                    error: $failed ? new \RuntimeException('flaked') : null,
                    tokensUsed: 5,
                    startedAt: new \DateTimeImmutable(),
                    completedAt: new \DateTimeImmutable(),
                );
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield $this->execute($agent, $request);
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
}

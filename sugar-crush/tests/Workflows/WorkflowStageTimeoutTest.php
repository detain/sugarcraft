<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\Workflow;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowRegistry;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * Audit WF-1 (a), engine half: `config.timeout` ({@see Workflow::$timeout})
 * is a per-stage wall-clock budget, and a task without its own `timeout()`
 * falls back to it rather than to an engine literal nobody enforced.
 *
 * Before the fix the engine wrote `$task->timeout ?? 300` onto every
 * SubAgent, read `Workflow::$timeout` nowhere, and the pool never looked at
 * either — so a workflow declaring `timeout: 1` waited out a worker that
 * slept ten seconds and reported the stage Completed.
 */
final class WorkflowStageTimeoutTest extends TestCase
{
    private const STAGE_WORKER_SLEEP_SECONDS = 10;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('the forking dispatch path needs ext-pcntl and ext-posix');
        }
    }

    public function testASequentialStagePastItsWorkflowTimeoutFailsTimedOut(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('wf1-stage')
            ->timeout(1)
            ->stage('slow', Tasks::agent('coder')->prompt('never finishes'))
            ->build();

        [$result, $elapsed] = $this->runTimed($this->forkingEngine(), $workflow);

        self::assertLessThan(3.0, $elapsed, 'the stage ran past its 1 s config.timeout');
        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame(WorkflowStatus::Failed, $result->stageResults[0]->status);
        self::assertSame(AgentStatus::TimedOut, $result->stageResults[0]->agents[0]->status);
    }

    public function testAParallelStageIsBoundedAsAWholeByItsWorkflowTimeout(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('wf1-parallel')
            ->timeout(1)
            ->parallel('fan', [
                Tasks::agent('coder')->name('a')->prompt('never finishes'),
                Tasks::agent('coder')->name('b')->prompt('never finishes'),
                Tasks::agent('coder')->name('c')->prompt('never finishes'),
            ])
            ->maxConcurrent(2)
            ->build();

        [$result, $elapsed] = $this->runTimed($this->forkingEngine(), $workflow);

        // Three agents on two slots: a per-agent bound alone would let the
        // third start at ~1 s and run another full second.
        self::assertLessThan(3.0, $elapsed, 'the parallel stage ran past its 1 s config.timeout');
        self::assertSame(WorkflowStatus::Failed, $result->stageResults[0]->status);
        self::assertCount(3, $result->stageResults[0]->agents);
        foreach ($result->stageResults[0]->agents as $agentResult) {
            self::assertSame(AgentStatus::TimedOut, $agentResult->status);
        }
    }

    public function testAPipelineSharesOneBudgetAcrossItsSteps(): void
    {
        // Step 1 completes inside the budget; step 2 gets only what is left.
        // Were each step handed the full 3 s afresh, the pipeline would run to
        // ~5 s. The 4 s line sits a full second from both answers: at a 2 s
        // budget the gap was 0.3 s, and a loaded CI runner's fork overhead
        // (measured 2.97 s for a shared budget) crossed it.
        $workflow = (new WorkflowBuilder())
            ->name('wf1-pipeline')
            ->timeout(3)
            ->pipeline('chain', [
                Tasks::agent('coder')->name('quick')->prompt('sleep:2'),
                Tasks::agent('coder')->name('slow')->prompt('never finishes'),
            ])
            ->build();

        [$result, $elapsed] = $this->runTimed($this->forkingEngine(), $workflow);

        self::assertLessThan(4.0, $elapsed, 'the pipeline\'s second step was given a fresh budget');
        $agents = $result->stageResults[0]->agents;
        self::assertCount(2, $agents);
        self::assertSame(AgentStatus::Completed, $agents[0]->status);
        self::assertSame(AgentStatus::TimedOut, $agents[1]->status);
        self::assertSame(WorkflowStatus::Failed, $result->status);
    }

    public function testATaskWithoutItsOwnTimeoutFallsBackToTheWorkflowTimeout(): void
    {
        $executor = $this->recordingExecutor();
        $engine = new WorkflowEngine(new WorkflowRegistry(), new AgentWorkerPool(executor: $executor));

        $engine->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('wf1-fallback')
            ->timeout(77)
            ->stage('inherits', Tasks::agent('coder')->prompt('p'))
            ->stage('declares', Tasks::agent('coder')->prompt('p')->timeout(5))
            ->withVerification('checked', Tasks::agent('coder')->prompt('p'), Tasks::agent('tester')->prompt('v'))
            ->build());

        // The verification stage's second task is bounded by what is LEFT of
        // that stage's 77 s (AgentWorkerPool rounds the remainder up), so on a
        // runner stalled for over a second between the two it is 76 — still
        // the workflow's budget, never an engine literal.
        $timeouts = $executor->timeouts;
        $last = array_pop($timeouts);
        self::assertSame(
            [77, 5, 77],
            $timeouts,
            'a task without timeout() must carry the workflow\'s per-stage budget, not an engine literal',
        );
        self::assertThat($last, self::logicalAnd(self::greaterThanOrEqual(70), self::lessThanOrEqual(77)), 'the verifier must carry what is left of the stage budget');
    }

    /** @return array{0: \SugarCraft\Crush\Workflows\WorkflowResult, 1: float} */
    private function runTimed(WorkflowEngine $engine, Workflow $workflow): array
    {
        $began = microtime(true);
        $result = $engine->runFromPhp(static fn (): Workflow => $workflow);

        return [$result, microtime(true) - $began];
    }

    /**
     * An engine whose stages fork, like `/workflow run`'s: the worker sleeps
     * {@see self::STAGE_WORKER_SLEEP_SECONDS} unless its prompt says `sleep:<n>`.
     */
    private function forkingEngine(): WorkflowEngine
    {
        $executor = new class (self::STAGE_WORKER_SLEEP_SECONDS) implements ExecutorInterface {
            public function __construct(private readonly int $defaultSleep) {}

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                throw new \LogicException('the forking path calls executeStream()');
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                $sleep = preg_match('/^sleep:(\d+)$/', $agent->task, $m) === 1 ? (int) $m[1] : $this->defaultSleep;
                sleep($sleep);

                yield new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Completed,
                    output: 'slept ' . $sleep,
                    startedAt: new \DateTimeImmutable(),
                    completedAt: new \DateTimeImmutable(),
                );
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };

        return new WorkflowEngine(new WorkflowRegistry(), new AgentWorkerPool(forkedExecutor: $executor));
    }

    private function recordingExecutor(): ExecutorInterface
    {
        return new class implements ExecutorInterface {
            /** @var list<int> */
            public array $timeouts = [];

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                $this->timeouts[] = $agent->timeout;

                return new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Completed,
                    output: 'ok',
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
}

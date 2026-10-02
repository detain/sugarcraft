<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\Isolation;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Providers\CompleteRequest;

/**
 * The residual of audit WF-1: the pool's run budget
 * ({@see AgentWorkerPool::withTimeBudget()}) could not cut short an agent
 * running INLINE — an injected executor, a build without pcntl, a failed
 * fork. The budget decided whether such an agent started, and once it had,
 * it ran to its own (usually much longer) timeout.
 *
 * The pool cannot interrupt an inline run, so it now hands the executor the
 * budget's remainder as the agent's timeout when that is tighter, and every
 * shipped executor enforces its agent's timeout itself. The fixture here is
 * an executor that does the same: it "works" for its agent's timeout and
 * then reports TimedOut, capped so a regression costs seconds, not minutes.
 */
final class AgentWorkerPoolInlineBudgetTest extends TestCase
{
    /** How long the honouring executor works at most, whatever timeout it is given. */
    private const HONOURING_EXECUTOR_CAP_SECONDS = 4;

    public function testAnInlineAgentIsStoppedAtTheRunBudgetNotAtItsOwnTimeout(): void
    {
        $executor = $this->timeoutHonouringExecutor();
        $pool = (new AgentWorkerPool(executor: $executor))->withTimeBudget(1.0);

        $began = microtime(true);
        $results = iterator_to_array($pool->executeAll([$this->budgetAgent('inline', timeout: 300)], $this->budgetRequest()), false);
        $elapsed = microtime(true) - $began;

        self::assertLessThan(2.5, $elapsed, 'the inline agent ran past the stage\'s 1 s budget');
        self::assertSame([1], $executor->timeouts, 'the executor was not handed the budget as the agent\'s timeout');
        self::assertSame(AgentStatus::TimedOut, $results[0]->status);
        self::assertSame(
            'AgentWorkerPool: agent inline ran past the 1 s time budget it was dispatched under and was stopped.',
            $results[0]->error?->getMessage(),
        );
        self::assertSame(3, $results[0]->tokensUsed, 'the stopped run\'s spend was dropped with its message');
    }

    public function testExecuteOnesDirectPathIsBoundedByTheBudgetToo(): void
    {
        $executor = $this->timeoutHonouringExecutor();

        $began = microtime(true);
        $result = (new AgentWorkerPool(executor: $executor))
            ->withTimeBudget(1.0)
            ->executeOne($this->budgetAgent('direct', timeout: 300), $this->budgetRequest());
        $elapsed = microtime(true) - $began;

        self::assertLessThan(2.5, $elapsed, 'executeOne()\'s inline run ran past its 1 s budget');
        self::assertSame(AgentStatus::TimedOut, $result->status);
        self::assertStringContainsString('time budget', (string) $result->error?->getMessage());
    }

    public function testAnAgentsOwnTighterTimeoutIsLeftAlone(): void
    {
        $executor = $this->timeoutHonouringExecutor(workSeconds: 0);
        $agent = $this->budgetAgent('tight', timeout: 2);

        $results = iterator_to_array(
            (new AgentWorkerPool(executor: $executor))->withTimeBudget(60.0)->executeAll([$agent], $this->budgetRequest()),
            false,
        );

        self::assertSame([2], $executor->timeouts);
        self::assertSame([$agent], $executor->agents, 'an agent whose own bound is the tighter one must reach the executor itself');
        self::assertSame(AgentStatus::Completed, $results[0]->status);
    }

    public function testWithoutABudgetTheAgentReachesTheExecutorUnchanged(): void
    {
        $executor = $this->timeoutHonouringExecutor(workSeconds: 0);
        $agent = $this->budgetAgent('unbounded', timeout: 300);

        iterator_to_array((new AgentWorkerPool(executor: $executor))->executeAll([$agent], $this->budgetRequest()), false);

        self::assertSame([$agent], $executor->agents);
    }

    public function testTheBoundedCopyCarriesEveryOtherConstructorArgument(): void
    {
        $executor = $this->timeoutHonouringExecutor(workSeconds: 0);
        $agent = new SubAgent(
            id: 'carried',
            agent: $this->budgetAgent('x', timeout: 1)->agent,
            task: 'the task',
            createdAt: new \DateTimeImmutable('2020-01-02 03:04:05'),
            timeout: 300,
            maxRetries: 4,
            isolation: Isolation::Worktree,
            teamId: 'team',
            teammateId: 'mate',
        );

        iterator_to_array((new AgentWorkerPool(executor: $executor))->withTimeBudget(30.0)->executeAll([$agent], $this->budgetRequest()), false);

        self::assertCount(1, $executor->agents);
        $copy = $executor->agents[0];
        self::assertNotSame($agent, $copy);
        // Every promoted constructor property, so a parameter SubAgent gains
        // later and the copy forgets is caught here rather than in production.
        foreach ((new \ReflectionMethod(SubAgent::class, '__construct'))->getParameters() as $parameter) {
            $name = $parameter->getName();
            self::assertSame(
                $name === 'timeout' ? 30 : $agent->{$name},
                $copy->{$name},
                "the budget-bounded copy changed or dropped SubAgent::\${$name}",
            );
        }
    }

    /**
     * Works for its agent's timeout (at most {@see HONOURING_EXECUTOR_CAP_SECONDS},
     * or $workSeconds when given), then reports TimedOut — or Completed when
     * the work fits inside the timeout. Bills 3 tokens either way.
     */
    private function timeoutHonouringExecutor(?int $workSeconds = null): ExecutorInterface
    {
        return new class ($workSeconds, self::HONOURING_EXECUTOR_CAP_SECONDS) implements ExecutorInterface {
            /** @var list<int> */
            public array $timeouts = [];

            /** @var list<SubAgent> */
            public array $agents = [];

            public function __construct(
                private readonly ?int $workSeconds,
                private readonly int $cap,
            ) {}

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                $this->timeouts[] = $agent->timeout;
                $this->agents[] = $agent;
                $work = $this->workSeconds ?? $this->cap;
                $timedOut = $work >= $agent->timeout;
                $spent = min($work, $agent->timeout, $this->cap);
                if ($spent > 0) {
                    sleep($spent);
                }

                return new AgentResult(
                    agentId: $agent->id,
                    status: $timedOut ? AgentStatus::TimedOut : AgentStatus::Completed,
                    output: $timedOut ? null : 'done',
                    error: $timedOut ? new \RuntimeException(sprintf('ran past its %d s timeout', $agent->timeout)) : null,
                    tokensUsed: 3,
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

    private function budgetAgent(string $id, int $timeout): SubAgent
    {
        return new SubAgent(
            id: $id,
            agent: new Agent(
                name: 'budgeted',
                description: 'works',
                prompt: '',
                model: 'm',
                provider: 'echo',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'work',
            timeout: $timeout,
        );
    }

    private function budgetRequest(): CompleteRequest
    {
        return new CompleteRequest(model: 'm', messages: []);
    }
}

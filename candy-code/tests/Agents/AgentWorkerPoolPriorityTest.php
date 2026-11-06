<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\ExecutorInterface;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Providers\CompleteRequest;

/**
 * Priority lanes under a width cap: when seats are contended, a freed one
 * goes to the highest-priority waiter, and equal priorities keep submission
 * order. The refuse-vs-queue door is untouched — priority only ever reorders
 * work that was going to wait anyway, and without a cap it is inert.
 *
 * Every test drives the pool through a synchronous fake executor, so nothing
 * here waits on wall-clock (E646-clean).
 */
final class AgentWorkerPoolPriorityTest extends TestCase
{
    public function testAFreedSeatGoesToTheHighestPriorityWaiter(): void
    {
        $executor = new PrioritySeatExecutor();
        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $executor);
        $executor->pool = $pool;

        $results = \iterator_to_array($pool->executeAll([
            $this->priorityQueuedRun('alpha', 0),
            $this->priorityQueuedRun('bravo', 10),
            $this->priorityQueuedRun('charlie', 5),
        ], new CompleteRequest(model: 'm', messages: [])), false);

        self::assertSame(
            ['bravo', 'charlie', 'alpha'],
            \array_column($executor->seen, 'id'),
            'the contended drain runs highest-priority first',
        );
        self::assertSame(
            [['queue' => 2, 'active' => 1], ['queue' => 1, 'active' => 1], ['queue' => 0, 'active' => 1]],
            \array_map(static fn (array $row): array => ['queue' => $row['queue'], 'active' => $row['active']], $executor->seen),
        );
        self::assertCount(3, $results);
        foreach ($results as $result) {
            self::assertSame(AgentStatus::Completed, $result->status);
        }
    }

    public function testEqualPrioritiesKeepSubmissionOrder(): void
    {
        $executor = new PrioritySeatExecutor();
        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $executor);
        $executor->pool = $pool;

        $results = \iterator_to_array($pool->executeAll([
            $this->priorityQueuedRun('alpha', 5),
            $this->priorityQueuedRun('bravo', 5),
            $this->priorityQueuedRun('charlie', 5),
        ], new CompleteRequest(model: 'm', messages: [])), false);

        self::assertSame(['alpha', 'bravo', 'charlie'], \array_column($executor->seen, 'id'));
        self::assertCount(3, $results);
        foreach ($results as $result) {
            self::assertSame(AgentStatus::Completed, $result->status);
        }
    }

    public function testPriorityIsInertWithoutACap(): void
    {
        $executor = new PrioritySeatExecutor();
        $pool = new AgentWorkerPool(executor: $executor);
        $executor->pool = $pool;

        $results = \iterator_to_array($pool->executeAll([
            $this->priorityQueuedRun('alpha', 0),
            $this->priorityQueuedRun('bravo', 10),
            $this->priorityQueuedRun('charlie', 5),
        ], new CompleteRequest(model: 'm', messages: [])), false);

        // No cap means every submission dispatches immediately in submission
        // order — mixed priorities change nothing, and nothing ever queues.
        self::assertSame(
            ['alpha', 'bravo', 'charlie'],
            \array_column($executor->seen, 'id'),
        );
        self::assertSame(
            [['queue' => 2, 'active' => 1], ['queue' => 1, 'active' => 2], ['queue' => 0, 'active' => 3]],
            \array_map(static fn (array $row): array => ['queue' => $row['queue'], 'active' => $row['active']], $executor->seen),
        );
        self::assertCount(3, $results);
        foreach ($results as $result) {
            self::assertSame(AgentStatus::Completed, $result->status);
        }
    }

    public function testPriorityNeverResurrectsWorkThatTheHaltAlreadyRefused(): void
    {
        $executor = new PrioritySeatExecutor(['alpha' => AgentStatus::Failed]);
        $pool = (new AgentWorkerPool(maxConcurrent: 1, executor: $executor))->withStopOnFirstFailure(true);
        $executor->pool = $pool;

        $results = \iterator_to_array($pool->executeAll([
            $this->priorityQueuedRun('alpha', 10),
            $this->priorityQueuedRun('bravo', 5),
            $this->priorityQueuedRun('charlie', 0),
        ], new CompleteRequest(model: 'm', messages: [])), false);

        self::assertSame(['alpha'], \array_column($executor->seen, 'id'), 'the halt drains the queue; priority never runs refused work');
        // The pool's documented contract is one yielded result per agent it
        // DISPATCHED: the two queued waiters are cancelled before ever taking a
        // seat, so they never reach the generator at all (halt semantics,
        // priority-agnostic — this is exactly what the pre-priority pool did).
        self::assertCount(1, $results);
        self::assertSame(AgentStatus::Failed, $results[0]->status);
    }

    public function testAnAllZeroBatchMatchesThePlainFifoDrain(): void
    {
        $executor = new PrioritySeatExecutor();
        $pool = new AgentWorkerPool(maxConcurrent: 2, executor: $executor);
        $executor->pool = $pool;

        $results = \iterator_to_array($pool->executeAll([
            $this->priorityQueuedRun('alpha', 0),
            $this->priorityQueuedRun('bravo', 0),
            $this->priorityQueuedRun('charlie', 0),
        ], new CompleteRequest(model: 'm', messages: [])), false);

        // The default-priority batch is the pre-priority shape exactly: the
        // contended drain still hands out seats in submission order.
        self::assertSame(['alpha', 'bravo', 'charlie'], \array_column($executor->seen, 'id'));
        self::assertSame(
            [['queue' => 2, 'active' => 1], ['queue' => 1, 'active' => 2], ['queue' => 0, 'active' => 2]],
            \array_map(static fn (array $row): array => ['queue' => $row['queue'], 'active' => $row['active']], $executor->seen),
        );
        self::assertCount(3, $results);
        foreach ($results as $result) {
            self::assertSame(AgentStatus::Completed, $result->status);
        }
    }

    private function priorityQueuedRun(string $id, int $priority): SubAgent
    {
        $agent = new SubAgent(
            id: $id,
            agent: new Agent(
                name: 'queued',
                description: 'takes a slot',
                prompt: '',
                model: 'm',
                provider: 'echo',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'prio-' . $id,
        );
        $agent->priority = $priority;

        return $agent;
    }
}

/**
 * Synchronous seat-taking double: records every dispatch and the pool's
 * queue/active snapshots at that moment, and can fail named agents by id so
 * the halt door is exercised without any real process or timer (E646-clean).
 */
final class PrioritySeatExecutor implements ExecutorInterface
{
    /** @var list<array{id:string,queue:?int,active:?int}> */
    public array $seen = [];
    public ?AgentWorkerPool $pool = null;

    /**
     * @param array<string,AgentStatus> $statusById
     */
    public function __construct(private array $statusById = [])
    {
    }

    public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
    {
        $this->seen[] = [
            'id' => $agent->id,
            'queue' => $this->pool?->queueSize(),
            'active' => $this->pool?->activeCount(),
        ];

        return new AgentResult(
            agentId: $agent->id,
            status: $this->statusById[$agent->id] ?? AgentStatus::Completed,
            output: 'ok',
        );
    }

    /**
     * @return \Generator<int,AgentResult>
     */
    public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
    {
        yield $this->execute($agent, $request);
    }

    public function cancel(string $agentId): void
    {
    }

    public function cancelAll(): void
    {
    }
}

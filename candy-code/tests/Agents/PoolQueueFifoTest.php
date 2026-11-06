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
 * WF-A6 (RS-AGENT A6): pin the queue semantics of the bounded-width pool.
 *
 * The dispatch order of queued agent launches is a property fleet drivers
 * depend on (ARCHITECTURE.md: a parallel Task batch forks its members "in
 * provider order as running ones exit"), yet until this file nothing asserted
 * it: AgentWorkerPoolTest::testExecuteAllRespectsMaxConcurrent only counts how
 * many started before the first yield, and its assertion is a one-sided
 * assertLessThanOrEqual — an executor that dispatched the batch in reverse
 * order, or one that started every agent regardless of the cap, kept it green.
 *
 * These pins name the two regimes the code actually ships and keeps them
 * distinct:
 *  - a width cap queues: the agents past the cap wait on the FIFO queue and
 *    are dispatched head-first in submission order as active slots drain;
 *  - the uncapped default (maxConcurrent null — the settings-era default,
 *    reached by leaving the setting unset or writing 0) never queues at all:
 *    the whole batch is dispatched inside the initial fill burst.
 *
 * No wall-clock timing is involved (E646): the injected executor runs inline
 * and synchronously, so every observation below is a recorded snapshot of the
 * pool's own queueSize()/activeCount() at each execute() call.
 */
final class PoolQueueFifoTest extends TestCase
{
    /**
     * With a width of 2 and three queued launches, dispatch must be FIFO by
     * submission, the queue must decay exactly as the head is popped, and the
     * third agent must wait for a slot instead of being refused or dropped —
     * it is queued, and every agent still yields one result.
     */
    public function testAWidthCapDispatchesQueuedAgentsFifoAndStillDeliversEveryResult(): void
    {
        $pool = null;
        $seen = [];
        $executor = $this->recordingExecutor($seen, $pool);

        $pool = new AgentWorkerPool(maxConcurrent: 2, executor: $executor);

        $results = iterator_to_array($pool->executeAll(
            [$this->fifoQueuedRun('alpha'), $this->fifoQueuedRun('bravo'), $this->fifoQueuedRun('charlie')],
            new CompleteRequest(model: 'm', messages: []),
        ), false);

        self::assertSame(
            ['alpha', 'bravo', 'charlie'],
            array_column($seen, 'id'),
            'a width-capped batch must dispatch head-first in submission order — a LIFO queue would '
                . 'reorder long fleet batches while every coarse count still passed',
        );
        self::assertSame(
            [['queue' => 2, 'active' => 1], ['queue' => 1, 'active' => 2], ['queue' => 0, 'active' => 2]],
            array_map(static fn (array $row): array => ['queue' => $row['queue'], 'active' => $row['active']], $seen),
            'charlie waits on the queue while both slots are held, then takes the slot alpha drained — '
                . 'its snapshot may never show a third active run',
        );
        self::assertCount(3, $results, 'queueing must never drop an agent — the cap delays, it does not refuse');
        self::assertSame(
            ['alpha', 'bravo', 'charlie'],
            array_map(static fn (AgentResult $result): string => $result->agentId, $results),
            'results are yielded in the same FIFO order they were dispatched',
        );
        self::assertSame(
            [AgentStatus::Completed, AgentStatus::Completed, AgentStatus::Completed],
            array_map(static fn (AgentResult $result): AgentStatus => $result->status, $results),
        );
    }

    /**
     * The polarity twin: with the shipped uncapped default the queue never
     * holds anything — every agent is dispatched inside the initial fill
     * burst, before the first result is extracted (charlie is seen with three
     * active runs, where under a cap it waited for one to drain).
     */
    public function testTheUncappedDefaultDispatchesTheWholeBatchWithoutEverQueueing(): void
    {
        $pool = null;
        $seen = [];
        $executor = $this->recordingExecutor($seen, $pool);

        $pool = new AgentWorkerPool(executor: $executor);

        $results = iterator_to_array($pool->executeAll(
            [$this->fifoQueuedRun('alpha'), $this->fifoQueuedRun('bravo'), $this->fifoQueuedRun('charlie')],
            new CompleteRequest(model: 'm', messages: []),
        ), false);

        self::assertSame(
            ['alpha', 'bravo', 'charlie'],
            array_column($seen, 'id'),
            'the uncapped burst dispatches in submission order too — FIFO is about the queue, '
                . 'and an uncapped batch must not take a detour through it',
        );
        self::assertSame(
            [['queue' => 2, 'active' => 1], ['queue' => 1, 'active' => 2], ['queue' => 0, 'active' => 3]],
            array_map(static fn (array $row): array => ['queue' => $row['queue'], 'active' => $row['active']], $seen),
            'a null cap fills every slot in the first burst: at charlie\'s dispatch alpha and bravo are '
                . 'still held active (nothing has drained), which no width cap can reproduce',
        );
        self::assertCount(3, $results);
        self::assertSame(
            ['alpha', 'bravo', 'charlie'],
            array_map(static fn (AgentResult $result): string => $result->agentId, $results),
        );
    }

    /**
     * An executor double that records, per execute() call, the pool's own
     * queueSize()/activeCount() snapshot. The pool variable is bound by
     * reference because the executor must be built before the pool that
     * takes it.
     *
     * @param list<array{id:string,queue:int,active:int}> $seen
     */
    private function recordingExecutor(array &$seen, ?AgentWorkerPool &$pool): ExecutorInterface
    {
        $executor = $this->createMock(ExecutorInterface::class);
        $executor->method('execute')
            ->willReturnCallback(function (SubAgent $agent) use (&$seen, &$pool): AgentResult {
                $seen[] = ['id' => $agent->id, 'queue' => $pool->queueSize(), 'active' => $pool->activeCount()];

                return new AgentResult(agentId: $agent->id, status: AgentStatus::Completed, output: 'ok');
            });
        $executor->method('executeStream')
            ->willReturnCallback(function (SubAgent $agent): \Generator {
                yield new AgentResult(agentId: $agent->id, status: AgentStatus::Completed);
            });
        $executor->method('cancel')->willReturnCallback(static function (string $agentId): void {
        });
        $executor->method('cancelAll')->willReturnCallback(static function (): void {
        });

        return $executor;
    }

    private function fifoQueuedRun(string $id): SubAgent
    {
        return new SubAgent(
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
            task: 'queue-' . $id,
        );
    }
}

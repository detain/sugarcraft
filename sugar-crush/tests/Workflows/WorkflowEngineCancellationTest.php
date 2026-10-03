<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowRegistry;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * Chat's double-Escape reaches a live workflow run through the
 * {@see CancellationToken} `WorkflowEngine::run()`/`resume()` take: a cancel
 * calls `AgentWorkerPool::cancelAll()` on the stage's live pool at once (the
 * run's fiber is suspended in that pool and cannot look at a flag), and the
 * stage loop then stops with a Cancelled result.
 *
 * The executor here runs inline and plays the user: while stage `a` is
 * running it cancels the token, as Esc Esc does while the fiber is suspended
 * mid-stage. Its own `cancelAll()` is the pool's last hop, so counting calls
 * to it measures whether the cancel reached the live pool.
 */
final class WorkflowEngineCancellationTest extends TestCase
{
    private string $dir;

    private WorkflowRegistry $registry;

    /** @var list<string> */
    public array $dispatched = [];

    public int $cancelAlls = 0;

    public ?CancellationToken $cancelDuring = null;

    public ?string $cancelOnTask = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-wf-cancel-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/workflows', 0700, true);
        $this->registry = new WorkflowRegistry($this->dir . '/workflows');
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('three')
                ->description('a then b then c')
                ->stage('a', Tasks::agent('coder')->prompt('do a'))
                ->stage('b', Tasks::agent('coder')->prompt('do b'))
                ->stage('c', Tasks::agent('coder')->prompt('do c'))
                ->build(),
        );
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    public function testACancelMidStageStopsTheStagesAgentsAndEveryLaterStage(): void
    {
        $token = new CancellationToken();
        $this->cancelDuring = $token;
        $this->cancelOnTask = 'do a';

        $result = $this->engine()->run('three', [], $token);

        self::assertSame(1, $this->cancelAlls, "the cancel reached the live stage pool's cancelAll()");
        self::assertSame(['do a'], $this->dispatched, 'b and c never started');
        self::assertSame(WorkflowStatus::Cancelled, $result->status);
        self::assertCount(1, $result->stageResults);
        self::assertSame(WorkflowStatus::Cancelled, $result->stageResults[0]->status, 'the interrupted stage is not reported as its work');
        self::assertSame(WorkflowEngine::CANCELLED_STAGE_ERROR, $result->stageResults[0]->error);
    }

    /** Esc Esc before the fiber's first tick: nothing is dispatched at all. */
    public function testATokenCancelledBeforeTheRunStartsDispatchesNothing(): void
    {
        $token = new CancellationToken();
        $token->cancel();

        $result = $this->engine()->run('three', [], $token);

        self::assertSame([], $this->dispatched);
        self::assertSame(WorkflowStatus::Cancelled, $result->status);
        self::assertSame([], $result->stageResults);
    }

    /** A cancel after the stage's last agent settled still stops the run there. */
    public function testACancelBetweenStagesStopsBeforeTheNextOne(): void
    {
        $token = new CancellationToken();
        $this->cancelDuring = $token;
        $this->cancelOnTask = 'do b';

        $result = $this->engine()->run('three', [], $token);

        self::assertSame(['do a', 'do b'], $this->dispatched);
        self::assertSame(WorkflowStatus::Cancelled, $result->status);
        self::assertSame(WorkflowStatus::Completed, $result->stageResults[0]->status, 'a finished before the cancel');
        self::assertSame(WorkflowStatus::Cancelled, $result->stageResults[1]->status);
    }

    public function testAnUncancelledTokenChangesNothing(): void
    {
        $result = $this->engine()->run('three', [], new CancellationToken());

        self::assertSame(['do a', 'do b', 'do c'], $this->dispatched);
        self::assertSame(WorkflowStatus::Completed, $result->status);
        self::assertSame(0, $this->cancelAlls);
    }

    /**
     * Chat holds the token until the turn settles, which is after the run
     * returned. A cancel then must not reach back into pools of a finished run.
     */
    public function testTheEngineLetsGoOfTheTokenWhenTheRunReturns(): void
    {
        $token = new CancellationToken();
        $this->engine()->run('three', [], $token);

        $token->cancel();

        self::assertSame(0, $this->cancelAlls);
    }

    /** The same token is honoured by resume(), the other way a run goes live. */
    public function testResumeHonoursTheTokenToo(): void
    {
        $engine = $this->engine();
        $this->cancelDuring = new CancellationToken();
        $this->cancelOnTask = 'do a';
        $first = $engine->run('three', [], $this->cancelDuring);
        $engine->pause($first->workflowId);
        $this->dispatched = [];

        $token = new CancellationToken();
        $this->cancelDuring = $token;
        $result = $engine->resume($first->workflowId, $token);

        self::assertSame(['do a'], $this->dispatched, 'the resumed run re-ran a and stopped there');
        self::assertSame(WorkflowStatus::Cancelled, $result->status);
    }

    private function engine(): WorkflowEngine
    {
        $test = $this;
        $executor = new class ($test) implements ExecutorInterface {
            public function __construct(private readonly WorkflowEngineCancellationTest $test)
            {
            }

            public function execute(SubAgent $subAgent, CompleteRequest $request): AgentResult
            {
                $this->test->dispatched[] = $subAgent->task;
                if ($subAgent->task === $this->test->cancelOnTask) {
                    $this->test->cancelDuring?->cancel();
                }

                return new AgentResult(
                    agentId: $subAgent->id,
                    status: AgentStatus::Completed,
                    output: 'OUT[' . $subAgent->task . ']',
                    tokensUsed: 1,
                    costUsd: 0.0,
                    startedAt: new \DateTimeImmutable(),
                    completedAt: new \DateTimeImmutable(),
                );
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
                $this->test->cancelAlls++;
            }
        };

        return new WorkflowEngine($this->registry, new AgentWorkerPool(1, $executor));
    }
}

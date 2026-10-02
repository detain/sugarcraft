<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Providers\CompleteRequest;

/**
 * Audit WF-1 (a), pool half: a forked agent is bounded by its own
 * {@see SubAgent::$timeout} and by the pool's run budget
 * ({@see AgentWorkerPool::withTimeBudget()}).
 *
 * Before the fix `SubAgent::$timeout` was written by every workflow dispatch
 * site and read by nothing: {@see AgentWorkerPool::waitForCompletion()}
 * waited on WNOHANG reaps with no deadline, so an agent blocked on a command
 * that never exits held the stage forever. The fixtures here are forked
 * workers that sleep {@see self::WORKER_SLEEP_SECONDS} and start a
 * `setsid sleep` grandchild — the shape of an agent stuck in a Bash command,
 * which runs in its own session — so the tests also prove the kill takes the
 * whole tree, not just the forked PHP child.
 */
final class AgentWorkerPoolTimeoutTest extends TestCase
{
    /**
     * Long enough that a run which waits it out is unmistakable against the
     * sub-3-second bound the tests assert, short enough that a regression
     * costs ten seconds rather than the suite's per-test alarm.
     */
    private const WORKER_SLEEP_SECONDS = 10;

    private string $markerDir;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('the forking dispatch path needs ext-pcntl and ext-posix');
        }

        $this->markerDir = sys_get_temp_dir() . '/wf1-pool-' . bin2hex(random_bytes(6));
        mkdir($this->markerDir, 0700);
    }

    protected function tearDown(): void
    {
        // Belt and braces: a regression must not leave sleepers behind.
        foreach (glob($this->markerDir . '/*') ?: [] as $marker) {
            foreach (explode(' ', trim((string) file_get_contents($marker))) as $pid) {
                if ((int) $pid > 0) {
                    @posix_kill((int) $pid, 9);
                }
            }
            @unlink($marker);
        }
        @rmdir($this->markerDir);
    }

    public function testAForkedAgentPastItsTimeoutIsKilledWithItsProcessTreeAndSettlesTimedOut(): void
    {
        $pool = new AgentWorkerPool(forkedExecutor: $this->sleepingExecutor());
        $agent = $this->subAgent('slow', timeout: 1);

        $began = microtime(true);
        $results = iterator_to_array($pool->executeAll([$agent], $this->request()), false);
        $elapsed = microtime(true) - $began;

        self::assertCount(1, $results);
        self::assertSame(AgentStatus::TimedOut, $results[0]->status, 'the overrunning agent did not settle TimedOut');
        self::assertTrue($results[0]->isFailure());
        self::assertStringContainsString('ran past its 1 s timeout', (string) $results[0]->error?->getMessage());
        self::assertLessThan(3.0, $elapsed, 'the pool waited the worker out instead of enforcing its 1 s timeout');

        [$child, $grandchild] = $this->pidsFor('slow');
        self::assertTrue($this->goneWithin($child, 2.0), 'the forked worker survived its timeout');
        self::assertTrue($this->goneWithin($grandchild, 2.0), 'the command the worker started (its own session, like Bash) survived the timeout');
    }

    public function testTheRunBudgetBoundsQueueTimeAndAQueuedAgentPastItNeverStarts(): void
    {
        // One slot, two agents with no per-agent bound: only the run budget
        // can stop this, and the second agent can only meet it in the queue.
        $pool = (new AgentWorkerPool(maxConcurrent: 1, forkedExecutor: $this->sleepingExecutor()))
            ->withTimeBudget(1.0);
        $first = $this->subAgent('first', timeout: 0);
        $second = $this->subAgent('second', timeout: 0);

        $began = microtime(true);
        $results = [];
        foreach ($pool->executeAll([$first, $second], $this->request()) as $result) {
            $results[$result->agentId] = $result;
        }
        $elapsed = microtime(true) - $began;

        self::assertLessThan(3.0, $elapsed, 'the run budget did not bound the batch');
        self::assertSame(['first', 'second'], array_keys($results), 'every dispatched agent must still yield exactly one result');
        self::assertSame(AgentStatus::TimedOut, $results['first']->status);
        self::assertStringContainsString('time budget it was dispatched under', (string) $results['first']->error?->getMessage());
        self::assertSame(AgentStatus::TimedOut, $results['second']->status);
        self::assertStringContainsString('was spent before agent second could start', (string) $results['second']->error?->getMessage());
        self::assertNull($results['second']->startedAt, 'an agent that never ran must not claim a start time');
        self::assertFileDoesNotExist($this->markerDir . '/second', 'the queued agent was started after the budget had run out');
    }

    public function testAnAgentThatFinishesInsideItsTimeoutKeepsItsResult(): void
    {
        $pool = new AgentWorkerPool(forkedExecutor: $this->sleepingExecutor(sleepSeconds: 0));

        $results = iterator_to_array($pool->executeAll([$this->subAgent('quick', timeout: 5)], $this->request()), false);

        self::assertSame(AgentStatus::Completed, $results[0]->status);
        self::assertSame('done quick', $results[0]->output);
    }

    public function testANonPositiveTimeoutPlacesNoPerAgentBound(): void
    {
        $pool = new AgentWorkerPool(forkedExecutor: $this->sleepingExecutor(sleepSeconds: 1, spawnGrandchild: false));

        $results = iterator_to_array($pool->executeAll([$this->subAgent('unbounded', timeout: 0)], $this->request()), false);

        self::assertSame(AgentStatus::Completed, $results[0]->status, 'timeout 0 must mean "no per-agent bound", not "already expired"');
    }

    public function testExecuteOneOnASpentBudgetRefusesWithoutRunningTheExecutor(): void
    {
        $executor = new class implements ExecutorInterface {
            public int $calls = 0;

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                $this->calls++;

                return new AgentResult(agentId: $agent->id, status: AgentStatus::Completed, output: 'ran');
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield $this->execute($agent, $request);
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };

        $result = (new AgentWorkerPool(executor: $executor))
            ->withTimeBudget(0.0)
            ->executeOne($this->subAgent('late', timeout: 30), $this->request());

        self::assertSame(AgentStatus::TimedOut, $result->status);
        self::assertSame(0, $executor->calls, 'a stage with no time left still ran its next agent');
    }

    public function testWithTimeBudgetLeavesTheOriginalPoolUnbounded(): void
    {
        $pool = new AgentWorkerPool(forkedExecutor: $this->sleepingExecutor(sleepSeconds: 0));
        $pool->withTimeBudget(0.0);

        $results = iterator_to_array($pool->executeAll([$this->subAgent('shared', timeout: 5)], $this->request()), false);

        self::assertSame(AgentStatus::Completed, $results[0]->status, 'withTimeBudget() mutated the pool it was called on');
    }

    /**
     * A worker that records its pid (and its grandchild's) under the marker
     * directory, optionally starts a `setsid sleep` grandchild, then sleeps.
     */
    private function sleepingExecutor(int $sleepSeconds = self::WORKER_SLEEP_SECONDS, bool $spawnGrandchild = true): ExecutorInterface
    {
        return new class ($this->markerDir, $sleepSeconds, $spawnGrandchild) implements ExecutorInterface {
            public function __construct(
                private readonly string $markerDir,
                private readonly int $sleepSeconds,
                private readonly bool $spawnGrandchild,
            ) {}

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                throw new \LogicException('the forking path calls executeStream()');
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                $pids = (string) getmypid();
                $process = null;
                if ($this->spawnGrandchild && $this->sleepSeconds > 0) {
                    $process = proc_open(
                        ['setsid', 'sleep', (string) $this->sleepSeconds],
                        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
                        $pipes,
                    );
                    if (\is_resource($process)) {
                        $pids .= ' ' . proc_get_status($process)['pid'];
                    }
                }
                file_put_contents($this->markerDir . '/' . $agent->id, $pids);

                if ($this->sleepSeconds > 0) {
                    sleep($this->sleepSeconds);
                }
                if (\is_resource($process)) {
                    proc_close($process);
                }

                yield new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Completed,
                    output: 'done ' . $agent->id,
                    startedAt: new \DateTimeImmutable(),
                    completedAt: new \DateTimeImmutable(),
                );
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };
    }

    private function subAgent(string $id, int $timeout): SubAgent
    {
        return new SubAgent(
            id: $id,
            agent: new Agent(
                name: 'sleeper',
                description: 'sleeps',
                prompt: '',
                model: 'm',
                provider: 'echo',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'sleep',
            timeout: $timeout,
        );
    }

    private function request(): CompleteRequest
    {
        return new CompleteRequest(model: 'm', messages: []);
    }

    /** @return array{0: int, 1: int} the forked worker's pid and its grandchild's */
    private function pidsFor(string $agentId): array
    {
        $marker = $this->markerDir . '/' . $agentId;
        self::assertFileExists($marker, 'the worker never started, so the test proves nothing');
        $pids = array_map('intval', explode(' ', trim((string) file_get_contents($marker))));
        self::assertCount(2, $pids, 'the worker did not record a grandchild');

        return [$pids[0], $pids[1]];
    }

    /** Dead means no /proc entry, or a zombie nobody has collected yet. */
    private function goneWithin(int $pid, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            $stat = @file_get_contents("/proc/{$pid}/stat");
            if ($stat === false) {
                return true;
            }
            $afterComm = substr($stat, (int) strrpos($stat, ')') + 2, 1);
            if ($afterComm === 'Z' || $afterComm === 'X') {
                return true;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        return false;
    }
}

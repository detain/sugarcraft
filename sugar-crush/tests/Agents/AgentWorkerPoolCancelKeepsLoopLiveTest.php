<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Support\ProcessTree;

/**
 * Audit R3 residual: {@see AgentWorkerPool}'s cancel path ran
 * `ProcessContainment::killTree($pid, 0.5)` on whatever thread called it — on
 * the TUI that is the event loop's, because the workflow Fiber runs the pool
 * in the TUI process and a cancel arrives from a loop callback. The freeze
 * walk held the loop for ~100 ms, and a member that ignores SIGTERM held it
 * for the whole 0.5 s grace on top.
 *
 * The run here is driven exactly the way the TUI drives it: a Fiber resumed
 * from a periodic loop timer (`Chat::driveWorkflowFiber()`), with the cancel
 * issued from another loop callback while the run is suspended.
 *
 * WHAT IS OBSERVED, AND WHY IT DISCRIMINATES — the same instrument as
 * EngineBackendCancelKeepsLoopLiveTest. A heartbeat timer looks at the forked
 * worker on every tick. Before the fix the worker went from running to
 * SIGSTOPped to dead inside ONE callback, so no other callback could ever see
 * it stopped. After it, the root is stopped at once and the walk takes loop
 * ticks, so the heartbeat must catch it held ('T'). The grace case adds the
 * longest gap between two heartbeats, which a 0.5 s usleep() ladder cannot
 * hide. The end state (worker and command dead, agent settled, nothing left
 * unreaped) is asserted every time, so a live loop never costs the kill. The
 * deadline kill (expireWorker()) runs in the same loop-driven run and is held
 * to the same instrument.
 *
 * The last test pins the other half of the brief: a pool no loop is driving
 * keeps the synchronous kill, done by the time cancel() returns.
 */
final class AgentWorkerPoolCancelKeepsLoopLiveTest extends TestCase
{
    private const COMMAND_SLEEP_SECONDS = 30;

    private string $dir = '';

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('the forking dispatch path needs ext-pcntl and ext-posix');
        }
        if (!ProcessTree::available() || !\function_exists('posix_getpgrp')) {
            self::markTestSkipped('the tree walk is Linux /proc only; elsewhere the kill is the root alone');
        }

        $this->dir = \sys_get_temp_dir() . '/pool_live_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        // Only pids this test's own workers recorded are signalled.
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            foreach (\explode(' ', \trim((string) \file_get_contents($file))) as $pid) {
                if ((int) $pid > 0) {
                    @\posix_kill((int) $pid, 9);
                }
            }
            @\unlink($file);
        }
        @\rmdir($this->dir);

        parent::tearDown();
    }

    public function testCancelOnTheLoopKillsTheWorkersTreeWithoutFreezingIt(): void
    {
        $pool = new AgentWorkerPool(forkedExecutor: $this->workerStartingACommand(trapsTerm: false));
        $run = $this->runOnTheLoop(
            $pool,
            ['doomed'],
            static function (AgentWorkerPool $pool): void {
                $pool->cancel('doomed');
            },
        );

        [$worker, $command] = $this->recordedPids('doomed');
        self::assertTrue($run['terminated'], 'executeAll() never settled the cancelled agent');
        self::assertCount(1, $run['results'], 'a cancelled agent still yields exactly one result');
        self::assertSame(AgentStatus::Stopped, $run['results'][0]->status);
        self::assertGreaterThan(
            0,
            $run['heldTicks'],
            'no loop tick ran between the worker being stopped and the run settling: the cancel froze the loop',
        );
        self::assertTrue(self::goneWithin($worker, 2.0), 'the forked worker survived cancel()');
        self::assertTrue(self::goneWithin($command, 2.0), 'the command the worker started survived cancel()');
        $this->assertNothingLeftToReap($pool, $worker);
    }

    /**
     * The grace half: a command that ignores SIGTERM held the old cancel on
     * the loop's thread for the full {@see AgentWorkerPool}::TERMINATE_GRACE_SECONDS.
     * The grace is still honoured — the 9 goes out only after it — but on a
     * timer, so the loop keeps turning through it.
     */
    public function testTheSigtermGraceRunsOnTheLoopAndStillEndsInAKill(): void
    {
        $pool = new AgentWorkerPool(forkedExecutor: $this->workerStartingACommand(trapsTerm: true));
        $run = $this->runOnTheLoop(
            $pool,
            ['stubborn'],
            static function (AgentWorkerPool $pool): void {
                $pool->cancel('stubborn');
            },
            watchCommandOf: 'stubborn',
        );

        self::assertTrue($run['terminated'], 'executeAll() never settled the cancelled agent');
        self::assertSame(AgentStatus::Stopped, $run['results'][0]->status ?? null);
        self::assertLessThan(
            0.25,
            $run['maxGap'],
            \sprintf('the loop stood still for %.3f s during the cancel: the SIGTERM grace ran on its thread', $run['maxGap']),
        );
        self::assertNotNull($run['commandGoneAfter'], 'the SIGTERM-ignoring command was never killed');
        self::assertGreaterThanOrEqual(
            0.4,
            $run['commandGoneAfter'],
            'the SIGTERM-ignoring command died before the grace was spent: the cancel skipped straight to SIGKILL',
        );
    }

    public function testCancelAllOnTheLoopKillsAndReapsEveryWorkerWithoutFreezingIt(): void
    {
        $pool = new AgentWorkerPool(maxConcurrent: 2, forkedExecutor: $this->workerStartingACommand(trapsTerm: false));
        $run = $this->runOnTheLoop(
            $pool,
            ['one', 'two'],
            static function (AgentWorkerPool $pool): void {
                $pool->cancelAll();
            },
        );

        self::assertTrue($run['terminated'], 'executeAll() kept running after cancelAll()');
        self::assertGreaterThan(
            0,
            $run['heldTicks'],
            'no loop tick ran between the workers being stopped and their reap: cancelAll() froze the loop',
        );
        foreach (['one', 'two'] as $id) {
            [$worker, $command] = $this->recordedPids($id);
            self::assertTrue(self::goneWithin($command, 2.0), "the command agent {$id} started survived cancelAll()");
            $this->assertNothingLeftToReap($pool, $worker);
        }
    }

    /**
     * The deadline path (audit WF-1's expireWorker()) runs inside the same
     * loop-driven run, so it kills over loop ticks too: the agent settles
     * TimedOut at once and its tree is walked, killed and reaped afterwards.
     */
    public function testAnAgentsDeadlineOnTheLoopKillsItsTreeWithoutFreezingIt(): void
    {
        $pool = new AgentWorkerPool(forkedExecutor: $this->workerStartingACommand(trapsTerm: false));
        $run = $this->runOnTheLoop(
            $pool,
            ['overrun'],
            static function (AgentWorkerPool $pool): void {
                // No cancel: the agent's own 1 s timeout is the kill.
            },
            timeout: 1,
        );

        [$worker, $command] = $this->recordedPids('overrun');
        self::assertTrue($run['terminated'], 'executeAll() never settled the overrunning agent');
        self::assertSame(AgentStatus::TimedOut, $run['results'][0]->status ?? null);
        self::assertGreaterThan(
            0,
            $run['heldTicks'],
            'no loop tick ran between the worker being stopped and its reap: the deadline kill froze the loop',
        );
        self::assertTrue(self::goneWithin($command, 2.0), 'the command the overrunning agent started survived its deadline');
        $this->assertNothingLeftToReap($pool, $worker);
    }

    /**
     * Callers that are not on a loop keep the synchronous kill: driven by a
     * plain resume loop (no event loop ever turns), the worker is already
     * dead when cancel() returns, and nothing waits on a timer.
     */
    public function testAPoolNoLoopDrivesKeepsTheSynchronousKill(): void
    {
        $pool = new AgentWorkerPool(forkedExecutor: $this->workerStartingACommand(trapsTerm: false));
        $results = [];
        $request = self::request();
        $agents = [self::sleepingSubAgent('offloop')];
        $fiber = new \Fiber(static function () use ($pool, $agents, $request, &$results): void {
            foreach ($pool->executeAll($agents, $request) as $result) {
                $results[] = $result;
            }
        });
        $fiber->start();

        $deadline = \microtime(true) + 5.0;
        while (!$this->recorded('offloop') && !$fiber->isTerminated() && \microtime(true) < $deadline) {
            \usleep(10_000);
            $fiber->resume();
        }
        [$worker, $command] = $this->recordedPids('offloop');

        $pool->cancel('offloop');

        $state = ProcessTree::stat($worker)['state'] ?? 'X';
        self::assertContains($state, ['Z', 'X'], "off the loop cancel() returned with the worker still in state {$state}");

        while (!$fiber->isTerminated() && \microtime(true) < $deadline) {
            $fiber->resume();
        }
        self::assertTrue($fiber->isTerminated());
        self::assertSame(AgentStatus::Stopped, $results[0]->status ?? null);
        self::assertTrue(self::goneWithin($command, 2.0), 'the command the worker started survived cancel()');
    }

    /**
     * Run $ids through $pool inside a Fiber resumed from a loop timer, call
     * $cancel from a loop callback once every worker has recorded its pids,
     * and watch the loop until the run settles and the kill is done.
     *
     * @param list<string> $ids
     * @param \Closure(AgentWorkerPool): void $cancel
     * @return array{terminated: bool, results: list<AgentResult>, heldTicks: int, maxGap: float, commandGoneAfter: ?float}
     */
    private function runOnTheLoop(AgentWorkerPool $pool, array $ids, \Closure $cancel, ?string $watchCommandOf = null, int $timeout = 0): array
    {
        $loop = Loop::get();
        $results = [];
        $request = self::request();
        $agents = \array_map(static fn(string $id): SubAgent => self::sleepingSubAgent($id, $timeout), $ids);
        $fiber = new \Fiber(static function () use ($pool, $agents, $request, &$results): void {
            foreach ($pool->executeAll($agents, $request) as $result) {
                $results[] = $result;
            }
        });

        // The TUI's driver: one resume per tick, never inline.
        $driver = $loop->addPeriodicTimer(0.005, static function () use ($fiber): void {
            if (!$fiber->isStarted()) {
                $fiber->start();
            } elseif (!$fiber->isTerminated()) {
                $fiber->resume();
            }
        });

        $workers = [];
        $command = null;
        $cancelledAt = null;
        $lastBeat = null;
        $maxGap = 0.0;
        $heldTicks = 0;
        $commandGoneAfter = null;
        $heartbeat = $loop->addPeriodicTimer(0.002, function () use (
            $pool, $ids, $cancel, $fiber, $loop, $watchCommandOf,
            &$workers, &$command, &$cancelledAt, &$lastBeat, &$maxGap, &$heldTicks, &$commandGoneAfter,
        ): void {
            $now = \microtime(true);
            if ($cancelledAt === null) {
                foreach ($ids as $id) {
                    if (!$this->recorded($id)) {
                        return;
                    }
                }
                foreach ($ids as $id) {
                    $workers[] = $this->recordedPids($id)[0];
                }
                if ($watchCommandOf !== null) {
                    $command = $this->recordedPids($watchCommandOf)[1];
                }
                // Both stamps BEFORE the call: whatever cancel() spends on this
                // callback is a gap the next heartbeat has to account for.
                $cancelledAt = \microtime(true);
                $lastBeat = $cancelledAt;
                $cancel($pool);

                return;
            }

            $maxGap = \max($maxGap, $now - $lastBeat);
            $lastBeat = $now;

            $settled = $fiber->isTerminated()
                && self::poolState($pool, 'killsInFlight') === []
                && self::poolState($pool, 'unreapedChildren') === [];
            if (!$settled) {
                foreach ($workers as $worker) {
                    if ((ProcessTree::stat($worker)['state'] ?? null) === 'T') {
                        $heldTicks++;
                    }
                }
            }
            if ($command !== null && $commandGoneAfter === null) {
                $state = ProcessTree::stat($command)['state'] ?? 'X';
                if ($state === 'Z' || $state === 'X') {
                    $commandGoneAfter = $now - $cancelledAt;
                }
            }
            if ($settled && ($command === null || $commandGoneAfter !== null)) {
                $loop->stop();
            }
        });
        $guard = $loop->addTimer(15.0, static fn() => $loop->stop());
        $loop->run();
        $loop->cancelTimer($guard);
        $loop->cancelTimer($heartbeat);
        $loop->cancelTimer($driver);

        self::assertNotNull($cancelledAt, 'fixture: the workers never started, so the cancel proves nothing');

        return [
            'terminated' => $fiber->isTerminated(),
            'results' => $results,
            'heldTicks' => $heldTicks,
            'maxGap' => $maxGap,
            'commandGoneAfter' => $commandGoneAfter,
        ];
    }

    private function assertNothingLeftToReap(AgentWorkerPool $pool, int $worker): void
    {
        self::assertSame([], self::poolState($pool, 'killsInFlight'));
        self::assertSame([], self::poolState($pool, 'activePids'));
        self::assertSame([], self::poolState($pool, 'unreapedChildren'));
        $status = 0;
        self::assertSame(-1, \pcntl_waitpid($worker, $status, \WNOHANG), "worker {$worker} was never reaped");
    }

    /**
     * A private list of the pool's bookkeeping, or [] where the property does
     * not exist: killsInFlight arrived with the fix, and reading it as []
     * makes the pre-fix code fail on the loop assertions instead of erroring.
     *
     * @return array<int|string, mixed>
     */
    private static function poolState(AgentWorkerPool $pool, string $property): array
    {
        if (!\property_exists($pool, $property)) {
            return [];
        }

        return (array) (new \ReflectionProperty(AgentWorkerPool::class, $property))->getValue($pool);
    }

    private static function goneWithin(int $pid, float $seconds): bool
    {
        $deadline = \microtime(true) + $seconds;
        do {
            $state = ProcessTree::stat($pid)['state'] ?? 'X';
            if ($state === 'Z' || $state === 'X') {
                return true;
            }
            \usleep(10_000);
        } while (\microtime(true) < $deadline);

        return false;
    }

    private function recorded(string $id): bool
    {
        return \is_file($this->dir . '/' . $id);
    }

    /** @return array{int, int} the forked worker's pid, then its command's */
    private function recordedPids(string $id): array
    {
        $pids = \array_map('intval', \explode(' ', \trim((string) @\file_get_contents($this->dir . '/' . $id))));

        return [$pids[0] ?? 0, $pids[1] ?? 0];
    }

    /**
     * A forked worker that starts a `setsid`'d command (an agent's Bash
     * command, in its own session), records both pids, then sleeps. With
     * $trapsTerm the command ignores SIGTERM (the disposition survives exec),
     * so only the post-grace SIGKILL ends it.
     */
    private function workerStartingACommand(bool $trapsTerm): ExecutorInterface
    {
        $detached = ProcessContainment::detachedSpawnBinary();
        if ($detached === '') {
            self::markTestSkipped('the command needs a setsid-detached spawn');
        }
        $sleep = (string) self::COMMAND_SLEEP_SECONDS;
        $argv = $trapsTerm
            ? [$detached, 'sh', '-c', 'trap "" TERM; exec sleep ' . $sleep]
            : [$detached, 'sleep', $sleep];

        return new class ($this->dir, $argv, self::COMMAND_SLEEP_SECONDS) implements ExecutorInterface {
            /** @param list<string> $argv */
            public function __construct(
                private readonly string $dir,
                private readonly array $argv,
                private readonly int $sleepSeconds,
            ) {}

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                throw new \LogicException('the forking path calls executeStream()');
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                $process = \proc_open(
                    $this->argv,
                    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                $pids = \getmypid() . (\is_resource($process) ? ' ' . \proc_get_status($process)['pid'] : '');
                \file_put_contents($this->dir . '/' . $agent->id . '.tmp', $pids);
                \rename($this->dir . '/' . $agent->id . '.tmp', $this->dir . '/' . $agent->id);

                \sleep($this->sleepSeconds);

                yield new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Completed,
                    output: 'slept through it',
                    startedAt: new \DateTimeImmutable(),
                    completedAt: new \DateTimeImmutable(),
                );
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };
    }

    private static function sleepingSubAgent(string $id, int $timeout = 0): SubAgent
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

    private static function request(): CompleteRequest
    {
        return new CompleteRequest(model: 'm', messages: []);
    }
}

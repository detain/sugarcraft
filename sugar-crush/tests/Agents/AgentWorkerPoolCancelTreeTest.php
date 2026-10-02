<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\ProcessExecutor;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Support\ProcessTree;

/**
 * Audit F-E2-rem / WF-1-rem: the pool's CANCEL path kills a forked worker the
 * way its deadline path does — the worker and every process it started — and
 * its synchronous fallback honours the agent's own timeout.
 *
 * Before the fix `terminateWorker()` sent one SIGTERM to the forked PHP child.
 * The work is never there: an engine-run stage's Bash commands run `setsid`'d
 * in their own session, so cancelling a stage left them running for nobody.
 * The fixture worker starts a `setsid sleep` grandchild — that shape — and the
 * tests assert it dies with the cancel.
 *
 * The pool is driven inside a Fiber, the way the TUI's workflow Fiber drives
 * it, so the test can call cancel() while executeAll() is genuinely mid-run:
 * every poll that finds nothing finished suspends back here.
 */
final class AgentWorkerPoolCancelTreeTest extends TestCase
{
    /** A worker that is still asleep when the test ends was never killed. */
    private const CANCEL_FIXTURE_SLEEP_SECONDS = 10;

    private string $markerDir;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('the forking dispatch path needs ext-pcntl and ext-posix');
        }

        $this->markerDir = sys_get_temp_dir() . '/fe2-pool-' . bin2hex(random_bytes(6));
        mkdir($this->markerDir, 0700);
    }

    protected function tearDown(): void
    {
        // A regression must not leave sleepers behind: only pids this test's
        // own workers recorded are signalled.
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

    public function testCancelKillsTheWorkerAndTheCommandItStartedAndSettlesStopped(): void
    {
        $this->requireTreeKill();

        $pool = new AgentWorkerPool(forkedExecutor: $this->sleepingExecutor());
        $results = [];
        $fiber = $this->drive($pool, [$this->sleeperAgent('doomed')], $results);

        $this->resumeUntil($fiber, fn (): bool => $this->recorded('doomed'));
        [$child, $grandchild] = $this->pidsFor('doomed');

        $began = microtime(true);
        $pool->cancel('doomed');
        $this->resumeUntil($fiber, static fn (): bool => $fiber->isTerminated());

        self::assertTrue($fiber->isTerminated(), 'executeAll() never settled the cancelled agent');
        self::assertLessThan(3.0, microtime(true) - $began, 'the run waited the worker out instead of killing it');
        self::assertCount(1, $results, 'a cancelled agent still yields exactly one result');
        self::assertSame(AgentStatus::Stopped, $results[0]->status);
        self::assertStringContainsString('was cancelled', (string) $results[0]->error?->getMessage());

        self::assertTrue($this->goneWithin($child, 2.0), 'the forked worker survived cancel()');
        self::assertTrue(
            $this->goneWithin($grandchild, 2.0),
            'the command the worker started (its own session, like Bash) survived cancel() — only the root was signalled',
        );
    }

    public function testCancelAllKillsEveryWorkersTree(): void
    {
        $this->requireTreeKill();

        $pool = new AgentWorkerPool(maxConcurrent: 2, forkedExecutor: $this->sleepingExecutor());
        $results = [];
        $fiber = $this->drive($pool, [$this->sleeperAgent('one'), $this->sleeperAgent('two')], $results);

        $this->resumeUntil($fiber, fn (): bool => $this->recorded('one') && $this->recorded('two'));
        $pids = [...$this->pidsFor('one'), ...$this->pidsFor('two')];

        $pool->cancelAll();
        $this->resumeUntil($fiber, static fn (): bool => $fiber->isTerminated());

        self::assertTrue($fiber->isTerminated(), 'executeAll() kept running after cancelAll()');
        foreach ($pids as $pid) {
            self::assertTrue($this->goneWithin($pid, 2.0), "pid {$pid} (a worker or the command it started) survived cancelAll()");
        }
    }

    /**
     * WF-1-rem's synchronous half, through the pool: where it cannot fork it
     * runs the executor inline and cannot interrupt it, so the executor it
     * builds for itself must bound the agent by the agent's own timeout. That
     * executor used to carry a fixed 300 s of its own instead.
     */
    public function testTheSynchronousFallbackHonoursTheAgentsOwnTimeout(): void
    {
        $pool = new AgentWorkerPool(forkedExecutor: new ProcessExecutor(simulatedWorker: true));
        $force = new \ReflectionProperty(AgentWorkerPool::class, 'forcePcntlUnavailableForTesting');
        $force->setValue($pool, true);

        // The fallback warns once on stderr; keep it out of the test output.
        $previousLog = ini_set('error_log', $this->markerDir . '/fallback.log');
        try {
            $results = iterator_to_array($pool->executeAll([$this->sleeperAgent('inline', timeout: 1)], $this->request()), false);
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            @unlink($this->markerDir . '/fallback.log');
        }

        self::assertCount(1, $results);
        self::assertSame(AgentStatus::TimedOut, $results[0]->status, 'the inline run ignored the agent\'s 1 s timeout');
    }

    /**
     * @param list<SubAgent> $agents
     * @param list<AgentResult> $results filled as the run yields
     */
    private function drive(AgentWorkerPool $pool, array $agents, array &$results): \Fiber
    {
        $request = $this->request();
        $fiber = new \Fiber(static function () use ($pool, $agents, $request, &$results): void {
            foreach ($pool->executeAll($agents, $request) as $result) {
                $results[] = $result;
            }
        });
        $fiber->start();

        return $fiber;
    }

    /** Resume the run until $done answers true, for at most five seconds. */
    private function resumeUntil(\Fiber $fiber, \Closure $done): void
    {
        $deadline = microtime(true) + 5.0;
        while (!$done() && !$fiber->isTerminated() && microtime(true) < $deadline) {
            usleep(10_000);
            $fiber->resume();
        }
    }

    private function requireTreeKill(): void
    {
        if (!ProcessTree::available() || !\function_exists('posix_getpgrp')) {
            self::markTestSkipped('killTree()\'s tree walk is Linux /proc only; elsewhere it kills the root alone');
        }
    }

    /**
     * A forked worker that starts a `setsid sleep` grandchild (an agent's
     * Bash command, in its own session), records both pids, then sleeps.
     */
    private function sleepingExecutor(): ExecutorInterface
    {
        return new class ($this->markerDir, self::CANCEL_FIXTURE_SLEEP_SECONDS) implements ExecutorInterface {
            public function __construct(
                private readonly string $markerDir,
                private readonly int $sleepSeconds,
            ) {}

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                throw new \LogicException('the forking path calls executeStream()');
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                $process = proc_open(
                    ['setsid', 'sleep', (string) $this->sleepSeconds],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                $pids = getmypid() . (\is_resource($process) ? ' ' . proc_get_status($process)['pid'] : '');
                file_put_contents($this->markerDir . '/' . $agent->id . '.tmp', $pids);
                rename($this->markerDir . '/' . $agent->id . '.tmp', $this->markerDir . '/' . $agent->id);

                sleep($this->sleepSeconds);

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

    private function sleeperAgent(string $id, int $timeout = 0): SubAgent
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

    private function recorded(string $agentId): bool
    {
        return is_file($this->markerDir . '/' . $agentId);
    }

    /** @return array{0: int, 1: int} the forked worker's pid and its grandchild's */
    private function pidsFor(string $agentId): array
    {
        self::assertTrue($this->recorded($agentId), "worker {$agentId} never started, so the test proves nothing");
        $pids = array_map('intval', explode(' ', trim((string) file_get_contents($this->markerDir . '/' . $agentId))));
        self::assertCount(2, $pids, "worker {$agentId} did not record a grandchild");

        return [$pids[0], $pids[1]];
    }

    /** Dead means no /proc entry, or a zombie nobody has collected yet. */
    private function goneWithin(int $pid, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            $stat = ProcessTree::stat($pid);
            if ($stat === null || $stat['state'] === 'Z' || $stat['state'] === 'X') {
                return true;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        return false;
    }
}

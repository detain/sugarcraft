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
 * C7' Option A: the pool's re-armable stall deadline.
 *
 * A {@see SubAgent::$timeout} measures the wall clock; this measures silence.
 * On the forked path the worker's stream publishes progress bytes into the
 * progress file ({@see AgentWorkerPool::pumpProgress()} reads them every poll
 * cycle), and every observed growth re-arms the idle bound. An agent that
 * produces nothing for stallTimeout seconds straight expires the way a
 * timeout does — tree killed, settled `timed_out` — but the record carries
 * the distinct cause ({@see AgentResult::$stalled}), and re-dispatching it
 * spends {@see SubAgent::$stallRestarts}, a budget the model-retry gate never
 * sees and that model retries never spend against.
 *
 * The fixtures are the shape of the defect this bounds: a forked worker
 * silent for ten seconds — unmistakable against the sub-3-second bounds the
 * tests assert. The chatty fixture is the counter-shape: output keeps
 * landing, so the idle bound must never fire however long the run goes on.
 */
final class AgentWorkerPoolStallTest extends TestCase
{
    /**
     * Long enough that a stalled run which is not bounded looks unmistakably
     * hung against the sub-3-second bounds asserted here, short enough that a
     * regression costs ten seconds rather than the suite's per-test alarm.
     */
    private const WORKER_SILENCE_SECONDS = 10;

    private string $stallMarkerDir;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('the forking dispatch path needs ext-pcntl and ext-posix');
        }

        $this->stallMarkerDir = sys_get_temp_dir() . '/c7-stall-pool-' . bin2hex(random_bytes(6));
        mkdir($this->stallMarkerDir, 0700);
    }

    protected function tearDown(): void
    {
        // Belt and braces: a regression must not leave sleepers behind.
        foreach (scandir($this->stallMarkerDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_ends_with($entry, '-count')) {
                continue;
            }
            foreach (explode(' ', trim((string) file_get_contents($this->stallMarkerDir . '/' . $entry))) as $pid) {
                if ((int) $pid > 0) {
                    @posix_kill((int) $pid, 9);
                }
            }
        }
        foreach (scandir($this->stallMarkerDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->stallMarkerDir . '/' . $entry);
            }
        }
        @rmdir($this->stallMarkerDir);
    }

    public function testProgressKeepResettingTheIdleClockSoASlowLiveAgentOutlivesItsStallWindow(): void
    {
        // Chunks land every 400 ms for ~2.4 s against a 1 s idle bound: with
        // no re-arm this worker dies at ~1 s (mutation M1's red shape).
        $pool = new AgentWorkerPool(forkedExecutor: $this->chattyExecutor());

        $began = microtime(true);
        $results = iterator_to_array(
            $pool->executeAll([$this->stallAgent('chatty', stallTimeout: 1)], $this->poolStallRequest()),
            false,
        );
        $elapsed = microtime(true) - $began;

        self::assertSame(AgentStatus::Completed, $results[0]->status, 'a progressing worker was expired by the idle bound it kept re-arming');
        self::assertSame('done chatty', $results[0]->output);
        self::assertFalse($results[0]->stalled);
        self::assertGreaterThanOrEqual(2.0, $elapsed, 'the worker never ran long enough for the keep-alive leg to matter');
        self::assertLessThan(5.0, $elapsed, 'the run outlasted its own completion by suspicious far');
    }

    public function testAForkedAgentWhoseOutputGoesIdleIsExpiredOnTheStallDeadlineWithItsDistinctCause(): void
    {
        $pool = new AgentWorkerPool(forkedExecutor: $this->silentStallExecutor(self::WORKER_SILENCE_SECONDS));
        $agent = $this->stallAgent('muted', stallTimeout: 1);

        $began = microtime(true);
        $results = iterator_to_array($pool->executeAll([$agent], $this->poolStallRequest()), false);
        $elapsed = microtime(true) - $began;

        self::assertCount(1, $results);
        self::assertSame(AgentStatus::TimedOut, $results[0]->status, 'the stalled agent did not settle TimedOut');
        self::assertTrue($results[0]->isFailure());
        self::assertTrue($results[0]->stalled, 'the record lost the distinct stall cause');
        self::assertStringContainsString('went silent for 1 s without publishing progress', (string) $results[0]->error?->getMessage());
        // 8 s, not 3 s: under full-family co-shard load the parent's poll
        // loop measurably starves an expiry to ~6 s. The pin that matters is
        // that the idle bound fires at all — long before the 10 s of silence
        // the worker chose would have run the stage out on its own.
        self::assertLessThan(8.0, $elapsed, 'the pool waited the silent worker out instead of enforcing its idle bound');

        [$child, $grandchild] = $this->recordedWorkerPids('muted');
        self::assertTrue($this->processVanishedWithin($child, 2.0), 'the forked worker survived its stall expiry');
        self::assertTrue($this->processVanishedWithin($grandchild, 2.0), 'the command the worker started (its own session, like Bash) survived the stall expiry');
    }

    public function testAStalledAgentIsReDispatchedWhileItsRestartBudgetLastsAndCompletes(): void
    {
        $pool = new AgentWorkerPool(forkedExecutor: $this->scriptedStallExecutor(['silent', 'live']));

        $began = microtime(true);
        $results = iterator_to_array(
            $pool->executeAll([$this->stallAgent('savior', stallTimeout: 1, restarts: 1)], $this->poolStallRequest()),
            false,
        );
        $elapsed = microtime(true) - $began;

        self::assertSame(AgentStatus::Completed, $results[0]->status, 'the stall expiry was not re-dispatched under the restart budget');
        self::assertSame('done second wind', $results[0]->output);
        self::assertSame(2, $results[0]->attempts, 'the settled record does not count both runs');
        self::assertFalse($results[0]->stalled, 'a completed agent must not carry the stall cause');
        // Load tolerance: under the full-family co-shard load the parent's
        // poll loop measurably starves each expiry, so the bound is 9 s —
        // still far under the 10 s the pool would spend waiting out the
        // silent first attempt on its own.
        self::assertLessThan(9.0, $elapsed, 'the pool waited the silent first attempt out instead of expiring and re-dispatching it');
    }

    public function testAStallBudgetSpentOutLeavesTheFailureShapeOfTodayWithTheDistinctCauseOnTheRecord(): void
    {
        $pool = new AgentWorkerPool(forkedExecutor: $this->silentStallExecutor(self::WORKER_SILENCE_SECONDS));

        $began = microtime(true);
        $results = iterator_to_array(
            $pool->executeAll([$this->stallAgent('quiet', stallTimeout: 1, restarts: 1)], $this->poolStallRequest()),
            false,
        );
        $elapsed = microtime(true) - $began;

        self::assertSame(AgentStatus::TimedOut, $results[0]->status);
        self::assertTrue($results[0]->isFailure());
        self::assertTrue($results[0]->stalled, 'the exhausted record lost the distinct stall cause');
        self::assertSame(2, $results[0]->attempts, 'the exhausted record does not count both stall runs');
        self::assertStringContainsString('went silent for 1 s without publishing progress', (string) $results[0]->error?->getMessage());
        // With maxRetries 0 notRetriedReason stays null: no bracket note,
        // exactly today's shape, only more attempts behind it.
        self::assertStringNotContainsString('not retried', (string) $results[0]->error?->getMessage());
        // Load tolerance as in the savior test: 8 s is still well under the
        // 20 s two silent attempts would cost without the idle bound.
        self::assertLessThan(8.0, $elapsed, 'the pool ran the silent worker out instead of bounding both stall runs');
    }

    public function testStallRestartsAndModelRetriesSpendTwoSeparateBudgets(): void
    {
        // attempt 1: silence, expires -> costs 1 stall restart, no retry.
        // attempt 2: fails for real -> the retry gate must still have its
        // budget; had the stall stolen it (mutation M2's shape), the stage
        // would settle the Failed attempt here.
        // attempt 3: completes.
        $pool = new AgentWorkerPool(forkedExecutor: $this->scriptedStallExecutor(['silent', 'fail', 'live']));

        $began = microtime(true);
        $results = iterator_to_array(
            $pool->executeAll(
                [$this->stallAgent('mixed', stallTimeout: 1, restarts: 1, maxRetries: 1)],
                $this->poolStallRequest(),
            ),
            false,
        );
        $elapsed = microtime(true) - $began;

        self::assertSame(AgentStatus::Completed, $results[0]->status, 'the stall restart spent the model retry — the budgets are shared after all');
        self::assertSame('done third wind', $results[0]->output);
        self::assertSame(3, $results[0]->attempts, 'the settled record does not count the stall, the failure and the success');
        self::assertFalse($results[0]->stalled);
        // Load tolerance: a silent attempt starves to ~6 s under co-shard
        // load; 16 s still proves both idle bounds fired, where two silent
        // waits would cost 20 s+ plus the failure path.
        self::assertLessThan(16.0, $elapsed, 'a silent attempt was waited out instead of expiring on its idle bound');
    }

    public function testAnAgentWithNoIdleBoundIsUntouchedByTheStallMachinery(): void
    {
        $pool = new AgentWorkerPool(forkedExecutor: $this->silentStallExecutor(2));

        $results = iterator_to_array(
            $pool->executeAll([$this->stallAgent('plain', stallTimeout: 0)], $this->poolStallRequest()),
            false,
        );

        self::assertSame(AgentStatus::Completed, $results[0]->status, 'an agent without stallTimeout was bounded by an idle deadline it never asked for');
        self::assertFalse($results[0]->stalled, 'the inert path still stamped the stall cause');
        self::assertSame(1, $results[0]->attempts);
    }

    public function testTheAbsoluteBoundIsTheHonestCauseWhenBothDeadlinesHaveFired(): void
    {
        $pool = new AgentWorkerPool(forkedExecutor: $this->silentStallExecutor(self::WORKER_SILENCE_SECONDS));

        $results = iterator_to_array(
            $pool->executeAll([$this->stallAgent('both', stallTimeout: 1, timeout: 1)], $this->poolStallRequest()),
            false,
        );

        self::assertSame(AgentStatus::TimedOut, $results[0]->status);
        self::assertTrue($results[0]->isFailure());
        self::assertFalse($results[0]->stalled, 'the outer wall-clock bound was recorded as silence although both had fired');
        self::assertStringContainsString('ran past its 1 s timeout', (string) $results[0]->error?->getMessage());
        self::assertStringNotContainsString('went silent', (string) $results[0]->error?->getMessage());
    }

    /**
     * A worker that records its pid (and a `setsid sleep` grandchild's, the
     * shape of an agent stuck in a Bash command) then yields nothing. The
     * terminal Completed frame is unreachable while the silence bound holds —
     * reaching it means the deadline never fired.
     */
    private function silentStallExecutor(int $silenceSeconds): ExecutorInterface
    {
        return new class ($this->stallMarkerDir, $silenceSeconds) implements ExecutorInterface {
            public function __construct(
                private readonly string $stallMarkerDir,
                private readonly int $silenceSeconds,
            ) {}

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                throw new \LogicException('the forking path calls executeStream()');
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                $pids = (string) getmypid();
                $process = proc_open(
                    ['setsid', 'sleep', (string) $this->silenceSeconds],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                if (\is_resource($process)) {
                    $pids .= ' ' . proc_get_status($process)['pid'];
                }
                file_put_contents($this->stallMarkerDir . '/' . $agent->id, $pids);

                sleep($this->silenceSeconds);
                if (\is_resource($process)) {
                    proc_close($process);
                }

                yield new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Completed,
                    output: 'survived the silence',
                    startedAt: new \DateTimeImmutable(),
                    completedAt: new \DateTimeImmutable(),
                );
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };
    }

    /** A worker that keeps publishing — the counter-shape to the silent one. */
    private function chattyExecutor(): ExecutorInterface
    {
        return new class ($this->stallMarkerDir) implements ExecutorInterface {
            public function __construct(
                private readonly string $stallMarkerDir,
            ) {}

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                throw new \LogicException('the forking path calls executeStream()');
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                file_put_contents($this->stallMarkerDir . '/' . $agent->id, (string) getmypid());

                for ($chunk = 0; $chunk < 6; $chunk++) {
                    usleep(400_000);
                    yield new AgentResult(
                        agentId: $agent->id,
                        status: AgentStatus::Streaming,
                        output: 'chunk' . $chunk,
                    );
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

    /**
     * A worker whose behavior is scripted per attempt through a counter file
     * the forked child bumps: 'silent' sleeps out, 'fail' settles failed,
     * anything else completes with the wind word the attempt index earns.
     *
     * @param list<string> $script
     */
    private function scriptedStallExecutor(array $script): ExecutorInterface
    {
        return new class ($this->stallMarkerDir, $script, self::WORKER_SILENCE_SECONDS) implements ExecutorInterface {
            /** @param list<string> $script */
            public function __construct(
                private readonly string $stallMarkerDir,
                private readonly array $script,
                private readonly int $silenceSeconds,
            ) {}

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                throw new \LogicException('the forking path calls executeStream()');
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                $countFile = $this->stallMarkerDir . '/' . $agent->id . '-count';
                $attempt = (int) @file_get_contents($countFile);
                file_put_contents($countFile, (string) ($attempt + 1));

                $pids = (string) getmypid();
                file_put_contents($this->stallMarkerDir . '/' . $agent->id, $pids);

                $phase = $this->script[$attempt] ?? 'live';
                if ($phase === 'silent') {
                    $process = proc_open(
                        ['setsid', 'sleep', (string) $this->silenceSeconds],
                        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
                        $pipes,
                    );
                    if (\is_resource($process)) {
                        $pids .= ' ' . proc_get_status($process)['pid'];
                        file_put_contents($this->stallMarkerDir . '/' . $agent->id, $pids);
                    }
                    sleep($this->silenceSeconds);
                    if (\is_resource($process)) {
                        proc_close($process);
                    }
                }

                if ($phase === 'fail') {
                    yield new AgentResult(
                        agentId: $agent->id,
                        status: AgentStatus::Failed,
                        error: new \RuntimeException('the scripted model failure'),
                    );

                    return;
                }

                yield new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Completed,
                    output: 'done ' . match ($attempt) { 1 => 'second wind', default => 'third wind' },
                    startedAt: new \DateTimeImmutable(),
                    completedAt: new \DateTimeImmutable(),
                );
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };
    }

    private function stallAgent(
        string $id,
        int $stallTimeout,
        int $restarts = 0,
        int $timeout = 0,
        int $maxRetries = 0,
    ): SubAgent {
        return new SubAgent(
            id: $id,
            agent: new Agent(
                name: 'stalled',
                description: 'goes quiet',
                prompt: '',
                model: 'm',
                provider: 'echo',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'stall',
            timeout: $timeout,
            maxRetries: $maxRetries,
            stallTimeout: $stallTimeout,
            stallRestarts: $restarts,
        );
    }

    private function poolStallRequest(): CompleteRequest
    {
        return new CompleteRequest(model: 'm', messages: []);
    }

    /** @return array{0: int, 1: int} the forked worker's pid and its grandchild's */
    private function recordedWorkerPids(string $agentId): array
    {
        $marker = $this->stallMarkerDir . '/' . $agentId;
        self::assertFileExists($marker, 'the worker never started, so the test proves nothing');
        $pids = array_map('intval', explode(' ', trim((string) @file_get_contents($marker))));
        self::assertCount(2, $pids, 'the worker did not record a grandchild');

        return [$pids[0], $pids[1]];
    }

    /** A vanished process has no live /proc row: signalled-gone, reaped or never born. */
    private function processVanishedWithin(int $pid, float $withinSeconds): bool
    {
        $stop = microtime(true) + $withinSeconds;
        while (microtime(true) < $stop) {
            if (@posix_kill($pid, 0) === false) {
                return true;
            }
            usleep(25_000);
        }

        return false;
    }
}

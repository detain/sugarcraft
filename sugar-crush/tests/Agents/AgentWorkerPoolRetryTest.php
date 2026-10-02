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
 * Audit WF-1(b): a failed or timed-out agent is re-queued up to its
 * {@see SubAgent::$maxRetries} (or the pool's {@see AgentWorkerPool::withMaxRetries()}
 * floor) instead of being yielded.
 *
 * Before the fix the count was carried onto every SubAgent and read by
 * nothing: a task declaring `retries(2)` that failed once failed its stage on
 * that first attempt. The synchronous tests drive the pool with an injected
 * executor that follows a per-agent script of outcomes; the forked ones run
 * the same script in the pool's child, counting attempts in a marker file
 * because the child's memory does not survive to the next fork.
 */
final class AgentWorkerPoolRetryTest extends TestCase
{
    private string $markerDir;

    protected function setUp(): void
    {
        $this->markerDir = sys_get_temp_dir() . '/wf1b-retry-' . bin2hex(random_bytes(6));
        mkdir($this->markerDir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->markerDir . '/*') ?: [] as $marker) {
            @unlink($marker);
        }
        @rmdir($this->markerDir);
    }

    public function testAFailedAgentIsRetriedUntilItSucceedsAndYieldsOneResultCarryingEveryAttempt(): void
    {
        $executor = $this->scriptedExecutor(['flaky' => ['fail', 'fail', 'ok']]);
        $pool = new AgentWorkerPool(executor: $executor);

        $results = iterator_to_array($pool->executeAll([$this->retryAgent('flaky', retries: 2)], $this->retryRequest()), false);

        self::assertSame(['flaky', 'flaky', 'flaky'], $executor->calls, 'the agent was not re-run after its failed attempts');
        self::assertCount(1, $results, 'a retried agent must yield exactly one result');
        self::assertSame(AgentStatus::Completed, $results[0]->status);
        self::assertSame('attempt 3', $results[0]->output);
        self::assertNull($results[0]->error);
        self::assertSame(3, $results[0]->attempts);
        self::assertSame(30, $results[0]->tokensUsed, 'the failed attempts\' tokens were dropped');
        self::assertEqualsWithDelta(1.5, $results[0]->costUsd, 1e-9, 'the failed attempts\' cost was dropped');
        // Compared as text: the pool hands results through its IPC file, so
        // the object is rebuilt rather than passed through.
        self::assertSame(
            $executor->startedAt['flaky'][0]->format('U.u'),
            $results[0]->startedAt?->format('U.u'),
            'the result must start when the first attempt did',
        );
    }

    public function testAnAgentThatFailsEveryAttemptYieldsItsLastFailureNamingTheCount(): void
    {
        $executor = $this->scriptedExecutor(['broken' => ['fail']]);
        $pool = new AgentWorkerPool(executor: $executor);

        $results = iterator_to_array($pool->executeAll([$this->retryAgent('broken', retries: 1)], $this->retryRequest()), false);

        self::assertCount(2, $executor->calls, 'retries(1) means two attempts in all');
        self::assertCount(1, $results);
        self::assertSame(AgentStatus::Failed, $results[0]->status);
        self::assertSame(2, $results[0]->attempts);
        self::assertSame(20, $results[0]->tokensUsed);
        self::assertSame('attempt 2 failed [failed on all 2 attempts]', $results[0]->error?->getMessage());
    }

    public function testATimedOutAttemptIsRetried(): void
    {
        $executor = $this->scriptedExecutor(['slow' => ['timeout', 'ok']]);
        $pool = new AgentWorkerPool(executor: $executor);

        $results = iterator_to_array($pool->executeAll([$this->retryAgent('slow', retries: 1)], $this->retryRequest()), false);

        self::assertSame(AgentStatus::Completed, $results[0]->status, 'a timed-out attempt must count as a failure for retrying');
        self::assertSame(2, $results[0]->attempts);
    }

    public function testAStoppedAgentIsNeverRetried(): void
    {
        $executor = $this->scriptedExecutor(['cancelled' => ['stop', 'ok']]);
        $pool = new AgentWorkerPool(executor: $executor);

        $results = iterator_to_array($pool->executeAll([$this->retryAgent('cancelled', retries: 3)], $this->retryRequest()), false);

        self::assertCount(1, $executor->calls, 're-running a cancelled agent would undo the cancel');
        self::assertSame(AgentStatus::Stopped, $results[0]->status);
        self::assertSame(1, $results[0]->attempts);
    }

    public function testAnAgentWithoutRetriesYieldsItsResultUntouched(): void
    {
        $executor = $this->scriptedExecutor(['once' => ['fail', 'ok']]);
        $pool = new AgentWorkerPool(executor: $executor);

        $results = iterator_to_array($pool->executeAll([$this->retryAgent('once', retries: 0)], $this->retryRequest()), false);

        self::assertCount(1, $executor->calls);
        self::assertSame(AgentStatus::Failed, $results[0]->status);
        self::assertSame('attempt 1 failed', $results[0]->error?->getMessage(), 'an agent that never asked for retries must keep its own message');
        self::assertSame(1, $results[0]->attempts);
    }

    public function testThePoolFloorAppliesAndAnAgentsOwnHigherCountWins(): void
    {
        $executor = $this->scriptedExecutor(['floor' => ['fail', 'fail', 'fail', 'ok'], 'own' => ['fail', 'fail', 'fail', 'ok']]);
        $pool = (new AgentWorkerPool(executor: $executor))->withMaxRetries(1);

        $results = [];
        foreach ($pool->executeAll([$this->retryAgent('floor', retries: 0), $this->retryAgent('own', retries: 3)], $this->retryRequest()) as $result) {
            $results[$result->agentId] = $result;
        }

        self::assertSame(1, $pool->maxRetries());
        self::assertSame(2, $results['floor']->attempts, 'the pool floor of one retry did not reach an agent asking for none');
        self::assertSame(AgentStatus::Failed, $results['floor']->status);
        self::assertSame(4, $results['own']->attempts, 'an agent asking for more than the floor must get its own count');
        self::assertSame(AgentStatus::Completed, $results['own']->status);
        self::assertSame(0, (new AgentWorkerPool())->withMaxRetries(-3)->maxRetries(), 'a negative floor is no floor');
    }

    public function testAFailureThatWillBeRetriedDoesNotStopAFailFastBatch(): void
    {
        $executor = $this->scriptedExecutor(['first' => ['fail', 'ok'], 'second' => ['ok']]);
        $pool = (new AgentWorkerPool(maxConcurrent: 1, executor: $executor))->withStopOnFirstFailure(true);

        $results = [];
        foreach ($pool->executeAll([$this->retryAgent('first', retries: 1), $this->retryAgent('second', retries: 0)], $this->retryRequest()) as $result) {
            $results[$result->agentId] = $result;
        }

        self::assertSame(['second', 'first'], array_keys($results), 'the retry goes to the back of the queue, and the queued agent must still run');
        self::assertSame(AgentStatus::Completed, $results['first']->status);
        self::assertSame(AgentStatus::Completed, $results['second']->status);
    }

    public function testAQueuedRetryAFailFastStopDropsYieldsItsLastAttempt(): void
    {
        $executor = $this->scriptedExecutor(['retrying' => ['fail', 'ok'], 'fatal' => ['fail']]);
        $pool = (new AgentWorkerPool(maxConcurrent: 1, executor: $executor))->withStopOnFirstFailure(true);

        $results = [];
        foreach ($pool->executeAll([$this->retryAgent('retrying', retries: 1), $this->retryAgent('fatal', retries: 0)], $this->retryRequest()) as $result) {
            $results[$result->agentId] = $result;
        }

        self::assertSame(['retrying', 'fatal'], $executor->calls, 'the queued retry ran after the fail-fast stop');
        self::assertSame(['fatal', 'retrying'], array_keys($results), 'every dispatched agent must still yield exactly one result');
        self::assertSame(AgentStatus::Failed, $results['retrying']->status);
        self::assertSame(1, $results['retrying']->attempts);
        self::assertSame(
            'attempt 1 failed [not retried after attempt 1: the run was stopped before the retry could start]',
            $results['retrying']->error?->getMessage(),
        );
    }

    public function testAQueuedRetryCancelAllDropsYieldsItsLastAttempt(): void
    {
        $pool = null;
        $executor = $this->scriptedExecutor(
            ['retrying' => ['fail', 'ok'], 'canceller' => ['ok']],
            onCall: static function (string $agentId) use (&$pool): void {
                if ($agentId === 'canceller') {
                    $pool->cancelAll();
                }
            },
        );
        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $executor);

        $results = [];
        foreach ($pool->executeAll([$this->retryAgent('retrying', retries: 1), $this->retryAgent('canceller', retries: 0)], $this->retryRequest()) as $result) {
            $results[$result->agentId] = $result;
        }

        self::assertSame(['retrying', 'canceller'], $executor->calls, 'the retry ran after cancelAll()');
        self::assertArrayHasKey('retrying', $results, 'a retry cancelAll() dropped must still yield its agent\'s last attempt');
        self::assertSame(AgentStatus::Failed, $results['retrying']->status);
        self::assertStringContainsString('not retried after attempt 1', (string) $results['retrying']->error?->getMessage());
    }

    public function testNothingIsRetriedOnceTheTimeBudgetIsSpent(): void
    {
        $executor = $this->scriptedExecutor(['late' => ['fail', 'ok']], sleepSeconds: 1.2);
        $pool = (new AgentWorkerPool(executor: $executor))->withTimeBudget(1.0);

        $results = iterator_to_array($pool->executeAll([$this->retryAgent('late', retries: 2)], $this->retryRequest()), false);

        self::assertCount(1, $executor->calls, 'an attempt was started after the run\'s time budget ran out');
        self::assertSame(AgentStatus::Failed, $results[0]->status);
        self::assertSame(
            'attempt 1 failed [not retried after attempt 1 of 3: the time budget was spent]',
            $results[0]->error?->getMessage(),
        );
    }

    public function testExecuteOneRetriesOnItsDirectPathToo(): void
    {
        $executor = $this->scriptedExecutor(['single' => ['timeout', 'fail', 'ok']]);
        $pool = new AgentWorkerPool(executor: $executor);

        $result = $pool->executeOne($this->retryAgent('single', retries: 2), $this->retryRequest());

        self::assertCount(3, $executor->calls);
        self::assertSame(AgentStatus::Completed, $result->status);
        self::assertSame(3, $result->attempts);
        self::assertSame(30, $result->tokensUsed);
        self::assertSame($executor->startedAt['single'][0], $result->startedAt);
    }

    public function testExecuteOneGivesUpWithTheCountAfterItsLastAttempt(): void
    {
        $executor = $this->scriptedExecutor(['single' => ['fail']]);

        $result = (new AgentWorkerPool(executor: $executor))->executeOne($this->retryAgent('single', retries: 1), $this->retryRequest());

        self::assertCount(2, $executor->calls);
        self::assertSame('attempt 2 failed [failed on all 2 attempts]', $result->error?->getMessage());
    }

    public function testAForkedAgentIsRetriedInAFreshWorker(): void
    {
        $this->requireForking();
        $pool = new AgentWorkerPool(forkedExecutor: $this->forkedScriptExecutor(['forked' => ['fail', 'ok']]));

        $results = iterator_to_array($pool->executeAll([$this->retryAgent('forked', retries: 1)], $this->retryRequest()), false);

        self::assertCount(1, $results);
        self::assertSame(AgentStatus::Completed, $results[0]->status);
        self::assertSame('forked attempt 2', $results[0]->output);
        self::assertSame(2, $results[0]->attempts);
        self::assertSame(14, $results[0]->tokensUsed);
    }

    public function testAForkedAttemptKilledAtItsTimeoutIsRetriedWithAFreshTimeout(): void
    {
        $this->requireForking();
        $pool = new AgentWorkerPool(forkedExecutor: $this->forkedScriptExecutor(['hung' => ['hang', 'ok']]));

        $began = microtime(true);
        $results = iterator_to_array($pool->executeAll([$this->retryAgent('hung', retries: 1, timeout: 1)], $this->retryRequest()), false);
        $elapsed = microtime(true) - $began;

        self::assertLessThan(4.0, $elapsed, 'the hung attempt was waited out rather than killed at its timeout');
        self::assertSame(AgentStatus::Completed, $results[0]->status, 'an attempt the pool killed at its timeout must be retried');
        self::assertSame(2, $results[0]->attempts);
    }

    private function requireForking(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('the forking dispatch path needs ext-pcntl and ext-posix');
        }
    }

    /**
     * A synchronous executor that answers each agent's Nth call with the Nth
     * outcome of its script (the last one repeating): `ok`, `fail`,
     * `timeout` or `stop`. Every attempt bills 10 tokens and $0.50.
     *
     * @param array<string, list<string>> $script
     * @param ?\Closure(string): void $onCall runs before each answer, with the agent id
     */
    private function scriptedExecutor(array $script, ?\Closure $onCall = null, float $sleepSeconds = 0.0): ExecutorInterface
    {
        return new class ($script, $onCall, $sleepSeconds) implements ExecutorInterface {
            /** @var list<string> */
            public array $calls = [];

            /** @var array<string, list<\DateTimeImmutable>> */
            public array $startedAt = [];

            /**
             * @param array<string, list<string>> $script
             */
            public function __construct(
                private readonly array $script,
                private readonly ?\Closure $onCall,
                private readonly float $sleepSeconds,
            ) {}

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                $this->calls[] = $agent->id;
                $started = new \DateTimeImmutable();
                $this->startedAt[$agent->id][] = $started;
                $attempt = \count($this->startedAt[$agent->id]);
                $outcomes = $this->script[$agent->id];
                $outcome = $outcomes[min($attempt, \count($outcomes)) - 1];

                if ($this->onCall !== null) {
                    ($this->onCall)($agent->id);
                }
                if ($this->sleepSeconds > 0) {
                    usleep((int) ($this->sleepSeconds * 1_000_000));
                }

                return new AgentResult(
                    agentId: $agent->id,
                    status: match ($outcome) {
                        'ok' => AgentStatus::Completed,
                        'timeout' => AgentStatus::TimedOut,
                        'stop' => AgentStatus::Stopped,
                        default => AgentStatus::Failed,
                    },
                    output: 'attempt ' . $attempt,
                    error: $outcome === 'ok' ? null : new \RuntimeException('attempt ' . $attempt . ' failed'),
                    tokensUsed: 10,
                    costUsd: 0.5,
                    startedAt: $started,
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

    /**
     * The forking path's counterpart: each attempt runs in a fresh child, so
     * the attempt number lives in a marker file. `hang` sleeps ten seconds,
     * well past any timeout these tests set. Every attempt bills 7 tokens.
     *
     * @param array<string, list<string>> $script
     */
    private function forkedScriptExecutor(array $script): ExecutorInterface
    {
        return new class ($this->markerDir, $script) implements ExecutorInterface {
            /**
             * @param array<string, list<string>> $script
             */
            public function __construct(
                private readonly string $markerDir,
                private readonly array $script,
            ) {}

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                throw new \LogicException('the forking path calls executeStream()');
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                $counter = $this->markerDir . '/' . $agent->id . '.attempts';
                $attempt = (int) @file_get_contents($counter) + 1;
                file_put_contents($counter, (string) $attempt);
                $outcomes = $this->script[$agent->id];
                $outcome = $outcomes[min($attempt, \count($outcomes)) - 1];

                if ($outcome === 'hang') {
                    sleep(10);
                }

                yield new AgentResult(
                    agentId: $agent->id,
                    status: $outcome === 'ok' ? AgentStatus::Completed : AgentStatus::Failed,
                    output: 'forked attempt ' . $attempt,
                    error: $outcome === 'ok' ? null : new \RuntimeException('forked attempt ' . $attempt . ' failed'),
                    tokensUsed: 7,
                    startedAt: new \DateTimeImmutable(),
                    completedAt: new \DateTimeImmutable(),
                );
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };
    }

    private function retryAgent(string $id, int $retries, int $timeout = 30): SubAgent
    {
        return new SubAgent(
            id: $id,
            agent: new Agent(
                name: 'retrier',
                description: 'may fail',
                prompt: '',
                model: 'm',
                provider: 'echo',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'try',
            timeout: $timeout,
            maxRetries: $retries,
        );
    }

    private function retryRequest(): CompleteRequest
    {
        return new CompleteRequest(model: 'm', messages: []);
    }
}

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
 * A FORK THAT FAILED AND A FORK THAT WAS NEVER ATTEMPTED ARE DIFFERENT EVENTS,
 * AND THE OPERATOR CAN NOW TELL THEM APART.
 *
 * `AgentWorkerPool::startAgent()` has four dispatch paths and three of them run
 * the agent synchronously in the parent. Two of those three announced
 * themselves; the `pcntl_fork() === -1` arm announced nothing at all, on any
 * channel. Both degrade to the same fallback, so from outside they looked
 * identical — while the remedies are not remotely the same. An absent `pcntl`
 * is a static fact about the installation, fixed by installing an extension. A
 * `-1` is a kernel resource verdict (`EAGAIN` for `RLIMIT_NPROC`, `ENOMEM` for
 * memory) that may clear on its own and may be this application's own doing.
 *
 * THE TWO MESSAGES ARE PINNED AGAINST EACH OTHER HERE, in both directions, so
 * that a future edit which collapses them back into one indistinguishable
 * sentence reds. Distinguishability IS the feature; asserting only that each
 * arm says something would pass on two identical strings.
 *
 * WHY REFLECTION AND NOT A SUBCLASS. `AgentWorkerPool` is `final`. The
 * pcntl-unavailable arm was already driven by Reflection-setting
 * `forcePcntlUnavailableForTesting`, and the fork-failure seam
 * (`forceForkFailureForTesting`, consulted by `forkProcess()`) is its sibling
 * and is set the same way. An honest fork failure cannot be provoked: it needs
 * the process table or memory exhausted, and a test that arranged either would
 * take the whole box down rather than just this arm — which is much of why the
 * branch went unexercised long enough to stay silent.
 */
final class AgentWorkerPoolForkFailureTest extends TestCase
{
    private CompleteRequest $request;

    private string $logFile;

    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = new CompleteRequest(
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'Hello!']],
        );

        // `tempnam()` and not a `uniqid()`-built name: five suites share one
        // uid-keyed TMPDIR during an audit round, and `uniqid()` with no
        // arguments is microtime-derived rather than process-unique.
        $log = tempnam(sys_get_temp_dir(), 'sc_r49b_forkfail_');
        self::assertIsString($log);
        $this->logFile = $log;
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);

        parent::tearDown();
    }

    /**
     * THE FAILED FORK NOW SAYS SO, ONCE, AND NAMES THE REMEDY.
     *
     * Also asserts the half the routing decision rests on: the agent still ran
     * and its result was still stored. `warnForkFailed()`'s doc-block argues
     * `error_log()` rather than the mid-session transcript seam precisely
     * BECAUSE the caller still gets what it asked for, and an argument of that
     * shape should fail with the fact it depends on rather than outlive it.
     */
    public function testAFailedForkWarnsOnceAndStillRunsTheAgent(): void
    {
        $pool = new AgentWorkerPool(maxConcurrent: 3);
        self::forceForkFailure($pool);

        self::assertSame('', $this->logContents(), 'something logged before the arm was reached');

        foreach (['fork-fail-a', 'fork-fail-b'] as $id) {
            $this->startAgent($pool, $id);
        }

        $log = $this->logContents();

        self::assertSame(
            1,
            substr_count($log, 'AgentWorkerPool: pcntl_fork() FAILED'),
            'the failed-fork warning must fire exactly once per pool, not once per agent — a pool '
                . 'that has run out of processes is precisely the one about to dispatch many',
        );
        self::assertStringContainsString('runs sequentially in the parent', $log);
        self::assertStringContainsString('RLIMIT_NPROC', $log);
        self::assertStringContainsString('maxConcurrent, currently 3', $log);

        // THE ROUTING ARGUMENT'S OWN PREMISE. Both agents still ran and both
        // results are still stored and reapable, which is why this is an
        // error_log() and not a RuntimeNoticeSink row.
        foreach (['fork-fail-a', 'fork-fail-b'] as $id) {
            self::assertTrue(
                self::hasResult($pool, $id),
                "the {$id} agent produced no result, so the fallback lost work rather than only "
                    . 'losing concurrency — warnForkFailed() belongs on the transcript seam after all',
            );
        }
    }

    /**
     * AND THE ABSENT-PCNTL ARM STILL SAYS ITS OWN, DIFFERENT THING.
     *
     * The negative half of the pair. Without it, "the failed fork warns" would
     * be satisfied by a pool that emitted the same sentence for both causes,
     * which is the exact defect this work closed.
     */
    public function testTheTwoFallbackCausesDoNotShareASentence(): void
    {
        $failed = new AgentWorkerPool(maxConcurrent: 2);
        self::forceForkFailure($failed);
        $this->startAgent($failed, 'cause-fork-failed');
        $failedLog = $this->logContents();

        @unlink($this->logFile);
        $unavailable = new AgentWorkerPool(maxConcurrent: 2);
        self::forcePcntlUnavailable($unavailable);
        $this->startAgent($unavailable, 'cause-pcntl-absent');
        $unavailableLog = $this->logContents();

        self::assertStringContainsString('pcntl_fork() FAILED', $failedLog);
        self::assertStringNotContainsString('pcntl_fork() is unavailable', $failedLog);

        self::assertStringContainsString('pcntl_fork() is unavailable', $unavailableLog);
        self::assertStringNotContainsString('pcntl_fork() FAILED', $unavailableLog);

        self::assertNotSame(
            trim($failedLog),
            trim($unavailableLog),
            'the two arms emit the same sentence again, so the operator is back to being unable '
                . 'to tell a resource limit from a missing extension',
        );
    }

    /**
     * THE SEAM DEFAULTS TO OFF, so the test above is measuring the arm rather
     * than a pool that reports failure unconditionally.
     *
     * This is the control for `forceForkFailureForTesting` itself: a seam
     * stuck on would make every assertion in this file pass for the wrong
     * reason. Read through Reflection rather than exercised through a real
     * `pcntl_fork()`, which would fork the test runner.
     */
    public function testTheForkFailureSeamIsOffUnlessATestTurnsItOn(): void
    {
        $property = new \ReflectionProperty(AgentWorkerPool::class, 'forceForkFailureForTesting');

        self::assertFalse($property->getValue(new AgentWorkerPool()));

        $forced = new AgentWorkerPool();
        self::forceForkFailure($forced);
        self::assertTrue($property->getValue($forced));
    }

    private function logContents(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    private static function forceForkFailure(AgentWorkerPool $pool): void
    {
        (new \ReflectionProperty(AgentWorkerPool::class, 'forceForkFailureForTesting'))
            ->setValue($pool, true);
    }

    private static function forcePcntlUnavailable(AgentWorkerPool $pool): void
    {
        (new \ReflectionProperty(AgentWorkerPool::class, 'forcePcntlUnavailableForTesting'))
            ->setValue($pool, true);
    }

    /**
     * Drive one dispatch through `startAgent()` directly.
     *
     * `executeAll()` is not the entry point here on purpose: reaching the fork
     * arms at all requires that NO executor was injected (an injected one
     * short-circuits into the synchronous branch above them), and a pool with
     * no executor builds a real `ProcessExecutor` that would spawn a model
     * request. `startAgent()` takes the executor as a parameter, so passing a
     * stub reaches the arm without either compromise.
     */
    private function startAgent(AgentWorkerPool $pool, string $agentId): void
    {
        (new \ReflectionMethod(AgentWorkerPool::class, 'startAgent'))
            ->invoke($pool, self::agent($agentId), $this->request, $this->executor());
    }

    private static function hasResult(AgentWorkerPool $pool, string $agentId): bool
    {
        return (bool) (new \ReflectionMethod(AgentWorkerPool::class, 'hasResult'))
            ->invoke($pool, $agentId);
    }

    private static function agent(string $id): SubAgent
    {
        return new SubAgent(
            id: $id,
            agent: new Agent(
                name: 'ForkFailureAgent',
                description: 'Test agent',
                prompt: 'You are a test agent.',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'Test task for ' . $id,
        );
    }

    /**
     * A stub executor, mocked rather than hand-written: `ExecutorInterface`
     * also declares `executeStream()`, `cancel()` and `cancelAll()`, and an
     * anonymous class implementing only `execute()` is a fatal, not a test
     * failure — which under a redirected `error_log` is an rc-255 with an
     * EMPTY console.
     */
    private function executor(): ExecutorInterface
    {
        $executor = $this->createMock(ExecutorInterface::class);
        $executor->method('execute')->willReturnCallback(
            static fn (SubAgent $agent): AgentResult => new AgentResult(
                agentId: $agent->id,
                status: AgentStatus::Completed,
                output: 'ran in the parent',
            ),
        );

        return $executor;
    }
}

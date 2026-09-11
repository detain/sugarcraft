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
 * E687: THE POOL NOW COUNTS ITS FORK FAILURES OUT LOUD WHEN IT DIES.
 *
 * `warnForkFailed()` latches its dispatch warning to one line per pool (the
 * E261 rule, pinned by {@see AgentWorkerPoolForkFailureTest}), and the total
 * of the failures the latch hid lived ONLY in `forkFailureCount()` — an
 * accessor for a caller that is still running. A pool whose last dispatch was
 * also its last observer died silent: the operator saw the FIRST failure and
 * never learned there was a second. The sibling file's own assertions run
 * BEFORE any pool destructs (its locals are alive through every check), so
 * the teardown line could only land untested there — this file exists because
 * the channel needs a pin of its own shape: it fires at most once, names the
 * total, stays silent for a healthy pool, and is covered by the same
 * owner-pid guard as the reaping above it.
 *
 * THE PAIRING RULE (E687's whole point): this site and its
 * {@see \SugarCraft\Crush\Tests\Cli\StderrEmitterCensusTest} roster bump
 * (`src/Agents/AgentWorkerPool.php` 2 → 3, TWENTY-THREE → TWENTY-FOUR in the
 * channel-3 doc-block) ship in ONE commit — a new `error_log()` site arriving
 * without its census row in-step is exactly the drift that kept this line
 * blocked in lane HH.
 *
 * THE MESSAGE SHARES NO DISTINGUISHING SUBSTRING with either dispatch
 * sentence ('pcntl_fork() FAILED', 'pcntl_fork() is unavailable'), and that
 * separation is asserted, not assumed: three log lines an operator cannot
 * tell apart from each other are one log line with delusions.
 *
 * WHY REFLECTION AND NOT A SUBCLASS: `AgentWorkerPool` is `final`, and the
 * seams this file drives (`forceForkFailureForTesting`, `resultDirOwnerPid`,
 * `resultDir`) are private, reached exactly the way the sibling file reaches
 * them.
 */
final class AgentWorkerPoolTeardownForkTotalTest extends TestCase
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

        $log = tempnam(sys_get_temp_dir(), 'sc_r66cd_teardown_');
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
     * THE TOTAL SURFACES ONCE, AT THE END, NAMING THE NUMBER.
     *
     * Two forced fork failures: the dispatch latch says ONE warning line
     * (the sibling's rule), and the teardown says the TWO the latch hid —
     * asserted in both directions so neither half of the pair can silently
     * absorb the other: nothing teardown-shaped may exist before the pool
     * dies, and exactly one total line must exist after.
     */
    public function testTheTeardownLineNamesTheFailuresTheDispatchLatchHid(): void
    {
        $pool = new AgentWorkerPool(maxConcurrent: 3);
        self::forceForkFailure($pool);

        foreach (['teardown-a', 'teardown-b'] as $id) {
            $this->startAgent($pool, $id);
        }

        self::assertSame(
            0,
            substr_count($this->logContents(), 'AgentWorkerPool: teardown'),
            'the total line fired while the pool was still alive — it is a last word, not a running counter',
        );

        unset($pool);

        $log = $this->logContents();

        self::assertSame(
            1,
            substr_count($log, 'AgentWorkerPool: teardown'),
            'the teardown total must surface exactly once for a pool that lost forks',
        );
        self::assertStringContainsString('lost 2 pcntl_fork() failure(s)', $log);
        self::assertSame(
            1,
            substr_count($log, 'AgentWorkerPool: pcntl_fork() FAILED'),
            'the dispatch latch still says one first-failure line — the total joins it, never replaces it',
        );
    }

    /**
     * A POOL THAT NEVER LOST A FORK NEVER SAYS THE LINE.
     *
     * The negative half: without it, an unconditional teardown log would pass
     * everything above, and every pool death in every suite run would add a
     * `lost 0 pcntl_fork() failure(s)` line to stderr noise the census counts
     * as a real emitter surface. No dispatch is attempted here on purpose —
     * an unforced `startAgent()` would really `pcntl_fork()`, and a test child
     * resuming PHPUnit is the duplicate-summary disaster the sibling file's
     * control test works around with `ForkedChild::exitNow()`.
     */
    public function testAHealthyPoolTearsDownWithoutATotalLine(): void
    {
        $pool = new AgentWorkerPool(maxConcurrent: 2);

        unset($pool);

        self::assertSame(
            0,
            substr_count($this->logContents(), 'AgentWorkerPool: teardown'),
            'a pool that lost zero forks has nothing to confess',
        );
    }

    /**
     * THE THREE SENTENCES STAY PAIRWISE TELLABLE APART.
     *
     * The doctrine that pins the two dispatch arms against each other extends
     * to the third line: the teardown total must not launder itself into
     * either dispatch shape. Extracted per line rather than by substring
     * alone, so a message that merely CONTAINS an old prefix somewhere in
     * its prose cannot slip the net.
     */
    public function testTheTeardownSentenceSharesNoPrefixWithEitherDispatchSentence(): void
    {
        $pool = new AgentWorkerPool(maxConcurrent: 2);
        self::forceForkFailure($pool);
        $this->startAgent($pool, 'prefix-check');
        unset($pool);

        $lines = array_values(array_filter(
            explode("\n", $this->logContents()),
            static fn (string $line): bool => str_contains($line, 'AgentWorkerPool:'),
        ));

        self::assertCount(2, $lines, 'one dispatch warning + one teardown total, nothing else: ' . var_export($lines, true));

        foreach ($lines as $line) {
            $isDispatch = str_contains($line, 'AgentWorkerPool: pcntl_fork() FAILED');
            $isTeardown = str_contains($line, 'AgentWorkerPool: teardown');
            self::assertTrue(
                $isDispatch !== $isTeardown,
                'a line matches both the dispatch prefix and the teardown prefix, or neither — '
                    . 'the three fork sentences have collapsed back into indistinguishable noise: ' . $line,
            );
        }

        self::assertStringNotContainsString('pcntl_fork() is unavailable', implode("\n", $lines));
    }

    /**
     * THE OWNER GUARD COVERS THE NEW LINE TOO.
     *
     * `__destruct()` starts with a non-owner early-return so a forked child
     * that inherits this object runs no sibling-signalling teardown; the
     * total line lives BELOW that guard and this pins it there — a site
     * hoisted above the guard would log the parent's failure count once per
     * dead forked child, doubling the very number it reports. The pool is
     * flipped to a non-owner via `resultDirOwnerPid` before it dies, so the
     * result directory survives the teardown and is cleaned by hand here —
     * the same leak the guard has always permitted for its own sake.
     */
    public function testANonOwnerDestructLogsNeitherTheTotalNorAnythingElse(): void
    {
        $pool = new AgentWorkerPool(maxConcurrent: 2);
        self::forceForkFailure($pool);
        $this->startAgent($pool, 'non-owner');

        /** @var string $resultDir */
        $resultDir = (string) (new \ReflectionProperty(AgentWorkerPool::class, 'resultDir'))->getValue($pool);
        (new \ReflectionProperty(AgentWorkerPool::class, 'resultDirOwnerPid'))
            ->setValue($pool, ((int) getmypid()) + 1);

        unset($pool);

        self::assertSame(
            0,
            substr_count($this->logContents(), 'AgentWorkerPool: teardown'),
            'a non-owner destruct ran the teardown emitter — every forked child would re-log the '
                . "parent's total and the number it names would be a multiple of the truth",
        );
        self::assertSame(
            1,
            substr_count($this->logContents(), 'AgentWorkerPool: pcntl_fork() FAILED'),
            'the dispatch latch (which runs well before the guard) is untouched by this seam',
        );

        foreach (glob($resultDir . '/*') ?: [] as $leftover) {
            @unlink($leftover);
        }
        @rmdir($resultDir);
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

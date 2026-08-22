<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowRegistry;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * Integration tests for workflow resumption from paused state.
 *
 * Tests the pause/resume cycle:
 * - pause() writes a valid pause file with current state
 * - resume() reloads state and continues from where it left off
 * - Completed stages are not re-run on resume
 * - Context is preserved across pause/resume
 */
final class WorkflowResumptionTest extends TestCase
{
    use \SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

    /**
     * THE PER-TEST TIME LIMIT DOES NOT REACH A FORKED CHILD. `phpunit.xml`'s
     * `enforceTimeLimit`/`defaultTimeLimit` is `pcntl_alarm()`, which fires in
     * the process that armed it and is not inherited across `pcntl_fork()` -
     * so an abort here stops the parent and leaves every child of this test
     * running with no clock at all, writing into a temp tree the parent's own
     * `tearDown()` is about to delete. {@see ReapsForkedChildrenTrait} for the
     * measurement behind that, and for why `tearDown()` is the right place to
     * put the net.
     */
    use \SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

    private string $tempDir;
    private WorkflowRegistry $registry;
    private ExecutorInterface $mockExecutor;
    private AgentWorkerPool $pool;
    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-resume-test-' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);

        // Override HOME so pause files go to our temp directory. BOTH forms:
        // WorkflowEngine resolves ~ through
        // {@see \SugarCraft\Crush\Support\HomeDirectory}, which reads
        // `getenv()` — and the env var is also what the SIGTERM subprocess
        // below inherits, which `$_SERVER` alone never was.
        $this->useHomeSandbox($this->tempDir);

        $this->registry = new WorkflowRegistry();

        // Mock the executor so no real LLM calls are made
        $this->mockExecutor = $this->getMockBuilder(ExecutorInterface::class)
            ->onlyMethods(['execute', 'executeStream', 'cancel', 'cancelAll'])
            ->getMock();

        $this->pool = new AgentWorkerPool(5, $this->mockExecutor);
        $this->engine = new WorkflowEngine($this->registry, $this->pool);
    }

    protected function tearDown(): void
    {
        // BEFORE the temp tree goes, not after: an orphan still running when
        // the directory is removed goes on writing into a path that no longer
        // exists, and the next test inherits the wreckage. Runs on the abort
        // path too - PHPUnit swallows the time-limit TimeoutException in
        // runBare() and calls tearDown() anyway.
        $this->reapTrackedForkedChildren();

        $this->restoreHomeSandbox();

        // Clean up temp directory
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    private function successfulAgentResult(string $output, int $tokens = 100, float $cost = 0.01): AgentResult
    {
        return new AgentResult(
            agentId: 'agent-' . uniqid(),
            status: AgentStatus::Completed,
            output: $output,
            tokensUsed: $tokens,
            costUsd: $cost,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * testPauseCreatesValidPauseFile: running a workflow and then calling
     * pause() should produce a valid JSON pause file with all state needed
     * to resume.
     */
    public function testPauseCreatesValidPauseFile(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('pause-file-test')
            ->description('Test pause creates valid pause file')
            ->stage('stage-1', Tasks::agent('coder')->prompt('Do first task'))
            ->stage('stage-2', Tasks::agent('coder')->prompt('Do second task'))
            ->build();

        $this->registry->register($workflow);

        $callCount = 0;
        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return $this->successfulAgentResult("output-{$callCount}");
            });

        // Run the workflow to completion
        $result = $this->engine->run('pause-file-test', []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2, $callCount);

        // Now pause it
        $this->engine->pause('pause-file-test');

        // Verify pause file exists
        $pauseFile = $this->tempDir . '/.sugar-crush/workflows/.running/pause-file-test.json';
        $this->assertFileExists($pauseFile);

        // Verify pause file contents
        $data = json_decode(file_get_contents($pauseFile), true);
        // `workflowId` is the RUN's own `<name>-<hash>` — the identifier the
        // transcript prints and `/workflow pause|resume|status` now accept —
        // while `workflowPath` is the loadable name resume() hands the registry.
        // The two fields used to hold the same string, which is what made a pause
        // file taken after a resume-by-ID unloadable.
        $this->assertMatchesRegularExpression('/^pause-file-test-[0-9a-f]{8}$/', $data['workflowId']);
        $this->assertSame('pause-file-test', $data['workflowPath']);
        $this->assertSame('paused', $data['status']);
        $this->assertSame(2, $data['stagesCompleted']);
        $this->assertSame('output-1', $data['context']['stage-1.output']);
        $this->assertSame('output-2', $data['context']['stage-2.output']);
        $this->assertSame(200, $data['totalTokens']);
        $this->assertSame(0.02, $data['totalCost']);

        // Verify stageResults array is present
        $this->assertCount(2, $data['stageResults']);
        $this->assertSame('stage-1', $data['stageResults'][0]['stageName']);
        $this->assertSame('stage-2', $data['stageResults'][1]['stageName']);
    }

    /**
     * testResumeContinuesFromPausedState: simulate a workflow that was
     * interrupted after stage 1, then resume and verify stage 2 completes.
     */
    public function testResumeContinuesFromPausedState(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('resume-continue-test')
            ->description('Test resume continues from paused state')
            ->stage('stage-1', Tasks::agent('coder')->prompt('First step'))
            ->stage('stage-2', Tasks::agent('coder')->prompt('Second step'))
            ->stage('stage-3', Tasks::agent('coder')->prompt('Third step'))
            ->build();

        $this->registry->register($workflow);

        $callCount = 0;
        $this->mockExecutor
            ->expects($this->any())
            ->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return $this->successfulAgentResult("output-{$callCount}");
            });

        // Create the .running directory and simulate a pause file for a workflow where only stage 1 completed
        mkdir($this->tempDir . '/.sugar-crush/workflows/.running', 0755, true);
        $pauseFile = $this->tempDir . '/.sugar-crush/workflows/.running/resume-continue-test.json';
        $pauseData = [
            'workflowId' => 'resume-continue-test',
            'workflowPath' => 'resume-continue-test',
            'status' => 'paused',
            'stagesCompleted' => 1,
            'context' => [
                'stage-1.output' => 'output-1',
            ],
            'stageResults' => [
                [
                    'stageName' => 'stage-1',
                    'status' => 'completed',
                    'output' => 'output-1',
                    'error' => null,
                    'agents' => [
                        [
                            'agentId' => 'agent-1',
                            'status' => 'completed',
                            'output' => 'output-1',
                            'error' => null,
                            'tokensUsed' => 100,
                            'costUsd' => 0.01,
                            'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                            'completedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                        ],
                    ],
                    'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'completedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ],
            ],
            'totalTokens' => 100,
            'totalCost' => 0.01,
            'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'pausedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
        file_put_contents($pauseFile, json_encode($pauseData, JSON_PRETTY_PRINT) . "\n");

        // Resume should continue from stage 2 (index 1)
        $resumeResult = $this->engine->resume('resume-continue-test');

        $this->assertTrue($resumeResult->isSuccess());
        $this->assertSame(WorkflowStatus::Completed, $resumeResult->status);

        // Context from pause file should be preserved
        $this->assertSame('output-1', $resumeResult->context['stage-1.output']);

        // stage-2.output is from the first resumed call (callCount started at 0 since no run() was called)
        // stage-3.output is from the second resumed call
        $this->assertSame('output-1', $resumeResult->context['stage-2.output'] ?? null);
        $this->assertSame('output-2', $resumeResult->context['stage-3.output'] ?? null);
    }

    /**
     * testCompletedStagesNotReRun: verify that when a workflow is resumed,
     * stages that were already completed are skipped and not re-executed.
     */
    public function testCompletedStagesNotReRun(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('no-rerun-test')
            ->description('Test completed stages not re-run on resume')
            ->stage('alpha', Tasks::agent('worker')->prompt('Alpha task'))
            ->stage('beta', Tasks::agent('worker')->prompt('Beta task'))
            ->stage('gamma', Tasks::agent('worker')->prompt('Gamma task'))
            ->build();

        $this->registry->register($workflow);

        $executionLog = [];
        $callCount = 0;
        $this->mockExecutor
            ->expects($this->any())
            ->method('execute')
            ->willReturnCallback(function ($agent, CompleteRequest $request) use (&$executionLog, &$callCount) {
                $callCount++;
                $content = $request->messages[0]['content'];
                $executionLog[] = "call-{$callCount}: {$content}";
                return $this->successfulAgentResult("result-{$callCount}");
            });

        // Create the .running directory and a pause file indicating stage 1 (alpha) completed
        mkdir($this->tempDir . '/.sugar-crush/workflows/.running', 0755, true);
        $pauseFile = $this->tempDir . '/.sugar-crush/workflows/.running/no-rerun-test.json';
        $pauseData = [
            'workflowId' => 'no-rerun-test',
            'workflowPath' => 'no-rerun-test',
            'status' => 'paused',
            'stagesCompleted' => 1,
            'context' => [
                'alpha.output' => 'result-1',
            ],
            'stageResults' => [
                [
                    'stageName' => 'alpha',
                    'status' => 'completed',
                    'output' => 'result-1',
                    'error' => null,
                    'agents' => [],
                    'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'completedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ],
            ],
            'totalTokens' => 100,
            'totalCost' => 0.01,
            'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'pausedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
        file_put_contents($pauseFile, json_encode($pauseData, JSON_PRETTY_PRINT) . "\n");

        // Resume should execute only beta and gamma, NOT alpha
        $resumeResult = $this->engine->resume('no-rerun-test');

        $this->assertTrue($resumeResult->isSuccess());

        // Verify only 2 calls were made (beta and gamma), not 3
        $this->assertCount(2, $executionLog);

        // Verify alpha was not re-run
        foreach ($executionLog as $log) {
            $this->assertStringNotContainsString('Alpha task', $log);
        }

        // Verify beta and gamma were run
        $this->assertStringContainsString('Beta task', $executionLog[0]);
        $this->assertStringContainsString('Gamma task', $executionLog[1]);
    }

    /**
     * testResumeWithPartialParallelStage: test resuming when a parallel
     * stage was partially complete (not fully supported in current impl,
     * but verifies graceful handling).
     */
    public function testResumeWithContextPreservation(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('context-preserve-test')
            ->description('Test context is preserved across pause/resume')
            ->stage('init', Tasks::agent('setup')->prompt('Initialize {{project}}'))
            ->stage('process', Tasks::agent('processor')->prompt('Process {{init.output}}'))
            ->build();

        $this->registry->register($workflow);

        $callCount = 0;
        $this->mockExecutor
            ->expects($this->any())
            ->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return $this->successfulAgentResult("phase-{$callCount}-result");
            });

        // Create the .running directory and a pause file with initial context
        mkdir($this->tempDir . '/.sugar-crush/workflows/.running', 0755, true);
        $pauseFile = $this->tempDir . '/.sugar-crush/workflows/.running/context-preserve-test.json';
        $pauseData = [
            'workflowId' => 'context-preserve-test',
            'workflowPath' => 'context-preserve-test',
            'status' => 'paused',
            'stagesCompleted' => 1,
            'context' => [
                'project' => 'my-app',
                'init.output' => 'phase-1-result',
            ],
            'stageResults' => [
                [
                    'stageName' => 'init',
                    'status' => 'completed',
                    'output' => 'phase-1-result',
                    'error' => null,
                    'agents' => [],
                    'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'completedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ],
            ],
            'totalTokens' => 100,
            'totalCost' => 0.01,
            'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'pausedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
        file_put_contents($pauseFile, json_encode($pauseData, JSON_PRETTY_PRINT) . "\n");

        // Resume should preserve the 'project' initial context
        $resumeResult = $this->engine->resume('context-preserve-test');

        $this->assertTrue($resumeResult->isSuccess());

        // Original initial context should still be present
        $this->assertSame('my-app', $resumeResult->context['project']);

        // init.output is from the pause file (no run() was called first, so callCount=0 on resume)
        // process.output is from the first resumed call -> 'phase-1-result'
        $this->assertSame('phase-1-result', $resumeResult->context['init.output']);
        $this->assertSame('phase-1-result', $resumeResult->context['process.output']);
    }

    /**
     * testGetStatusReturnsCorrectPausedStatus: verify getStatus() correctly
     * reads the status from a pause file.
     */
    public function testGetStatusReturnsCorrectPausedStatus(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('status-check-test')
            ->description('Test getStatus returns paused status')
            ->stage('step1', Tasks::agent('worker')->prompt('Do work'))
            ->build();

        $this->registry->register($workflow);

        // Create the .running directory and a pause file directly
        mkdir($this->tempDir . '/.sugar-crush/workflows/.running', 0755, true);
        $pauseFile = $this->tempDir . '/.sugar-crush/workflows/.running/status-check-test.json';
        $pauseData = [
            'workflowId' => 'status-check-test',
            'workflowPath' => 'status-check-test',
            'status' => 'paused',
            'stagesCompleted' => 1,
            'context' => [],
            'stageResults' => [],
            'totalTokens' => 100,
            'totalCost' => 0.01,
            'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'pausedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
        file_put_contents($pauseFile, json_encode($pauseData));

        $status = $this->engine->getStatus('status-check-test');

        $this->assertSame(WorkflowStatus::Paused, $status);
    }

    /**
     * testRealSigtermMidWorkflowCapturesInFlightState (R28): forks a child
     * process that runs a REAL workflow through the real WorkflowEngine::run()
     * (no engine-level mocking — only the LLM-calling ExecutorInterface at
     * the very bottom is a stand-in, so no network calls happen in CI), then
     * the parent sends the child a genuine SIGTERM while it is blocked mid
     * second-stage execution. Asserts a real pause file materializes on disk
     * reflecting exactly the one stage that had genuinely finished — proving
     * the pcntl handlers registered around the stage-execution loop actually
     * fire on a real signal and capture real in-flight state, not a fabricated
     * one. Gated on pcntl availability; skips gracefully otherwise.
     */
    public function testRealSigtermMidWorkflowCapturesInFlightState(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix extensions are not available in this environment.');
        }

        $pauseFile = $this->tempDir . '/.sugar-crush/workflows/.running/real-sigterm-test.json';

        $pid = $this->forkTracked();
        if ($pid === -1) {
            $this->fail('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            // --- Child process ---
            // Build a fresh registry/engine locally (not $this->engine, which
            // uses the setUp() mock) and drive it through the REAL run()
            // entry point end-to-end. Only the bottom-most ExecutorInterface
            // is a stand-in for an actual LLM call, so this exercises the
            // genuine stage-execution loop, context bookkeeping, and the
            // real SIGTERM handler installed by WorkflowEngine itself.
            $registry = new WorkflowRegistry();
            $workflow = (new WorkflowBuilder())
                ->name('real-sigterm-test')
                ->description('Real interrupt test workflow')
                ->stage('quick', Tasks::agent('worker')->prompt('Quick step'))
                ->stage('slow', Tasks::agent('worker')->prompt('Slow step'))
                ->stage('never-reached', Tasks::agent('worker')->prompt('Should not run'))
                ->build();
            $registry->register($workflow);

            $executor = new class implements ExecutorInterface {
                private int $calls = 0;

                public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
                {
                    $this->calls++;
                    // Stage 1 ("quick") returns immediately. Stage 2 ("slow")
                    // sleeps long enough for the parent to deliver a real
                    // SIGTERM while this call is genuinely blocked.
                    if ($this->calls >= 2) {
                        sleep(10);
                    }

                    return new AgentResult(
                        agentId: $agent->id,
                        status: AgentStatus::Completed,
                        output: "output-{$this->calls}",
                        startedAt: new \DateTimeImmutable(),
                        completedAt: new \DateTimeImmutable(),
                    );
                }

                public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
                {
                    yield from [];
                }

                public function cancel(string $agentId): void
                {
                }

                public function cancelAll(): void
                {
                }
            };

            $pool = new AgentWorkerPool(5, $executor);
            $engine = new WorkflowEngine($registry, $pool);

            $engine->run('real-sigterm-test', []);

            // Should be unreachable: the real SIGTERM handler exits the
            // process from inside sleep(10) above, well before run() can
            // return. A distinct exit code here means the fix did NOT
            // actually interrupt execution.
            exit(99);
        }

        // --- Parent process ---
        // Give the child time to genuinely finish stage 1 and enter stage 2's
        // blocking sleep(10), then deliver a real SIGTERM.
        usleep(800_000);
        posix_kill($pid, SIGTERM);

        $status = null;
        pcntl_waitpid($pid, $status);

        $this->assertTrue(pcntl_wifexited($status), 'Child process should have exited normally after the real SIGTERM.');
        $this->assertNotSame(99, pcntl_wexitstatus($status), 'Child ran to completion instead of being genuinely interrupted mid-run.');
        $this->assertSame(143, pcntl_wexitstatus($status), 'Child should exit with the SIGTERM-convention code from the real interrupt handler.');

        $this->assertFileExists($pauseFile, 'A real SIGTERM mid-workflow should materialize a genuine pause file on disk.');

        $data = json_decode(file_get_contents($pauseFile), true);
        // Same two fields as the cooperative pause above, and asserted here
        // BECAUSE this is the interrupt path: the two used to be keyed
        // differently by the two code paths (run() by name, the SIGINT handler by
        // the generated ID), so which spelling `/workflow pause` accepted
        // depended on how the run had ended.
        $this->assertMatchesRegularExpression('/^real-sigterm-test-[0-9a-f]{8}$/', $data['workflowId']);
        $this->assertSame('real-sigterm-test', $data['workflowPath']);
        $this->assertSame('paused', $data['status']);
        $this->assertSame(1, $data['stagesCompleted'], 'Only the already-finished "quick" stage should be captured.');
        $this->assertSame('quick', $data['stageResults'][0]['stageName']);
        $this->assertSame('output-1', $data['context']['quick.output']);
        $this->assertArrayNotHasKey('slow.output', $data['context'], 'The in-flight "slow" stage must not appear as if it completed.');
    }

    /**
     * testForkedChildDoesNotRacePauseFileOnRealSignal (R28.fix): regression
     * test for a real fork/signal-inheritance race a reviewer found in the
     * original R28 fix. pcntl_signal() dispositions are inherited across
     * pcntl_fork() — if a real SIGTERM lands while a 'parallel' stage's
     * AgentWorkerPool has live forked children (see
     * AgentWorkerPool::startAgent()), every forked child independently
     * re-enters the SAME handler closure installed by
     * installInterruptHandlers() and, before this fix, would call pause()
     * too — racing an unsynchronized file write against the true parent.
     *
     * This drives installInterruptHandlers() directly (it's private) to
     * install the real signal handler in this test process, forks a real
     * child exactly the way AgentWorkerPool::startAgent() does, and
     * delivers a real SIGTERM to ONLY the forked child — never to this
     * process. Asserts the child still exits under the signal convention
     * (143), but critically never creates the pause file, proving the
     * getmypid() guard stops a forked child from calling pause() at all.
     */
    public function testForkedChildDoesNotRacePauseFileOnRealSignal(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix extensions are not available in this environment.');
        }

        $workflowId = 'fork-guard-test';
        $pauseFile = $this->tempDir . '/.sugar-crush/workflows/.running/' . $workflowId . '.json';

        $context = [];
        $stageResults = [];
        $totalTokens = 0;
        $totalCost = 0.0;
        $startedAt = new \DateTimeImmutable();

        $installMethod = new \ReflectionMethod(WorkflowEngine::class, 'installInterruptHandlers');
        $installMethod->setAccessible(true);
        $previousAsyncSignals = $installMethod->invokeArgs($this->engine, [
            $workflowId,
            $workflowId,
            // $loadPath: the registry name the interrupted run could be reloaded
            // from, threaded so a pause file taken by the signal handler records a
            // loadable `workflowPath` rather than whatever identifier the caller
            // happened to use.
            $workflowId,
            $startedAt,
            &$context,
            &$stageResults,
            &$totalTokens,
            &$totalCost,
        ]);

        $this->assertNotNull($previousAsyncSignals, 'Handlers should install successfully when pcntl is available.');

        try {
            $pid = $this->forkTracked();
            if ($pid === -1) {
                $this->fail('pcntl_fork() failed.');
            }

            if ($pid === 0) {
                // --- Forked child ---
                // Inherits the SIGTERM handler just installed above, exactly
                // as a real AgentWorkerPool worker child would inherit it
                // from mid-parallel-stage. Sleeps so the parent has time to
                // deliver a real signal before the child would exit on its
                // own.
                sleep(5);
                exit(98); // Unreachable if the signal is delivered as expected.
            }

            // --- Parent (this test process) ---
            // This is the same process that called installInterruptHandlers()
            // above, so getmypid() here matches the $installPid captured by
            // the handler closure. Signal only the forked CHILD, never
            // ourselves, so this process's own pause()/handler path is never
            // exercised here.
            usleep(200_000);
            posix_kill($pid, SIGTERM);

            $status = null;
            pcntl_waitpid($pid, $status);

            $this->assertTrue(pcntl_wifexited($status), 'Forked child should have exited normally after the real SIGTERM.');
            $this->assertSame(143, pcntl_wexitstatus($status), 'Forked child should still exit under the SIGTERM convention.');
            $this->assertFileDoesNotExist(
                $pauseFile,
                'A forked child must never call pause() itself — only the process that installed the handler may.'
            );
        } finally {
            $restoreMethod = new \ReflectionMethod(WorkflowEngine::class, 'restoreInterruptHandlers');
            $restoreMethod->setAccessible(true);
            $restoreMethod->invoke($this->engine, $previousAsyncSignals ?? false);
        }
    }

    /**
     * testAsyncSignalsSettingRestoredAfterRun (R28.fix): regression test for
     * a global-state leak a reviewer found in the original R28 fix.
     * installInterruptHandlers() unconditionally called pcntl_async_signals(true)
     * but restoreInterruptHandlers() never turned it back off, so every
     * run()/resume() permanently flipped the whole calling process (including
     * this very PHPUnit process) into async-signal-dispatch mode with no
     * corresponding cleanup. Sets a known baseline before run(), then asserts
     * run() restores exactly that baseline once it finishes normally.
     */
    public function testAsyncSignalsSettingRestoredAfterRun(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl extension is not available in this environment.');
        }

        $workflow = (new WorkflowBuilder())
            ->name('async-signals-restore-test')
            ->description('Test pcntl_async_signals is restored after run()')
            ->stage('only-stage', Tasks::agent('worker')->prompt('Do the only thing'))
            ->build();

        $this->registry->register($workflow);

        $this->mockExecutor
            ->expects($this->once())
            ->method('execute')
            ->willReturn($this->successfulAgentResult('done'));

        // Start from a known baseline so we prove run() puts it back to
        // exactly this value, not just that it happens to already match.
        $originalSetting = pcntl_async_signals(false);

        try {
            $result = $this->engine->run('async-signals-restore-test', []);
            $this->assertTrue($result->isSuccess());

            $this->assertFalse(
                pcntl_async_signals(),
                'run() must restore pcntl_async_signals() to what it was before the run, not leave async dispatch enabled process-wide.'
            );
        } finally {
            pcntl_async_signals($originalSetting);
        }
    }

    /**
     * testResumeMissingPauseFileThrows: verify resume() throws when no
     * pause file exists.
     */
    public function testResumeMissingPauseFileThrows(): void
    {
        $this->expectException(\SugarCraft\Crush\Workflows\WorkflowNotRunningException::class);
        $this->expectExceptionMessageMatches('/No paused workflow found/i');

        $this->engine->resume('nonexistent-pause-file');
    }

    /**
     * Recursively remove a directory and its contents.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

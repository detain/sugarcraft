<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\ExecutorInterface;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Support\ProcessTree;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowNotRunningException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * P2 orphaned-run reconciliation: a run whose writer process died mid-flight
 * (SIGKILL — no handler, no teardown) leaves an O1 crash record whose wire
 * `status` is still the paused claim it was written with. The engine now
 * stamps every record with the writer's pid (and its process start time when
 * procfs can answer), and status()/resume() JUDGE crash-class records at
 * query time: dead writer ⇒ {@see WorkflowStatus::Orphaned}, live writer
 * elsewhere ⇒ {@see WorkflowStatus::Running} with resume refused, so two
 * processes can never drive one run off one record.
 *
 * The paired truths this file pins are as load-bearing as the new class:
 * cooperative pauses ({@code pausedCleanly}) and legacy records (no pid at
 * all) keep reading exactly as they always did, and an orphaned record
 * resumes through the SAME replay path a cooperative pause uses — a killed
 * stage loses its partial work the same way pausing at a stage boundary
 * always did (WF-2 successful-prefix law).
 *
 * Real processes for the crash/cooperative/live legs (same harness shape as
 * WorkflowIncrementalPauseTest's killed-run test); hand-crafted records for
 * the classification arms that a real pid cannot conjure on demand (start
 * time mismatch, legacy shape, heartbeat edges via the pure helper).
 */
final class WorkflowOrphanedRunTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;
    private WorkflowEngine $engine;

    /** @var list<string> every prompt the executor was handed, in order */
    private array $prompts = [];

    /** @var array<string, \Closure(SubAgent): ?AgentResult> prompt prefix => behaviour override */
    private array $script = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_orphan_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
        $this->registry = new WorkflowRegistry($this->tempDir . '/workflows');

        $this->registry->register(
            (new WorkflowBuilder())
                ->name('three')
                ->description('a then b then c')
                ->stage('a', Tasks::agent('coder')->prompt('do a'))
                ->stage('b', Tasks::agent('coder')->prompt('do b using {{a.output}}'))
                ->stage('c', Tasks::agent('coder')->prompt('do c using {{b.output}}'))
                ->build(),
        );

        $test = $this;
        $executor = new class ($test) implements ExecutorInterface {
            public function __construct(private readonly WorkflowOrphanedRunTest $test)
            {
            }

            public function execute(SubAgent $subAgent, CompleteRequest $request): AgentResult
            {
                return $this->test->dispatch($subAgent);
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
            }
        };

        $this->engine = new WorkflowEngine($this->registry, new AgentWorkerPool(1, $executor));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /** @internal the fake executor's body; public only so the anonymous class can reach it */
    public function dispatch(SubAgent $subAgent): AgentResult
    {
        $this->prompts[] = $subAgent->task;

        foreach ($this->script as $prefix => $behaviour) {
            if (str_starts_with($subAgent->task, $prefix)) {
                $result = $behaviour($subAgent);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return self::succeeded($subAgent, 'OUT[' . $subAgent->task . ']');
    }

    // ---------------------------------------------------------------- crash

    public function testASigkilledRunReadsAsOrphanedAndResumesThroughThePausePath(): void
    {
        $this->requireRealProcesses();
        $process = $this->startCrashChild();
        $childPid = proc_get_status($process)['pid'];

        $record = null;
        try {
            $record = $this->waitForRecord($process);
        } finally {
            $this->signal($process, $childPid, \SIGKILL);
            proc_close($process);
        }

        // What SIGKILL actually leaves: the last O1 refresh, still claiming
        // 'paused' on the wire, carrying the dead writer's identity and NO
        // cooperative marker.
        $this->assertSame(1, $record['stagesCompleted']);
        $this->assertSame(WorkflowStatus::Paused->value, $record['status']);
        $this->assertSame($childPid, $record['pid'], 'the crash record must name the writer process');
        $this->assertArrayNotHasKey('pausedCleanly', $record, 'a crash write is not a cooperative stop');

        $this->assertSame(
            WorkflowStatus::Orphaned,
            $this->engine->status('three'),
            'a dead writer must read as orphaned, never as live and never as a chosen pause',
        );

        // The orphan resumes through the SAME replay a cooperative pause uses.
        $this->prompts = [];
        $resumed = $this->engine->resume('three');

        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertSame(
            ['do b using SAY[do a]', 'do c using OUT[do b using SAY[do a]]'],
            $this->prompts,
            'an orphaned record must skip exactly the stages it counted, like any pause',
        );
        $this->assertFileDoesNotExist($this->pauseFile());
    }

    public function testAnOrphanedRecordDoesNotBlockAFreshRun(): void
    {
        $this->requireProcfsStartTimes();

        // Hand-crafted crash record: this process's pid, a start time that
        // cannot be this pid's real one — the exact pid-reuse shape.
        $this->writeRecord($this->writerStampedRecord(deadLike: true));
        $this->assertSame(WorkflowStatus::Orphaned, $this->engine->status('three'));

        $this->prompts = [];
        $run = $this->engine->run('three');

        $this->assertSame(WorkflowStatus::Completed, $run->status);
        $this->assertSame(
            ['do a', 'do b using OUT[do a]', 'do c using OUT[do b using OUT[do a]]'],
            $this->prompts,
            'the one-live-run decision is in-memory; an on-disk orphan must never gate a fresh run',
        );
        $this->assertFileDoesNotExist($this->pauseFile(), 'the fresh run supersedes the orphan record');
    }

    // ----------------------------------------------------------- cooperative

    public function testASigtermPauseStaysPausedAfterTheWriterDies(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->markTestSkipped('the cooperative leg needs the child to run signal handlers');
        }
        $this->requireRealProcesses();
        $process = $this->startCrashChild();
        $childPid = proc_get_status($process)['pid'];

        try {
            $record = $this->waitForRecord($process);
            $this->assertSame($childPid, $record['pid']);

            $this->signal($process, $childPid, \SIGTERM);
            $deadline = microtime(true) + 10.0;
            while (microtime(true) < $deadline && proc_get_status($process)['running']) {
                usleep(50_000);
            }
            $this->assertFalse(
                proc_get_status($process)['running'],
                'the interrupt handler must take the cooperative exit',
            );
        } finally {
            if (proc_get_status($process)['running']) {
                posix_kill(proc_get_status($process)['pid'], \SIGKILL);
            }
            proc_close($process);
        }

        // The handler's write is cooperative: marker present. The writer is
        // now dead, and it STILL must read as paused — the marker wins, which
        // is what keeps this whole classification from relabelling honest
        // pauses as crashes just because the session closed.
        $survivor = $this->readRecord();
        $this->assertIsArray($survivor);
        $this->assertArrayHasKey('pausedCleanly', $survivor, 'a SIGTERM stop is cooperative');
        $this->assertSame(true, $survivor['pausedCleanly']);
        $this->assertSame($childPid, $survivor['pid']);

        $this->assertSame(WorkflowStatus::Paused, $this->engine->status('three'));

        $this->prompts = [];
        $resumed = $this->engine->resume('three');
        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertSame(
            ['do b using SAY[do a]', 'do c using OUT[do b using SAY[do a]]'],
            $this->prompts,
            'the orphan replay and the cooperative replay are the SAME path',
        );
    }

    // ---------------------------------------------------------- live writer

    public function testALiveWritersRecordReadsRunningAndRefusesResumeHere(): void
    {
        $this->requireRealProcesses();
        $this->requireProcfsStartTimes();
        $process = $this->startCrashChild();
        $childPid = proc_get_status($process)['pid'];

        try {
            $record = $this->waitForRecord($process);
            $this->assertSame($childPid, $record['pid']);
            $this->assertArrayNotHasKey('pausedCleanly', $record);

            // The other process is STILL writing this run's progress. On the
            // pre-P2 engine this same file read as 'paused' and a resume
            // here would have driven a second copy of the run concurrently.
            $this->assertSame(WorkflowStatus::Running, $this->engine->status('three'));

            $this->prompts = [];
            try {
                $this->engine->resume('three');
                $this->fail('a crash record whose writer is live elsewhere must refuse resume');
            } catch (WorkflowNotRunningException $refusal) {
                $this->assertStringContainsString('live in another process', $refusal->getMessage());
                $this->assertStringContainsString((string) $childPid, $refusal->getMessage());
            }
            $this->assertSame([], $this->prompts, 'the refusal must land before any stage replays');
        } finally {
            if (proc_get_status($process)['running']) {
                posix_kill(proc_get_status($process)['pid'], \SIGKILL);
            }
            proc_close($process);
        }

        // Same file, writer now reaped: the verdict flips to orphaned at the
        // next query — reconciliation is query-time, never a background poll.
        $this->assertSame(WorkflowStatus::Orphaned, $this->engine->status('three'));
    }

    public function testAPidReusedByAnotherProcessReadsOrphaned(): void
    {
        $this->requireProcfsStartTimes();

        $this->writeRecord($this->writerStampedRecord(deadLike: true));

        $this->assertSame(
            WorkflowStatus::Orphaned,
            $this->engine->status('three'),
            'a live pid whose start time cannot be the recorded one is a recycled pid',
        );
    }

    // --------------------------------------------------------------- legacy

    public function testALegacyRecordWithoutApidStillReadsPausedAndResumes(): void
    {
        $atom = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $this->writeRecord([
            'workflowId' => 'three-ffffffff',
            'workflowPath' => 'three',
            'status' => WorkflowStatus::Paused->value,
            'stagesCompleted' => 1,
            'context' => ['a.output' => 'OUT[do a]'],
            'stageResults' => [[
                'stageName' => 'a',
                'status' => WorkflowStatus::Completed->value,
                'output' => 'OUT[do a]',
                'error' => null,
                'agents' => [],
                'startedAt' => $atom,
                'completedAt' => $atom,
            ]],
            'totalTokens' => 10,
            'totalCost' => 0.5,
            'startedAt' => $atom,
            'pausedAt' => $atom,
        ]);

        $this->assertSame(
            WorkflowStatus::Paused,
            $this->engine->status('three'),
            'records written before P2 carry no pid and keep their exact old reading',
        );

        $this->prompts = [];
        $resumed = $this->engine->resume('three');
        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertSame(['do b using OUT[do a]', 'do c using OUT[do b using OUT[do a]]'], $this->prompts);
    }

    // ------------------------------------------------------- pure-helper arm

    public function testTheHeartbeatWindowBoundsTheNoProofFallback(): void
    {
        $this->assertSame(7200, WorkflowEngine::ORPHAN_HEARTBEAT_STALE_SECONDS);

        mkdir($this->tempDir . '/workflows/.running', 0755, true);
        $probe = $this->pauseFile();
        file_put_contents($probe, '{}');

        $classify = new \ReflectionMethod(WorkflowEngine::class, 'crashRecordIsOrphaned');
        $classify->setAccessible(true);
        /** @var callable(array<string,mixed>, string): bool $judge */
        $judge = static fn (array $data, string $file): bool => $classify->invoke(null, $data, $file);

        // This process is unmistakably alive and no start time was recorded,
        // so ONLY the heartbeat window can decide — the documented fallback.
        $data = ['status' => WorkflowStatus::Paused->value, 'pid' => \getmypid()];

        touch($probe, time() - (WorkflowEngine::ORPHAN_HEARTBEAT_STALE_SECONDS - 60));
        $this->assertFalse(
            $judge($data, $probe),
            'a live writer inside the heartbeat window is still writing',
        );

        touch($probe, time() - (WorkflowEngine::ORPHAN_HEARTBEAT_STALE_SECONDS + 60));
        $this->assertTrue(
            $judge($data, $probe),
            'a silent writer past the window without start-time proof is presumed gone',
        );

        // A start time that procfs CAN match removes the window from the
        // decision entirely, however stale the heartbeat reads.
        $observed = BackgroundSupervisor::procStartTime((int) \getmypid());
        if ($observed !== null) {
            touch($probe, time() - (WorkflowEngine::ORPHAN_HEARTBEAT_STALE_SECONDS + 600));
            $this->assertFalse(
                $judge($data + ['pidStartedAt' => $observed], $probe),
                'a verified writer is live regardless of the heartbeat',
            );
        }

        // A dead pid is orphaned regardless of a fresh heartbeat.
        touch($probe, time());
        $this->assertTrue($judge(['status' => WorkflowStatus::Paused->value, 'pid' => 999_999_999], $probe));
    }

    // =============================================================== plumbing

    /**
     * @return array<string, mixed>
     */
    private function writerStampedRecord(bool $deadLike): array
    {
        $this->requireProcfsStartTimes();
        $atom = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $record = [
            'workflowId' => 'three-ffffffff',
            'workflowPath' => 'three',
            'status' => WorkflowStatus::Paused->value,
            'stagesCompleted' => 1,
            'context' => ['a.output' => 'SAY[do a]'],
            'stageResults' => [[
                'stageName' => 'a',
                'status' => WorkflowStatus::Completed->value,
                'output' => 'SAY[do a]',
                'error' => null,
                'agents' => [],
                'startedAt' => $atom,
                'completedAt' => $atom,
            ]],
            'totalTokens' => 10,
            'totalCost' => 0.5,
            'startedAt' => $atom,
            'pausedAt' => $atom,
            'pid' => \getmypid(),
        ];

        // $deadLike: a start time this pid cannot own — the recycled-pid shape.
        $record['pidStartedAt'] = $deadLike ? 1 : BackgroundSupervisor::procStartTime((int) \getmypid());

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForRecord($process): array
    {
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            if (!proc_get_status($process)['running']) {
                $this->fail(
                    'the child exited before stage a was recorded: '
                    . (string) @file_get_contents($this->tempDir . '/child.out'),
                );
            }
            if (is_file($this->tempDir . '/ready') && is_file($this->pauseFile())) {
                $candidate = $this->readRecord();
                if ($candidate !== null && ($candidate['stagesCompleted'] ?? 0) >= 1) {
                    return $candidate;
                }
            }
            usleep(50_000);
        }

        $this->fail('the child never recorded stage a within 20s');
    }

    /** @return resource */
    private function startCrashChild()
    {
        $childScript = $this->tempDir . '/child.php';
        file_put_contents($childScript, $this->childProgram());
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $this->assertFileExists($autoload);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->tempDir . '/child.out', 'a'],
            2 => ['file', $this->tempDir . '/child.out', 'a'],
        ];
        $process = proc_open([PHP_BINARY, $childScript, $this->tempDir, $autoload], $descriptors, $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);

        return $process;
    }

    private function signal($process, int $pid, int $signal): void
    {
        if (proc_get_status($process)['running']) {
            posix_kill($pid, $signal);
        }
    }

    private function requireRealProcesses(): void
    {
        if (!function_exists('proc_open') || !function_exists('posix_kill')) {
            $this->markTestSkipped('the orphaned-run pin drives a real second process');
        }
    }

    private function requireProcfsStartTimes(): void
    {
        if (!ProcessTree::available() || BackgroundSupervisor::procStartTime((int) \getmypid()) === null) {
            $this->markTestSkipped('the pid-reuse proof needs procfs process start times');
        }
    }

    /**
     * A standalone program: registers the same three-stage workflow, runs it
     * with an executor that completes `do a`, then hangs forever on `do b`
     * after publishing a ready marker — the window the parent signals into.
     * Its own alarm is a second leash so a mis-parented child can never leak.
     */
    private function childProgram(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            require $argv[2];

            use SugarCraft\Code\Agents\AgentResult;
            use SugarCraft\Code\Agents\AgentStatus;
            use SugarCraft\Code\Agents\AgentWorkerPool;
            use SugarCraft\Code\Agents\ExecutorInterface;
            use SugarCraft\Code\Agents\SubAgent;
            use SugarCraft\Code\Providers\CompleteRequest;
            use SugarCraft\Code\Workflows\Tasks;
            use SugarCraft\Code\Workflows\WorkflowBuilder;
            use SugarCraft\Code\Workflows\WorkflowEngine;
            use SugarCraft\Code\Workflows\WorkflowRegistry;

            if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
                pcntl_async_signals(true);
                pcntl_signal(\SIGALRM, static function (): void {
                    exit(9);
                });
                pcntl_alarm(30);
            }

            $registry = new WorkflowRegistry($argv[1] . '/workflows');
            $registry->register(
                (new WorkflowBuilder())
                    ->name('three')
                    ->description('a then b then c')
                    ->stage('a', Tasks::agent('coder')->prompt('do a'))
                    ->stage('b', Tasks::agent('coder')->prompt('do b using {{a.output}}'))
                    ->stage('c', Tasks::agent('coder')->prompt('do c using {{b.output}}'))
                    ->build(),
            );

            $executor = new class ($argv[1]) implements ExecutorInterface {
                public function __construct(private readonly string $dir)
                {
                }

                public function execute(SubAgent $subAgent, CompleteRequest $request): AgentResult
                {
                    if (str_starts_with($subAgent->task, 'do b')) {
                        file_put_contents($this->dir . '/ready', 'x');
                        while (true) {
                            usleep(100_000);
                        }
                    }

                    return new AgentResult(
                        agentId: $subAgent->id,
                        status: AgentStatus::Completed,
                        output: 'SAY[' . $subAgent->task . ']',
                        tokensUsed: 10,
                        costUsd: 0.5,
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
                }
            };

            $engine = new WorkflowEngine($registry, new AgentWorkerPool(1, $executor));
            $engine->run('three');
            exit(0);
            PHP;
    }

    private function pauseFile(): string
    {
        return $this->tempDir . '/workflows/.running/three.json';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeRecord(array $data): void
    {
        if (!is_dir($this->tempDir . '/workflows/.running')) {
            mkdir($this->tempDir . '/workflows/.running', 0755, true);
        }
        file_put_contents($this->pauseFile(), (string) json_encode($data));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readRecord(): ?array
    {
        if (!is_file($this->pauseFile())) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($this->pauseFile()), true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function succeeded(SubAgent $subAgent, string $output): AgentResult
    {
        return new AgentResult(
            agentId: $subAgent->id,
            status: AgentStatus::Completed,
            output: $output,
            tokensUsed: 10,
            costUsd: 0.5,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

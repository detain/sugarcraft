<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
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
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * O1: the pause record is refreshed after EVERY completed stage of a
 * registry-backed run, not only at pause boundaries — so a SIGKILL or a dead
 * machine loses at most the in-flight stage, and the record is withdrawn at
 * every clean terminal (completed / failed / cancelled with no pause
 * standing), keeping the old "an undisturbed run leaves no file" contract
 * byte-for-byte.
 *
 * The wire shape is deliberately unchanged, so records written by the
 * pre-O1 engine (one writer, {@see WorkflowEngine}'s pause paths) resume
 * exactly as before — pinned both by this file's older-shaped record test and
 * by the untouched WorkflowPauseResumeTest family.
 */
final class WorkflowIncrementalPauseTest extends TestCase
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

        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_incr_' . uniqid('', true);
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
            public function __construct(private readonly WorkflowIncrementalPauseTest $test)
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

    private function pauseFile(): string
    {
        return $this->tempDir . '/workflows/.running/three.json';
    }

    /**
     * Read the resilience record while a run is live (or hand a missing-file
     * verdict back to the caller to assert on). Decoded as an array, or null
     * when the file does not exist.
     *
     * @return array<string, mixed>|null
     */
    private function readRecord(): ?array
    {
        $file = $this->pauseFile();
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    public function testTheRecordCountsEveryStageAsSoonAsItCompletes(): void
    {
        $test = $this;
        $seenDuringB = null;
        $this->script['do b'] = static function (SubAgent $agent) use ($test, &$seenDuringB): ?AgentResult {
            $seenDuringB = $test->recordWhileRunning();

            return null;
        };

        $run = $this->engine->run('three');

        $this->assertSame(WorkflowStatus::Completed, $run->status);
        $this->assertIsArray($seenDuringB, 'stage b dispatch must have found the record on disk (O1)');
        $this->assertSame(
            1,
            $seenDuringB['stagesCompleted'],
            'stage a must already be counted while stage b is in flight (O1)',
        );
        $this->assertSame(['a'], array_column($seenDuringB['stageResults'], 'stageName'));
        $this->assertSame('OUT[do a]', $seenDuringB['context']['a.output']);

        // The withdrawal contract: a clean completed run leaves nothing.
        $this->assertFileDoesNotExist($this->pauseFile());
        $this->assertSame(['do a', 'do b using OUT[do a]', 'do c using OUT[do b using OUT[do a]]'], $this->prompts);
    }

    /** @internal raw record read for executor closures; no assertions inside the run */
    public function recordWhileRunning(): ?array
    {
        return $this->readRecord();
    }

    public function testTheRecordCarriesStablePerStageIdentityHashes(): void
    {
        $test = $this;
        $seenAtB = null;
        $seenAtC = null;
        $this->script['do b'] = static function (SubAgent $agent) use ($test, &$seenAtB): ?AgentResult {
            $seenAtB = $test->recordWhileRunning();

            return null;
        };
        $this->script['do c'] = static function (SubAgent $agent) use ($test, &$seenAtC): ?AgentResult {
            $seenAtC = $test->recordWhileRunning();

            return null;
        };

        $run = $this->engine->run('three');
        $this->assertSame(WorkflowStatus::Completed, $run->status);

        $this->assertIsArray($seenAtB);
        $hashA = $seenAtB['stageInputHashes']['a'] ?? null;
        $this->assertIsString($hashA, 'a settled stage must carry its input identity (C13 substrate)');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hashA);
        $this->assertSame(['a'], array_keys($seenAtB['stageInputHashes']), 'only settled stages are hashed');

        $this->assertIsArray($seenAtC);
        $this->assertSame(['a', 'b'], array_keys($seenAtC['stageInputHashes']));
        $this->assertSame($hashA, $seenAtC['stageInputHashes']['a'], 'the hash is stable for the same declaration');

        // The recipe, pinned so the future cache consumer and this writer
        // cannot drift apart silently: sha256 over the canonical
        // pre-interpolation material.
        $this->assertSame(
            hash('sha256', (string) json_encode([
                'type' => 'stage',
                'tasks' => [['prompt' => 'do a', 'model' => null, 'schema' => null]],
            ], JSON_UNESCAPED_SLASHES)),
            $hashA,
            'the stageInputHash recipe drifted — C13 consumers pin exactly this shape',
        );
    }

    public function testAnOlderRecordWithoutHashesResumesAndHashesOnlyWhatItRunsHere(): void
    {
        // Same hand-written pre-O1, pre-C13 record as the compatibility pin
        // above — the resumed run must seed from nothing, skip stage a on
        // the recorded count, and carry forward hashes only for stages THIS
        // process settled. Absence for a is a cache miss by law, never
        // corruption.
        mkdir($this->tempDir . '/workflows/.running', 0755, true);
        $atom = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        file_put_contents(
            $this->pauseFile(),
            (string) json_encode([
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
            ]),
        );

        $test = $this;
        $seenAtC = null;
        $this->script['do c'] = static function (SubAgent $agent) use ($test, &$seenAtC): ?AgentResult {
            $seenAtC = $test->recordWhileRunning();

            return null;
        };

        $resumed = $this->engine->resume('three');

        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertIsArray($seenAtC);
        $this->assertSame(
            ['b'],
            array_keys($seenAtC['stageInputHashes'] ?? []),
            'the resumed run hashes only stages it settled itself — a skipped pre-C13 stage stays a cache miss',
        );
    }

    public function testAFailedRunWithdrawsTheRecordItWasKeeping(): void
    {
        $test = $this;
        $seenDuringB = null;
        $this->script['do b'] = static function (SubAgent $agent) use ($test, &$seenDuringB): AgentResult {
            $seenDuringB = $test->recordWhileRunning();

            return new AgentResult(
                agentId: $agent->id,
                status: AgentStatus::Failed,
                error: new \RuntimeException('b broke'),
                tokensUsed: 7,
                costUsd: 0.25,
                startedAt: new \DateTimeImmutable(),
                completedAt: new \DateTimeImmutable(),
            );
        };

        $run = $this->engine->run('three');

        $this->assertSame(WorkflowStatus::Failed, $run->status);
        $this->assertIsArray($seenDuringB, 'the failing stage must still have seen its prefix counted');
        $this->assertSame(1, $seenDuringB['stagesCompleted']);

        // The precedence pin: a leftover file would make status() report the
        // failed run as PAUSED — withdrawal keeps Failed readable.
        $this->assertFileDoesNotExist($this->pauseFile());
        $this->assertSame(WorkflowStatus::Failed, $this->engine->status('three'));
    }

    public function testAnOlderShapedRecordStillResumes(): void
    {
        // Hand-written exactly in the PRE-O1 wire shape (the only writer was a
        // pause boundary) — the incremental refresh changed nothing a resume
        // reads.
        mkdir($this->tempDir . '/workflows/.running', 0755, true);
        $atom = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        file_put_contents(
            $this->pauseFile(),
            (string) json_encode([
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
                    'agents' => [[
                        'agentId' => 'a-1',
                        'status' => AgentStatus::Completed->value,
                        'output' => 'OUT[do a]',
                        'error' => null,
                        'tokensUsed' => 10,
                        'costUsd' => 0.5,
                        'startedAt' => $atom,
                        'completedAt' => $atom,
                        'attempts' => 1,
                    ]],
                    'startedAt' => $atom,
                    'completedAt' => $atom,
                ]],
                'totalTokens' => 10,
                'totalCost' => 0.5,
                'startedAt' => $atom,
                'pausedAt' => $atom,
            ]),
        );

        $resumed = $this->engine->resume('three');

        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertSame(
            ['do b using OUT[do a]', 'do c using OUT[do b using OUT[do a]]'],
            $this->prompts,
            'a pre-O1 record must skip exactly the stages it counts',
        );
        $this->assertFileDoesNotExist($this->pauseFile());
    }

    public function testAKilledRunResumesWithoutReRunningRecordedStages(): void
    {
        if (!function_exists('proc_open') || !function_exists('posix_kill')) {
            $this->markTestSkipped('the crash-resilience pin drives a real second process');
        }

        $childScript = $this->tempDir . '/child.php';
        file_put_contents($childScript, $this->childProgram());
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $this->assertFileExists($autoload, 'the crash-resilience pin bootstraps the package autoloader');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->tempDir . '/child.out', 'a'],
            2 => ['file', $this->tempDir . '/child.out', 'a'],
        ];
        $process = proc_open([PHP_BINARY, $childScript, $this->tempDir, $autoload], $descriptors, $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);

        try {
            $deadline = microtime(true) + 20.0;
            $record = null;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $this->fail(
                        'the killed-run child exited before stage a was recorded: '
                        . (string) @file_get_contents($this->tempDir . '/child.out'),
                    );
                }
                if (is_file($this->tempDir . '/ready') && is_file($this->pauseFile())) {
                    $candidate = $this->readRecord();
                    if ($candidate !== null && ($candidate['stagesCompleted'] ?? 0) >= 1) {
                        $record = $candidate;
                        break;
                    }
                }
                usleep(50_000);
            }

            $this->assertIsArray($record, 'stage a must be recorded while stage b is still in flight (O1)');

            // The kill the record is built for: SIGKILL, no handler runs, no
            // teardown gets a turn.
            posix_kill(proc_get_status($process)['pid'], \SIGKILL);
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                posix_kill($status['pid'], \SIGKILL);
            }
            proc_close($process);
        }

        // The record survived the death of its writer — the pre-O1 engine had
        // written nothing yet at this point in the run.
        $survivor = $this->readRecord();
        $this->assertIsArray($survivor, 'a SIGKILL must not lose the recorded prefix');
        $this->assertSame(1, $survivor['stagesCompleted']);
        $this->assertSame(['a'], array_column($survivor['stageResults'], 'stageName'));
        $this->assertSame('SAY[do a]', $survivor['context']['a.output']);

        // Fresh-process resume in THIS process: stage a is skipped, the rest
        // replays with the recorded output interpolated.
        $this->prompts = [];
        $resumed = $this->engine->resume('three');

        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertSame(
            ['do b using SAY[do a]', 'do c using OUT[do b using SAY[do a]]'],
            $this->prompts,
            'the recorded stage must not re-run after a crash',
        );
        $this->assertFileDoesNotExist($this->pauseFile());
    }

    /**
     * A standalone program: registers the same three-stage workflow, runs it
     * with an executor that completes `do a`, then hangs forever on `do b`
     * after publishing a ready marker — the window the parent SIGKILLs into.
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

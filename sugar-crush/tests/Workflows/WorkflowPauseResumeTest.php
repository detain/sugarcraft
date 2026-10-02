<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

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
use SugarCraft\Crush\Workflows\WorkflowNotRunningException;
use SugarCraft\Crush\Workflows\WorkflowRegistry;
use SugarCraft\Crush\Workflows\WorkflowResult;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * AUDIT WF-2: `/workflow pause` could not pause a running workflow; resuming a
 * FAILED run skipped the stage that failed and reported success; the pause file
 * was never cleared.
 *
 * Measured before the fix with the audit's repro (a three-stage `a → b → c`
 * workflow whose `b` fails on the first run):
 *
 *     run1: failed stages=2
 *     status after pause: paused
 *     resume: completed stages=c tokensTotal=0
 *     prompts run on resume: ["do c using "]   <- b skipped; {{b.output}} empty
 *     status after resume: paused               <- pause file left behind
 *     second resume re-runs: ["do c using "]   <- resumable forever
 *
 * Every test drives the real engine with a fake {@see ExecutorInterface} whose
 * per-prompt behaviour is scripted, and a "live" pause is a {@see
 * WorkflowEngine::pause()} call made FROM INSIDE that executor — the run's stage
 * loop is on the stack below it, which is exactly the state `Chat`'s workflow
 * fiber is in when `/workflow pause` reaches the engine between agent polls.
 */
final class WorkflowPauseResumeTest extends TestCase
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

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_wf_pause_' . uniqid('', true);
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
            public function __construct(private readonly WorkflowPauseResumeTest $test)
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

    /** b fails, having spent 7 tokens / $0.25 doing so. */
    private function failB(): void
    {
        $this->script['do b'] = static fn (SubAgent $a): AgentResult => new AgentResult(
            agentId: $a->id,
            status: AgentStatus::Failed,
            error: new \RuntimeException('b broke'),
            tokensUsed: 7,
            costUsd: 0.25,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    /** Pause $identifier while the stage whose prompt starts with $prefix is running. */
    private function pauseDuring(string $prefix, string $identifier, ?WorkflowStatus &$statusBefore = null): void
    {
        $engine = $this->engine;
        $this->script[$prefix] = static function () use ($engine, $identifier, &$statusBefore): ?AgentResult {
            $statusBefore = $engine->getStatus($identifier);
            $engine->pause($identifier);

            return null;
        };
    }

    private function pauseFile(): string
    {
        return $this->tempDir . '/workflows/.running/three.json';
    }

    /** @return array<string, mixed> */
    private function pauseData(): array
    {
        $this->assertFileExists($this->pauseFile());
        $data = json_decode((string) file_get_contents($this->pauseFile()), true);
        $this->assertIsArray($data);

        return $data;
    }

    /** @return list<string> */
    private static function stageNames(WorkflowResult $result): array
    {
        return array_map(static fn ($s): string => $s->stageName, $result->stageResults);
    }

    /** A failed run that is paused and fixed. */
    private function failedThenPaused(): string
    {
        $this->failB();
        $run = $this->engine->run('three');
        $this->assertSame(WorkflowStatus::Failed, $run->status);
        $this->engine->pause($run->workflowId);
        unset($this->script['do b']);
        $this->prompts = [];

        return $run->workflowId;
    }

    public function testPausingAFailedRunCountsAndPersistsOnlyTheStagesThatSucceeded(): void
    {
        $this->failB();
        $run = $this->engine->run('three');
        $this->assertSame(['a', 'b'], self::stageNames($run));

        $this->engine->pause($run->workflowId);

        $data = $this->pauseData();
        $this->assertSame(1, $data['stagesCompleted'], 'the failed stage b must not count as completed');
        $this->assertSame(['a'], array_column($data['stageResults'], 'stageName'));
        // The spend is the run's whole spend: the failed attempt cost real tokens.
        $this->assertSame(17, $data['totalTokens']);
    }

    public function testResumingAFailedRunReRunsTheFailedStageAndFeedsItsOutputDownstream(): void
    {
        $id = $this->failedThenPaused();

        $resumed = $this->engine->resume($id);

        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertSame(
            ['do b using OUT[do a]', 'do c using OUT[do b using OUT[do a]]'],
            $this->prompts,
            'resume must re-run b (not skip it) and must not re-run a',
        );
        $this->assertSame('OUT[do b using OUT[do a]]', $resumed->context['b.output']);
        $this->assertSame($id, $resumed->workflowId);
    }

    public function testTheResumedResultIsAbsoluteAndCarriesThePausedRunsSpend(): void
    {
        $id = $this->failedThenPaused();

        $resumed = $this->engine->resume($id);

        $this->assertSame(['a', 'b', 'c'], self::stageNames($resumed));
        // a 10 + failed b 7 on the first run, then b 10 + c 10 on the resume.
        $this->assertSame(37, $resumed->totalTokens);
        $this->assertEqualsWithDelta(1.75, $resumed->totalCost, 1e-9);
    }

    public function testResumeConsumesThePauseFileSoStatusMovesOnAndASecondResumeIsRefused(): void
    {
        $id = $this->failedThenPaused();
        $this->assertSame(WorkflowStatus::Paused, $this->engine->getStatus($id));

        $this->engine->resume($id);

        $this->assertFileDoesNotExist($this->pauseFile());
        $this->assertSame(WorkflowStatus::Completed, $this->engine->getStatus($id));
        $this->assertSame(WorkflowStatus::Completed, $this->engine->getStatus('three'));

        $this->prompts = [];
        try {
            $this->engine->resume($id);
            $this->fail('a second resume of a finished run must be refused');
        } catch (WorkflowNotRunningException) {
            $this->assertSame([], $this->prompts, 'the refused resume must not re-run the tail');
        }
    }

    public function testAResumeThatFailsAgainAlsoConsumesTheFileAndCanBePausedAgain(): void
    {
        $id = $this->failedThenPaused();
        $this->failB();

        $again = $this->engine->resume($id);

        $this->assertSame(WorkflowStatus::Failed, $again->status);
        $this->assertSame(['a', 'b'], self::stageNames($again));
        $this->assertFileDoesNotExist($this->pauseFile());
        $this->assertSame(WorkflowStatus::Failed, $this->engine->getStatus($id));

        // Pause → fix → resume, a second time round.
        $this->engine->pause($id);
        $this->assertSame(1, $this->pauseData()['stagesCompleted']);
        unset($this->script['do b']);
        $this->prompts = [];

        $this->assertSame(WorkflowStatus::Completed, $this->engine->resume($id)->status);
        $this->assertCount(2, $this->prompts);
    }

    /**
     * The live pause: requested while stage a is running, it lets a finish and
     * stops the run before b starts.
     */
    public function testALivePauseStopsTheRunBeforeTheNextStage(): void
    {
        $this->pauseDuring('do a', 'three', $statusBefore);

        $run = $this->engine->run('three');

        $this->assertSame(WorkflowStatus::Running, $statusBefore, 'a live run reports Running');
        $this->assertSame(WorkflowStatus::Paused, $run->status);
        $this->assertSame(['do a'], $this->prompts, 'b and c must not start once a pause is requested');
        $this->assertSame(['a'], self::stageNames($run));

        $data = $this->pauseData();
        $this->assertSame(1, $data['stagesCompleted'], 'the file is rewritten with the stage that finished');
        $this->assertSame($run->workflowId, $data['workflowId']);
        $this->assertSame(WorkflowStatus::Paused, $this->engine->getStatus($run->workflowId));

        unset($this->script['do a']);
        $this->prompts = [];
        $resumed = $this->engine->resume($run->workflowId);

        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertSame(['do b using OUT[do a]', 'do c using OUT[do b using OUT[do a]]'], $this->prompts);
        $this->assertSame(30, $resumed->totalTokens);
        $this->assertFileDoesNotExist($this->pauseFile());
    }

    /** The pause file exists the moment the live pause is requested, not only once the stage ends. */
    public function testALivePauseWritesThePauseFileImmediately(): void
    {
        $seen = null;
        $engine = $this->engine;
        $file = $this->pauseFile();
        $this->script['do b'] = static function () use ($engine, $file, &$seen): ?AgentResult {
            $engine->pause('three');
            $seen = json_decode((string) file_get_contents($file), true);

            return null;
        };

        $this->engine->run('three');

        $this->assertIsArray($seen);
        $this->assertSame('paused', $seen['status']);
        $this->assertSame(1, $seen['stagesCompleted'], 'only a had finished when the pause was requested');
    }

    /** A resumed run is itself pausable, by the printed run ID, and its counts stay absolute. */
    public function testAResumedRunCanBeLivePausedByItsRunIdAndResumedAgain(): void
    {
        $this->pauseDuring('do a', 'three');
        $id = $this->engine->run('three')->workflowId;
        unset($this->script['do a']);

        $this->pauseDuring('do b', $id, $statusBefore);
        $second = $this->engine->resume($id);

        $this->assertSame(WorkflowStatus::Running, $statusBefore, 'the resumed run is live, not "paused" from its file');
        $this->assertSame(WorkflowStatus::Paused, $second->status);
        $this->assertSame(['a', 'b'], self::stageNames($second));
        $this->assertSame(2, $this->pauseData()['stagesCompleted'], 'the count spans both legs of the run');

        unset($this->script['do b']);
        $this->prompts = [];
        $third = $this->engine->resume($id);

        $this->assertSame(WorkflowStatus::Completed, $third->status);
        $this->assertSame(['do c using OUT[do b using OUT[do a]]'], $this->prompts);
        $this->assertSame(['a', 'b', 'c'], self::stageNames($third));
        $this->assertSame(30, $third->totalTokens);
        $this->assertFileDoesNotExist($this->pauseFile());
    }

    /** A pause requested during the LAST stage has nothing left to stop. */
    public function testALivePauseDuringTheLastStageLetsTheRunCompleteAndWithdrawsTheFile(): void
    {
        $this->pauseDuring('do c', 'three');

        $run = $this->engine->run('three');

        $this->assertSame(WorkflowStatus::Completed, $run->status);
        $this->assertFileDoesNotExist($this->pauseFile());
        $this->assertSame(WorkflowStatus::Completed, $this->engine->getStatus($run->workflowId));
    }

    /** The stage in flight fails after the pause was requested: the run failed, and the pause stands. */
    public function testALivePauseWhoseInFlightStageFailsReportsFailedAndStaysResumable(): void
    {
        $engine = $this->engine;
        $this->script['do b'] = static function (SubAgent $a) use ($engine): AgentResult {
            $engine->pause('three');

            return new AgentResult(agentId: $a->id, status: AgentStatus::Failed, error: new \RuntimeException('b broke'));
        };

        $run = $this->engine->run('three');

        $this->assertSame(WorkflowStatus::Failed, $run->status);
        $this->assertSame(1, $this->pauseData()['stagesCompleted']);

        unset($this->script['do b']);
        $this->prompts = [];
        $resumed = $this->engine->resume($run->workflowId);

        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertSame('do b using OUT[do a]', $this->prompts[0]);
    }

    /** status answers for finished runs that were never paused, instead of "not found". */
    public function testStatusOfAFinishedRunThatWasNeverPausedIsItsFinalStatus(): void
    {
        $ok = $this->engine->run('three');
        $this->assertSame(WorkflowStatus::Completed, $this->engine->getStatus($ok->workflowId));

        $this->failB();
        $failed = $this->engine->run('three');
        $this->assertSame(WorkflowStatus::Failed, $this->engine->getStatus($failed->workflowId));
        $this->assertSame(WorkflowStatus::Failed, $this->engine->getStatus('three'));

        $this->expectException(WorkflowNotRunningException::class);
        $this->engine->getStatus('never-ran');
    }

    /** Pausing a completed run stays legal; resuming it runs nothing and consumes the file. */
    public function testPausingACompletedRunRecordsItAndResumingItRunsNothing(): void
    {
        $run = $this->engine->run('three');
        $this->engine->pause($run->workflowId);
        $this->assertSame(3, $this->pauseData()['stagesCompleted']);
        $this->prompts = [];

        $resumed = $this->engine->resume($run->workflowId);

        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertSame([], $this->prompts);
        $this->assertSame(['a', 'b', 'c'], self::stageNames($resumed));
        $this->assertSame(30, $resumed->totalTokens);
        $this->assertFileDoesNotExist($this->pauseFile());
    }

    /**
     * A pause file written BEFORE this fix counted the failed stage in
     * `stagesCompleted`. Its recorded stage results still show which one failed,
     * so it resumes from there instead of skipping it.
     */
    public function testAPreFixPauseFileThatCountedTheFailedStageResumesFromThatStage(): void
    {
        mkdir(dirname($this->pauseFile()), 0700, true);
        $at = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $stage = static fn (string $name, string $status, ?string $error): array => [
            'stageName' => $name,
            'status' => $status,
            'output' => $error === null ? 'OUT[do a]' : '',
            'error' => $error,
            'agents' => [],
            'startedAt' => $at,
            'completedAt' => $at,
        ];
        file_put_contents($this->pauseFile(), (string) json_encode([
            'workflowId' => 'three-0badc0de',
            'workflowPath' => 'three',
            'status' => 'paused',
            'stagesCompleted' => 2,
            'context' => ['a.output' => 'OUT[do a]', 'b.output' => ''],
            'stageResults' => [$stage('a', 'completed', null), $stage('b', 'failed', 'b broke')],
            'totalTokens' => 17,
            'totalCost' => 0.75,
            'startedAt' => $at,
        ]));

        $resumed = $this->engine->resume('three-0badc0de');

        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertSame(['do b using OUT[do a]', 'do c using OUT[do b using OUT[do a]]'], $this->prompts);
        $this->assertSame(37, $resumed->totalTokens);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}

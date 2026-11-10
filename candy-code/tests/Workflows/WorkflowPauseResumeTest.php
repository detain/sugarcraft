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
use SugarCraft\Code\Workflows\WorkflowNotRunningException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowResult;
use SugarCraft\Code\Workflows\WorkflowStatus;

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

        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_pause_' . uniqid('', true);
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

    /**
     * C2: a skip is a settled fact. Stage b's `when` fails, so b never runs; the
     * pause during c must still count b in `stagesCompleted` and persist its
     * Skipped row, and the resume must dispatch ONLY d — b is never re-decided,
     * because a skip re-evaluated under outputs that changed since the file was
     * written could run a stage whose guard said no.
     */
    public function testASkippedStageSurvivesThePauseFileAndIsNeverReDecidedOnResume(): void
    {
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('skips')
                ->description('a, skipped b, c, d')
                ->stage('a', Tasks::agent('coder')->prompt('do a'))
                ->stage('b', Tasks::agent('coder')->prompt('do b using {{a.output}}'))
                ->when(['stage' => 'a', 'contains' => 'NEED-B'])
                ->stage('c', Tasks::agent('coder')->prompt('do c using {{b.output}}'))
                ->stage('d', Tasks::agent('coder')->prompt('do d using {{c.output}}'))
                ->build(),
        );

        $this->pauseDuring('do c', 'skips');
        $run = $this->engine->run('skips');

        $this->assertSame(WorkflowStatus::Paused, $run->status);
        // b was never dispatched; d was never started.
        $this->assertSame(['do a', 'do c using {{b.output}}'], $this->prompts);
        // The paused snapshot carries the skip row settled between the runners.
        $this->assertSame(['a', 'b', 'c'], self::stageNames($run));
        $this->assertSame(
            [WorkflowStatus::Completed, WorkflowStatus::Skipped, WorkflowStatus::Completed],
            array_map(static fn ($s): WorkflowStatus => $s->status, $run->stageResults),
        );

        $file = $this->tempDir . '/workflows/.running/skips.json';
        $this->assertFileExists($file);
        $data = json_decode((string) file_get_contents($file), true);
        $this->assertIsArray($data);
        // Without the settled-prefix fix this would count only stages a and c (2).
        $this->assertSame(3, $data['stagesCompleted']);
        $this->assertSame(['a', 'b', 'c'], array_column($data['stageResults'], 'stageName'));
        $this->assertSame(['completed', 'skipped', 'completed'], array_column($data['stageResults'], 'status'));

        $this->prompts = [];
        $resumed = $this->engine->resume($run->workflowId);

        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        // Exactly one dispatch on resume: d. b never runs and is never re-decided.
        $this->assertSame(['do d using OUT[do c using {{b.output}}]'], $this->prompts);
        $this->assertSame(['a', 'b', 'c', 'd'], self::stageNames($resumed));
        $this->assertSame(
            [
                WorkflowStatus::Completed,
                WorkflowStatus::Skipped,
                WorkflowStatus::Completed,
                WorkflowStatus::Completed,
            ],
            array_map(static fn ($s): WorkflowStatus => $s->status, $resumed->stageResults),
        );
        $this->assertFileDoesNotExist($file);
    }

    // -------------------------------------------------------------------------
    // C8: a "from:" fan-out replays its recorded list, never a fresh glob
    // -------------------------------------------------------------------------

    public function testAResumedFanOutReplaysTheRecordedListInsteadOfReGlobbing(): void
    {
        $envRoot = $this->tempDir . '/fanenv';
        mkdir($envRoot, 0755, true);
        file_put_contents($envRoot . '/a.txt', 'one');
        file_put_contents($envRoot . '/b.txt', 'two');

        $this->registerFanOut();
        $engine = $this->fanOutEngine($envRoot);

        // The wave fails mid-stage — but a live pause lands first, so the file
        // is written while the run still holds its materialised list.
        $this->script['fix b'] = static function (SubAgent $a) use ($engine): AgentResult {
            $engine->pause('fan');

            return new AgentResult(
                agentId: $a->id,
                status: AgentStatus::Failed,
                error: new \RuntimeException('b broke'),
                tokensUsed: 1,
                costUsd: 0.1,
                startedAt: new \DateTimeImmutable(),
                completedAt: new \DateTimeImmutable(),
            );
        };

        $run = $engine->run('fan');
        $this->assertSame(WorkflowStatus::Failed, $run->status);

        $data = $this->fanOutPauseData();
        $this->assertSame(['a.txt', 'b.txt'], $data['dynamicWaves']['sweep']['paths'], 'the pause record carries the materialised list');
        $this->assertSame(2, $data['dynamicWaves']['sweep']['chunk'], 'and the chunk it expanded under');
        $this->assertSame(1, $data['stagesCompleted'], 'the failed stage must not count as completed');

        // The tree changes under the record — a fresh glob could only answer
        // differently, and answering differently would fork different work.
        unlink($envRoot . '/a.txt');
        file_put_contents($envRoot . '/z.txt', 'late');
        unset($this->script['fix b']);
        $this->prompts = [];

        $resumed = $engine->resume($run->workflowId);

        $this->assertSame(WorkflowStatus::Completed, $resumed->status, (string) $resumed->firstFailure()?->error);
        $this->assertSame(
            ['fix a.txt', 'fix b.txt', 'ship the sweep'],
            $this->prompts,
            'the failed stage re-ran from the RECORDED names — a.txt is replayed without its file, z.txt never joins',
        );
    }

    public function testACorruptWaveRecordIsRefusedLoudlyWithoutAnyExpansion(): void
    {
        $envRoot = $this->tempDir . '/fanenv-live';
        mkdir($envRoot, 0755, true);
        file_put_contents($envRoot . '/a.txt', 'one');

        $this->registerFanOut();

        $at = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        mkdir($this->tempDir . '/workflows/.running', 0700, true);
        file_put_contents($this->tempDir . '/workflows/.running/fan.json', (string) json_encode([
            'workflowId' => 'fan-0badc0de',
            'workflowPath' => 'fan',
            'status' => 'paused',
            'stagesCompleted' => 1,
            'context' => ['pre.output' => 'OUT[do pre]'],
            'stageResults' => [[
                'stageName' => 'pre',
                'status' => 'completed',
                'output' => 'OUT[do pre]',
                'error' => null,
                'agents' => [],
                'startedAt' => $at,
                'completedAt' => $at,
            ]],
            'dynamicWaves' => ['sweep' => ['paths' => 'not-a-list']],
            'totalTokens' => 10,
            'totalCost' => 0.5,
            'startedAt' => $at,
        ]));

        $resumed = $this->fanOutEngine($envRoot)->resume('fan-0badc0de');

        $this->assertSame(WorkflowStatus::Failed, $resumed->status);
        $this->assertSame('sweep', $resumed->firstFailure()?->stageName);
        $this->assertStringContainsString('cannot replay', (string) $resumed->firstFailure()?->error);
        $this->assertSame([], $this->prompts, 'a corrupt record is refused — the engine must not silently re-glob over it');
        $this->assertFileDoesNotExist($this->tempDir . '/workflows/.running/fan.json');
    }

    private function registerFanOut(): void
    {
        // chr(42)-assembled glob: a bare wildcard literal would be harvested
        // into the GlobDialect corpus (house law).
        $star = chr(42);
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('fan')
                ->stage('pre', Tasks::agent('coder')->prompt('do pre'))
                ->parallel('sweep', [Tasks::agent('coder')->prompt('fix {{path}}')], from: ['glob' => $star . '.txt', 'chunk' => 2])
                ->stage('tail', Tasks::agent('coder')->prompt('ship the sweep'))
                ->build(),
        );
    }

    private function fanOutEngine(string $envRoot): WorkflowEngine
    {
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

        return new WorkflowEngine($this->registry, new AgentWorkerPool(1, $executor), environmentRoot: $envRoot);
    }

    /** @return array<string, mixed> */
    private function fanOutPauseData(): array
    {
        $file = $this->tempDir . '/workflows/.running/fan.json';
        $this->assertFileExists($file);
        $data = json_decode((string) file_get_contents($file), true);
        $this->assertIsArray($data);

        return $data;
    }

    public function testALoopedStageFinishesItsRoundsBeforeALivePauseIsObserved(): void
    {
        // C4 disclosure: the pause check sits at the stage boundary, so a
        // live pause requested mid-loop is honoured only once the looped
        // stage has settled its final round — rounds are not checkpointed
        // individually, and the pause record sees the stage as one row.
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('three')
                ->description('a, then a looping fix, then z')
                ->stage('a', Tasks::agent('coder')->prompt('do a'))
                ->stage('fix', Tasks::agent('coder')->prompt('do fix round {{round}}'))
                ->loop(['max' => 2, 'until' => ['stage' => 'fix', 'contains' => 'ALL DONE']])
                ->stage('z', Tasks::agent('coder')->prompt('do z using {{fix.output}}'))
                ->build(),
        );
        $this->pauseDuring('do fix', 'three');

        $run = $this->engine->run('three');

        $this->assertSame(WorkflowStatus::Paused, $run->status);
        $this->assertSame(
            ['do a', 'do fix round 1', 'do fix round 2'],
            $this->prompts,
            'the loop ran out both rounds; the pause was observed at the next stage boundary',
        );
        $this->assertSame(['a', 'fix'], self::stageNames($run));

        $data = $this->pauseData();
        $this->assertSame(2, $data['stagesCompleted'], 'the looped stage is recorded whole, as one row');

        $this->prompts = [];
        $resumed = $this->engine->resume($run->workflowId);

        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertSame(
            ['do z using OUT[do fix round 2]'],
            $this->prompts,
            'resume never re-runs a settled looped stage: whole-stage granularity, no round replay',
        );
        $this->assertFileDoesNotExist($this->pauseFile());
    }

    public function testATypedResultRowSurvivesThePauseFileAndInterpolatesAfterResume(): void
    {
        $this->registry->register((new WorkflowBuilder())
            ->name('typed')
            ->stage('a', Tasks::agent('coder')->prompt('do a'))
            ->stage('b', Tasks::agent('coder')->prompt('emit json')->outputSchema(['type' => 'object']))
            ->stage('c', Tasks::agent('coder')->prompt('hold here'))
            ->stage('d', Tasks::agent('coder')->prompt('use {{b.summary}}'))
            ->build());

        $this->script['emit'] = fn (SubAgent $subAgent): AgentResult => self::succeeded($subAgent, '{"summary":"green"}');
        $this->script['hold'] = function (SubAgent $subAgent): ?AgentResult {
            $this->engine->pause('typed');

            return null;
        };

        $paused = $this->engine->run('typed');
        $this->assertSame(WorkflowStatus::Paused, $paused->status);

        $file = $this->tempDir . '/workflows/.running/typed.json';
        $this->assertFileExists($file);
        $data = json_decode((string) file_get_contents($file), true);
        $this->assertIsArray($data);
        $results = $data['context'][WorkflowEngine::RESULTS_CONTEXT_KEY] ?? null;
        $this->assertIsArray($results);
        $this->assertSame(
            '{"summary":"green"}',
            $results['b'] ?? null,
            'the text row still carries the raw bytes in its own map',
        );
        $typed = $data['context'][WorkflowEngine::TYPED_RESULTS_CONTEXT_KEY] ?? null;
        $this->assertIsArray($typed);
        $this->assertSame(
            '{"summary":"green"}',
            $typed['b'] ?? null,
            'the typed row rides the pause file as a plain string under its raw name — no serializer was added',
        );

        unset($this->script['hold'], $this->script['emit']);
        $this->prompts = [];
        $resumed = $this->engine->resume('typed');

        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertSame(
            ['use green'],
            $this->prompts,
            'a in-flight stage that succeeded settles before the stop; resume runs only d,' .
            ' and its pointer read resolves from the file-carried typed row',
        );
    }

    // -------------------------------------------------------------------------
    // C9L: the inputJoins ledger — declarations ride the pause file, and a
    // mid-pause edit to a RECORDED join refuses the resume.
    // -------------------------------------------------------------------------

    /** @param array{from: list<string>, as: string}|null $mInput the join spec of stage m (null = no join) */
    private function registerFanIn(string $name, ?array $mInput = null, bool $withM = true): void
    {
        $builder = (new WorkflowBuilder())
            ->name($name)
            ->stage('a', Tasks::agent('coder')->prompt('do a'))
            ->stage('b', Tasks::agent('coder')->prompt('do b'));
        if ($withM) {
            $task = Tasks::agent('coder')->prompt($mInput === null ? 'merge plain' : 'merge {{input}}');
            if ($mInput !== null) {
                $task = $task->input($mInput);
            }
            $builder = $builder->stage('m', $task);
        }
        $this->registry->register(
            $builder->stage('z', Tasks::agent('coder')->prompt('ship using {{m.output}}'))->build(),
        );
    }

    /**
     * Hand-craft the pause record a first incarnation left: a, b and m settled
     * (m as a join stage), z never started. A null ledger writes NO inputJoins
     * key — the pre-C9L shape; a map rides the file verbatim.
     *
     * @param array<string, list<array{from: list<string>, as: string}>>|null $ledger
     */
    private function writeJoinRecord(string $name, ?array $ledger): string
    {
        $at = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $row = static fn (string $n, string $o): array => [
            'stageName' => $n,
            'status' => 'completed',
            'output' => $o,
            'error' => null,
            'agents' => [],
            'startedAt' => $at,
            'completedAt' => $at,
        ];
        $data = [
            'workflowId' => $name,
            'workflowPath' => $name,
            'status' => 'paused',
            'stagesCompleted' => 3,
            'context' => [
                'a.output' => 'OUT[do a]',
                'b.output' => 'OUT[do b]',
                'm.output' => 'OUT[merge recorded]',
            ],
            'stageResults' => [
                $row('a', 'OUT[do a]'),
                $row('b', 'OUT[do b]'),
                $row('m', 'OUT[merge recorded]'),
            ],
            'totalTokens' => 30,
            'totalCost' => 1.5,
            'startedAt' => $at,
        ];
        if ($ledger !== null) {
            $data['inputJoins'] = $ledger;
        }
        $file = $this->tempDir . '/workflows/.running/' . $name . '.json';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }
        file_put_contents($file, (string) json_encode($data));

        return $file;
    }

    public function testTheJoinDeclarationsRideThePauseFileAndAnUneditedResumePassesTheGuard(): void
    {
        $this->registerFanIn('joinfan', ['from' => ['a', 'b'], 'as' => 'all']);
        // Arm during m, not z: a pause that lands during the LAST stage never
        // meets a boundary, the run simply completes and withdraws its record.
        $this->pauseDuring('merge', 'joinfan');

        $run = $this->engine->run('joinfan');
        $this->assertSame(WorkflowStatus::Paused, $run->status);

        $file = $this->tempDir . '/workflows/.running/joinfan.json';
        $this->assertFileExists($file);
        $data = json_decode((string) file_get_contents($file), true);
        $this->assertSame(
            ['m' => [['from' => ['a', 'b'], 'as' => 'all']]],
            $data['inputJoins'] ?? null,
            'the settled join stage contributes exactly its declarations, in task order',
        );
        $this->assertArrayNotHasKey('input', $data['context'], 'the join PAYLOAD still never rides the file');

        unset($this->script['merge']);
        $this->prompts = [];
        $merged = '';
        foreach ($run->stageResults as $settled) {
            if ($settled->stageName === 'm') {
                $merged = (string) $settled->output;
            }
        }
        $resumed = $this->engine->resume('joinfan');

        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertSame(
            ['ship using ' . $merged],
            $this->prompts,
            'the guard passes an unedited resume, which re-runs ONLY z — m is never re-decided',
        );
        $this->assertFileDoesNotExist($file);
    }

    public function testAJoinFreePauseFileCarriesNoLedgerKey(): void
    {
        $this->registry->register((new WorkflowBuilder())
            ->name('nofan')
            ->stage('a', Tasks::agent('coder')->prompt('do a'))
            ->stage('b', Tasks::agent('coder')->prompt('do b'))
            ->stage('c', Tasks::agent('coder')->prompt('hold c'))
            ->build());
        $this->pauseDuring('do b', 'nofan');

        $run = $this->engine->run('nofan');
        $this->assertSame(WorkflowStatus::Paused, $run->status, 'the pause lands with c still pending, so the record survives');

        $data = json_decode(
            (string) file_get_contents($this->tempDir . '/workflows/.running/nofan.json'),
            true,
        );
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('inputJoins', $data, 'no settled join means NO key — never a spurious empty map');
    }

    public function testAPreLedgerPauseFileWithRecordedJoinStagesResumesAnyway(): void
    {
        $this->registerFanIn('legacy', ['from' => ['a', 'b'], 'as' => 'all']);
        $this->writeJoinRecord('legacy', null);

        $resumed = $this->engine->resume('legacy');

        $this->assertSame(
            WorkflowStatus::Completed,
            $resumed->status,
            'absence is "no claim made", not a broken one — a pre-C9L record resumes as it always did',
        );
        $this->assertSame(['ship using OUT[merge recorded]'], $this->prompts);
    }

    public function testEveryMidPauseJoinEditRefusesTheResumeWithThePinnedWording(): void
    {
        $ledger = ['m' => [['from' => ['a', 'b'], 'as' => 'all']]];
        $wording = "Workflow '%s' cannot be resumed: stage 'm' has a recorded input join whose"
            . " declaration no longer matches the pause record's ledger"
            . ' — refusing to re-decide a recorded join stage against an edited workflow';

        $edits = [
            'refuse-add' => ['from' => ['a', 'b', 'q'], 'as' => 'all'],
            'refuse-del' => ['from' => ['a'], 'as' => 'all'],
            // ruling: from-ORDER is byte-significant in all four shapes, so a
            // reorder of the same set re-decides different bytes and refuses.
            'refuse-order' => ['from' => ['b', 'a'], 'as' => 'all'],
            'refuse-as' => ['from' => ['a', 'b'], 'as' => 'first'],
            // the join itself stripped from the definition: a removed decision
            // is still a changed decision.
            'refuse-none' => null,
        ];
        foreach ($edits as $name => $spec) {
            $this->registerFanIn($name, $spec);
            $file = $this->writeJoinRecord($name, $ledger);
            try {
                $this->engine->resume($name);
                $this->fail("an edited join declaration must refuse the resume ({$name})");
            } catch (\SugarCraft\Code\Workflows\WorkflowNotRunningException $e) {
                $this->assertSame(sprintf($wording, $name), $e->getMessage(), 'pinned refusal wording');
            }
            $this->assertSame([], $this->prompts, "a refused resume dispatches nothing ({$name})");
            $this->assertFileExists($file, "a refused resume must not consume the record ({$name})");
        }

        // the identical declaration spelled through the same shapes resumes.
        $this->registerFanIn('refuse-ok', ['from' => ['a', 'b'], 'as' => 'all']);
        $this->writeJoinRecord('refuse-ok', $ledger);
        $resumed = $this->engine->resume('refuse-ok');
        $this->assertSame(WorkflowStatus::Completed, $resumed->status, 'the guard must not become a blanket refusal');
    }

    public function testARecordedJoinStageThatVanishedFromTheDefinitionRefusesTheResume(): void
    {
        $this->registerFanIn('gonefan', null, withM: false);
        $this->writeJoinRecord('gonefan', ['m' => [['from' => ['a', 'b'], 'as' => 'all']]]);

        $this->expectException(\SugarCraft\Code\Workflows\WorkflowNotRunningException::class);
        $this->engine->resume('gonefan');
    }

    public function testALedgerRowForAnUnrecordedStageIsToleratedAndThatStageMayReDecide(): void
    {
        $this->registerFanIn('prunetol', ['from' => ['a'], 'as' => 'first']);
        $at = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $row = static fn (string $n): array => [
            'stageName' => $n,
            'status' => 'completed',
            'output' => "OUT[do {$n}]",
            'error' => null,
            'agents' => [],
            'startedAt' => $at,
            'completedAt' => $at,
        ];
        $file = $this->tempDir . '/workflows/.running/prunetol.json';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }
        file_put_contents($file, (string) json_encode([
            'workflowId' => 'prunetol',
            'workflowPath' => 'prunetol',
            'status' => 'paused',
            'stagesCompleted' => 2,
            'context' => ['a.output' => 'OUT[do a]', 'b.output' => 'OUT[do b]'],
            'stageResults' => [$row('a'), $row('b')],
            // m's row claims the OLD join — but m is not among the recorded
            // settlements, so the claim defends nothing: prune-tolerance.
            'inputJoins' => ['m' => [['from' => ['a', 'b'], 'as' => 'all']]],
            'totalTokens' => 20,
            'totalCost' => 1.0,
            'startedAt' => $at,
        ]));

        $resumed = $this->engine->resume('prunetol');

        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertSame('merge OUT[do a]', $this->prompts[0], 'm re-decides under the new declaration — it was never settled');
        $this->assertStringStartsWith('ship using OUT[merge', $this->prompts[1]);
    }

    public function testTheLedgerCapturesJoinRowsForEveryStageShapeInDeclarationOrder(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('shapes')
            ->withVerification(
                'v',
                Tasks::agent('coder')->prompt('V1 {{input}}')->input(['from' => ['a'], 'as' => 'first']),
                Tasks::agent('coder')->prompt('V2 {{input}}')->input(['from' => ['b', 'a'], 'as' => 'file-list']),
            )
            ->pipeline('p', [
                Tasks::agent('coder')->prompt('P1 {{input}}')->input(['from' => ['a'], 'as' => 'all']),
                Tasks::agent('coder')->prompt('P2 plain'),
            ])
            ->build();

        $engine = (new \ReflectionClass(WorkflowEngine::class))->newInstanceWithoutConstructor();
        $read = new \ReflectionMethod(WorkflowEngine::class, 'stageJoinDeclarations');

        $verification = $read->invoke($engine, $workflow->stages[0], 'verification');
        $this->assertSame(
            [
                ['from' => ['a'], 'as' => 'first'],
                ['from' => ['b', 'a'], 'as' => 'file-list'],
            ],
            $verification,
            'task then verifier, in declaration order — the same collection the resolver consumes',
        );

        $pipeline = $read->invoke($engine, $workflow->stages[1], 'pipeline');
        $this->assertSame(
            [['from' => ['a'], 'as' => 'all']],
            $pipeline,
            'nested join tasks contribute in walk order; the join-free nested one earns no row',
        );
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

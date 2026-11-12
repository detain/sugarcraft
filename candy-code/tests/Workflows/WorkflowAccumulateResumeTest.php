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
 * #26: an accumulate loop's settled rounds ride the pause record, so a resume
 * continues at the next round with the set it had instead of re-running the
 * whole loop from round 1 with an empty set.
 */
final class WorkflowAccumulateResumeTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;

    /** @var list<string> */
    private array $prompts = [];

    /** @var list<string> */
    private array $outputs = [];

    /** @var callable(string): void|null called with each dispatched prompt, before it is answered */
    private $onDispatch = null;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_accumulate_resume_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
        $this->registry = new WorkflowRegistry($this->tempDir . '/workflows');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/workflows/.running/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir . '/workflows/.running');
        @rmdir($this->tempDir . '/workflows');
        @rmdir($this->tempDir);
    }

    /** Round 1 adds a and b, round 2 adds c, round 3 adds nothing: dry 1 ends the loop. */
    private function registerFindLoop(string $name): void
    {
        $this->outputs = ['{"f":["a","b"]}', '{"f":["c"]}', '{"f":[]}'];
        $this->registry->register((new WorkflowBuilder())->name($name)
            ->stage('find', Tasks::agent('f')->prompt('find {{round}} seen {{seen}}')->outputSchema(['type' => 'object']))
            ->loop(['max' => 5, 'accumulate' => ['pointer' => '/f'], 'until' => ['dry' => 1]])
            ->build());
    }

    public function testAnOldFormatPauseFileWithoutRoundStateRunsTheLoopFromRoundOne(): void
    {
        $this->registerFindLoop('old-format');
        $this->writePauseFile('old-format', [
            'workflowId' => 'old-format',
            'workflowPath' => 'old-format',
            'status' => 'paused',
            'stagesCompleted' => 0,
            'context' => [],
            'stageResults' => [],
            'totalTokens' => 0,
            'totalCost' => 0.0,
            'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        $result = $this->engine()->resume('old-format');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame([
            'find 1 seen []',
            'find 2 seen ["a","b"]',
            'find 3 seen ["a","b","c"]',
        ], $this->prompts, 'a record without the round state resumes exactly as before: the loop starts over');
        self::assertSame('{"items":["a","b","c"],"keys":["a","b","c"],"rounds":3}', $result->stageResults[0]->output);
    }

    public function testAResumeFromAMidLoopRecordSkipsTheCompletedRounds(): void
    {
        $this->registerFindLoop('mid-loop');

        // The live run writes its own record after each settled round. Capture
        // the one on disk as round 3 is about to dispatch: that is what a kill
        // during round 3 leaves behind.
        $captured = null;
        $this->onDispatch = function (string $task) use (&$captured): void {
            if (str_starts_with($task, 'find 3 ') && $captured === null) {
                $captured = (string) file_get_contents($this->pauseFile('mid-loop'));
            }
        };
        $this->engine()->run('mid-loop');

        self::assertIsString($captured);
        $record = json_decode($captured, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $record['loopRound']['completedRounds'] ?? null, 'two rounds settled before the kill');
        self::assertSame(['a', 'b', 'c'], $record['loopRound']['accumulator']['keys'] ?? null);

        // The killed writer is gone: drop its pid so the record reads as an orphan.
        unset($record['pid'], $record['pidStartedAt']);
        $this->writePauseFile('mid-loop', $record);

        $this->prompts = [];
        $this->onDispatch = null;
        $this->outputs = ['{"f":[]}'];
        $result = $this->engine()->resume('mid-loop');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(['find 3 seen ["a","b","c"]'], $this->prompts, 'rounds 1 and 2 are not re-dispatched');
        self::assertSame('{"items":["a","b","c"],"keys":["a","b","c"],"rounds":3}', $result->stageResults[0]->output);
        self::assertArrayNotHasKey('loopRound', $result->context, 'the round state never leaks into the run context');
    }

    public function testTheRunIdIsAnnouncedBeforeAnyStageDispatches(): void
    {
        $this->registerFindLoop('announce');
        $events = [];
        $engine = $this->engine();
        $engine->setRunStartedSink(static function (string $runId) use (&$events): void {
            $events[] = 'started:' . $runId;
        });
        $this->onDispatch = function (string $task) use (&$events): void {
            $events[] = 'dispatch:' . $task;
        };

        $workflow = $this->registry->fromYamlString(
            "name: announce\nstages:\n  - name: find\n    prompt: 'find {{round}} seen {{seen}}'\n    outputSchema: {type: object}\n"
            . "    loop:\n      max: 5\n      accumulate: {pointer: /f}\n      until: {dry: 1}\n",
        );
        $engine->runPlan($workflow, "name: announce\nstages:\n  - name: find\n    prompt: 'find {{round}} seen {{seen}}'\n    outputSchema: {type: object}\n    loop:\n      max: 5\n      accumulate: {pointer: /f}\n      until: {dry: 1}\n");

        self::assertNotEmpty($events);
        self::assertMatchesRegularExpression('/^started:plan-[0-9a-f]{8}-1$/', $events[0]);
        self::assertStringStartsWith('dispatch:', $events[1]);
    }

    private function pauseFile(string $name): string
    {
        return $this->tempDir . '/workflows/.running/' . $name . '.json';
    }

    /** @param array<string, mixed> $data */
    private function writePauseFile(string $name, array $data): void
    {
        @mkdir($this->tempDir . '/workflows/.running', 0755, true);
        file_put_contents($this->pauseFile($name), json_encode($data, JSON_PRETTY_PRINT) . "\n");
    }

    private function engine(): WorkflowEngine
    {
        $test = $this;
        $executor = new class ($test) implements ExecutorInterface {
            public function __construct(private readonly WorkflowAccumulateResumeTest $test)
            {
            }

            public function execute(SubAgent $subAgent, CompleteRequest $request): AgentResult
            {
                $this->test->answer($subAgent->task);

                return new AgentResult(
                    agentId: $subAgent->id,
                    status: AgentStatus::Completed,
                    output: $this->test->nextOutput(),
                    tokensUsed: 1,
                    costUsd: 0.01,
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

        return new WorkflowEngine($this->registry, new AgentWorkerPool(1, $executor));
    }

    /** @internal the fake executor's hook */
    public function answer(string $task): void
    {
        $this->prompts[] = $task;
        if ($this->onDispatch !== null) {
            ($this->onDispatch)($task);
        }
    }

    /** @internal the fake executor's answer: the scripted outputs in order, the last one repeated */
    public function nextOutput(): string
    {
        $output = array_shift($this->outputs) ?? '{"f":[]}';
        if ($this->outputs === []) {
            $this->outputs = [$output];
        }

        return $output;
    }
}

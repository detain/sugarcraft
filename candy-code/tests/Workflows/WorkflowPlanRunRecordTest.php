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
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowNotRunningException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * wf-impl P1-8: Workflow-tool plan runs leave a resumable record —
 * `plan-<sha8>-<n>` IDs, the stored plan beside the record, the record kept
 * at every terminal, and a resume that carries the longest unchanged prefix.
 */
final class WorkflowPlanRunRecordTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;

    /** @var list<string> */
    public array $prompts = [];

    /** @var list<string> prompt prefixes that fail */
    public array $failing = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_planrun_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
        $this->registry = new WorkflowRegistry($this->tempDir . '/workflows');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->tempDir);
    }

    /** @internal the fake executor's body */
    public function dispatch(SubAgent $agent): AgentResult
    {
        $this->prompts[] = $agent->task;
        foreach ($this->failing as $prefix) {
            if (str_starts_with($agent->task, $prefix)) {
                return new AgentResult(agentId: $agent->id, status: AgentStatus::Failed, error: new \RuntimeException('boom'), completedAt: new \DateTimeImmutable());
            }
        }

        return new AgentResult(agentId: $agent->id, status: AgentStatus::Completed, output: 'OUT[' . $agent->task . ']', tokensUsed: 100, completedAt: new \DateTimeImmutable());
    }

    /** Also the plan {@see \SugarCraft\Code\Tests\Tools\WorkflowToolResumeTest} runs: one provider, not two copies. */
    public const PLAN = "name: tri\nstages:\n  - name: a\n    prompt: do a\n  - name: b\n    prompt: 'do b after {{a.output}}'\n  - name: c\n    prompt: do c\n";

    public function testAPlanRunGetsADigestIdAndKeepsItsCompletedRecordAndPlan(): void
    {
        $result = $this->runPlan(self::PLAN);

        $digest = substr(hash('sha256', self::PLAN), 0, 8);
        self::assertTrue($result->isSuccess());
        self::assertSame("plan-{$digest}-1", $result->workflowId);

        $record = $this->record($result->workflowId);
        self::assertSame('completed', $record['status']);
        self::assertTrue($record['planRun']);
        self::assertSame(3, $record['stagesCompleted']);
        self::assertSame(['a', 'b', 'c'], array_keys($record['planStageHashes']));
        self::assertSame(0600, fileperms($this->runningDir() . '/' . $result->workflowId . '.json') & 0777);

        $stored = json_decode((string) file_get_contents($this->runningDir() . '/' . $result->workflowId . '.plan'), true);
        self::assertSame(self::PLAN, $stored['plan']);
        self::assertSame(['k' => 'v'], $stored['context']);

        self::assertSame(WorkflowStatus::Completed, $this->engine()->status($result->workflowId), 'a fresh engine reads the kept record');
        self::assertSame("plan-{$digest}-2", $this->runPlan(self::PLAN)->workflowId, 'the same plan again is the next n');
    }

    public function testSamePlanSameContextCarriesEveryStageAtNoCost(): void
    {
        $first = $this->runPlan(self::PLAN);
        $this->prompts = [];

        $again = $this->engine()->resumePlan($first->workflowId, $this->registry->fromYamlString(self::PLAN), self::PLAN, ['k' => 'v']);

        self::assertTrue($again->isSuccess());
        self::assertSame([], $this->prompts, 'nothing was dispatched');
        self::assertSame(3, $again->stagesCarried);
        self::assertSame(0, $again->totalTokens, 'carried stages cost nothing this call');
        self::assertSame($first->workflowId, $again->workflowId, 'the run keeps its ID');
        self::assertCount(3, $again->stageResults);
    }

    public function testAnEditedStageAndEverythingAfterItRunLive(): void
    {
        $first = $this->runPlan(self::PLAN);
        $this->prompts = [];

        $edited = str_replace('do b after', 'do B after', self::PLAN);
        $again = $this->engine()->resumePlan($first->workflowId, $this->registry->fromYamlString($edited), $edited, null);

        self::assertTrue($again->isSuccess());
        self::assertSame(1, $again->stagesCarried);
        self::assertSame(['do B after OUT[do a]', 'do c'], $this->prompts, 'c is unchanged but downstream of the edit, so it re-runs (prefix rule)');
        self::assertSame(200, $again->totalTokens);

        $stored = json_decode((string) file_get_contents($this->runningDir() . '/' . $first->workflowId . '.plan'), true);
        self::assertSame($edited, $stored['plan'], 'the stored plan follows the edit, so a TUI resume runs what was last asked');
    }

    public function testAFailedRunIsKeptAndResumesFromTheFailure(): void
    {
        $this->failing = ['do b'];
        $first = $this->runPlan(self::PLAN);
        self::assertSame(WorkflowStatus::Failed, $first->status);
        self::assertSame('failed', $this->record($first->workflowId)['status']);

        $this->failing = [];
        $this->prompts = [];
        // The registry door (`/workflow resume <runId>`) reaches the same
        // plan resume, from the stored plan.
        $again = $this->engine()->resume($first->workflowId);

        self::assertTrue($again->isSuccess());
        self::assertSame(['do b after OUT[do a]', 'do c'], $this->prompts);
        self::assertSame('completed', $this->record($first->workflowId)['status'], 'the record is kept, not consumed');
    }

    public function testAChangedContextCarriesNothing(): void
    {
        $first = $this->runPlan(self::PLAN);
        $this->prompts = [];

        $again = $this->engine()->resumePlan($first->workflowId, $this->registry->fromYamlString(self::PLAN), self::PLAN, ['k' => 'other']);

        self::assertSame(0, $again->stagesCarried);
        self::assertCount(3, $this->prompts);
    }

    public function testAWhenSkipIsCarriedAsASettledFact(): void
    {
        $plan = "stages:\n  - name: a\n    prompt: do a\n  - name: s\n    prompt: do s\n    when: {stage: a, equals: never}\n  - name: c\n    prompt: do c\n";
        $first = $this->runPlan($plan);
        self::assertSame(WorkflowStatus::Skipped, $first->stageResults[1]->status);
        $this->prompts = [];

        $again = $this->engine()->resumePlan($first->workflowId, $this->registry->fromYamlString($plan), $plan, null);
        self::assertSame(3, $again->stagesCarried);
        self::assertSame([], $this->prompts);
    }

    public function testUnknownForeignAndMalformedIdsAreRefusedWithNothingDispatched(): void
    {
        $engine = $this->engine();
        foreach (['plan-deadbeef-9', 'deploy-1234abcd', '../plan-x'] as $id) {
            try {
                $engine->resumePlan($id);
                self::fail("{$id} should be refused");
            } catch (WorkflowNotRunningException $e) {
                self::assertStringContainsString('nothing was dispatched', strtolower($e->getMessage()));
            }
        }

        // A saved workflow's record under a plan-looking name is still foreign.
        mkdir($this->runningDir(), 0700, true);
        file_put_contents($this->runningDir() . '/plan-0000aaaa-1.json', json_encode(['workflowId' => 'plan-0000aaaa-1', 'workflowPath' => 'x', 'status' => 'paused']));
        try {
            $engine->resumePlan('plan-0000aaaa-1');
            self::fail('a non-plan record must be refused');
        } catch (WorkflowNotRunningException $e) {
            self::assertStringContainsString("saved workflow's run", $e->getMessage());
        }
        self::assertSame([], $this->prompts);
    }

    public function testOnlyTheNewestPlanRunsAreKept(): void
    {
        $ids = [];
        for ($i = 0; $i < WorkflowEngine::PLAN_RUN_RETENTION + 2; $i++) {
            $plan = "stages:\n  - name: a\n    prompt: run {$i}\n    capture: true\n";
            $ids[] = $this->runPlan($plan)->workflowId;
            // mtime is the recency: make every run strictly older than the next.
            touch($this->runningDir() . '/' . end($ids) . '.plan', time() - 1000 + $i);
        }
        $this->runPlan("stages:\n  - name: a\n    prompt: last\n");

        $plans = glob($this->runningDir() . '/plan-*.plan') ?: [];
        self::assertCount(WorkflowEngine::PLAN_RUN_RETENTION, $plans);
        foreach (array_slice($ids, 0, 3) as $old) {
            self::assertFileDoesNotExist($this->runningDir() . '/' . $old . '.json');
            self::assertDirectoryDoesNotExist($this->runningDir() . '/' . $old . '.stages', 'its captures go with it');
        }
        self::assertFileExists($this->runningDir() . '/' . end($ids) . '.json');
    }

    public function testABudgetPauseIsKeptPausedAndResumesUnderARaisedBudget(): void
    {
        $first = $this->engine()->runPlan($this->registry->fromYamlString(self::PLAN), self::PLAN, [], null, 150);
        self::assertSame(WorkflowStatus::Paused, $first->status);
        self::assertSame('paused', $this->record($first->workflowId)['status']);

        $this->prompts = [];
        $again = $this->engine()->resumePlan($first->workflowId, budgetTokens: 1000);
        self::assertTrue($again->isSuccess());
        self::assertGreaterThanOrEqual(1, $again->stagesCarried);
    }

    /** @param array<string, mixed> $context */
    private function runPlan(string $plan, array $context = ['k' => 'v']): \SugarCraft\Code\Workflows\WorkflowResult
    {
        return $this->engine()->runPlan($this->registry->fromYamlString($plan), $plan, $context);
    }

    private function runningDir(): string
    {
        return $this->tempDir . '/workflows/.running';
    }

    /** @return array<string, mixed> */
    private function record(string $runId): array
    {
        $data = json_decode((string) file_get_contents($this->runningDir() . '/' . $runId . '.json'), true);
        self::assertIsArray($data);

        return $data;
    }

    private function engine(): WorkflowEngine
    {
        $test = $this;
        $executor = new class ($test) implements ExecutorInterface {
            public function __construct(private readonly WorkflowPlanRunRecordTest $test)
            {
            }

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return $this->test->dispatch($agent);
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield $this->execute($agent, $request);
            }

            public function cancel(string $agentId): void
            {
            }

            public function cancelAll(): void
            {
            }
        };

        return new WorkflowEngine($this->registry, new AgentWorkerPool(executor: $executor), environmentRoot: $this->tempDir);
    }
}

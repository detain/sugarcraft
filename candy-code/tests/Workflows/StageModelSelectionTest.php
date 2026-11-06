<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\EngineExecutor;
use SugarCraft\Code\Agents\ExecutorInterface;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Tests\Support\RosterAgent;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Workflows\TaskBuilder;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\Workflow;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;
use SugarCraft\Code\Workflows\WorkflowTask;

/**
 * S2: a stage's `model:` ask rides the SAME channel a delegation's model
 * override rides (EngineBackend::withModel → the App the Runtime stamps onto
 * every request), decided by the ONE ladder ({@see
 * \SugarCraft\Code\Agents\ModelChoice}) TaskTool consults. The stage law
 * differs where it must: an ask the session cannot answer REFUSES the stage
 * before a single dial — a plan author who pinned a model's answer never
 * silently gets another model's. Absent, the dial is byte-identical to the
 * session default.
 */
final class StageModelSelectionTest extends TestCase
{
    private string $tempDir;

    /** @var list<SubAgent> */
    private array $modelDispatched = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_model_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/workflows/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir . '/workflows');
        @rmdir($this->tempDir);
    }

    // ------------------------------------------------------------------ builder boundary

    public function testAModelAskPassesThroughTheBuilderTrimmed(): void
    {
        $task = (new TaskBuilder())->agent('coder')->prompt('work')->model(' fast-x ')->build();

        self::assertSame('fast-x', $task->model, 'the boundary trims; internals trust');
    }

    public function testATaskWithoutAModelCarriesNone(): void
    {
        self::assertNull((new TaskBuilder())->agent('coder')->prompt('work')->build()->model);
    }

    public function testABlankModelAskFailsClosedAtBuildTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/names no model/');

        (new TaskBuilder())->agent('coder')->prompt('work')->model('  ')->build();
    }

    public function testTheSubAgentWireCarriesTheModelBesideItsSiblings(): void
    {
        $wire = $this->stageWithModel('wire-me')->toArray();

        self::assertSame('wire-me', $wire['model'], 'the model ask is part of the run record');
    }

    // ------------------------------------------------------------------ engine threading

    public function testEveryStageDispatchCarriesItsOwnModelAsk(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $registry->register(
            (new WorkflowBuilder())
                ->name('models-thread')
                ->stage('work', [
                    Tasks::agent('coder')->prompt('asked')->model('x'),
                    Tasks::agent('coder')->prompt('plain'),
                    Tasks::agent('coder')->prompt('asked-again')->model('y'),
                ])
                ->build(),
        );

        $result = $this->threadEngine($registry)->run('models-thread');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(
            ['x', null, 'y'],
            array_map(static fn (SubAgent $agent): ?string => $agent->model, $this->modelDispatched),
            'each model ask reaches its own SubAgent; the unset task stays null',
        );
    }

    public function testAParallelStageCarriesEachAgentsModelSeparately(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $registry->register(
            (new WorkflowBuilder())
                ->name('models-fanout')
                ->parallel('work', [
                    Tasks::agent('coder')->prompt('left')->model('fast-l'),
                    Tasks::agent('coder')->prompt('right'),
                ])
                ->build(),
        );

        $result = $this->threadEngine($registry)->run('models-fanout');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        $byTask = [];
        foreach ($this->modelDispatched as $agent) {
            $byTask[$agent->task] = $agent->model;
        }
        self::assertSame(['left' => 'fast-l', 'right' => null], $byTask);
    }

    // ------------------------------------------------------------------ EngineExecutor legs

    public function testThePinnedModelIsTheOneDialedAndReported(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'structured answer')]);
        $agent = $this->stageWithModel('other-model');

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))->execute(
            $agent,
            $this->modelWire(),
        );

        self::assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());
        self::assertSame('other-model', $provider->requests[0]->model, 'the ask pinned the dial');
        self::assertSame('other-model', $agent->runModel, 'and the run reports the model it dialed');
    }

    public function testAClaudeTierNameTheSessionCannotAnswerForRefusesTheStage(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'never')]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))->execute(
            $this->stageWithModel('sonnet'),
            $this->modelWire(),
        );

        self::assertSame(AgentStatus::Failed, $result->status);
        self::assertStringContainsString('asks to run on model "sonnet"', (string) $result->error?->getMessage());
        self::assertStringContainsString('was not started', (string) $result->error?->getMessage());
        self::assertSame([], $provider->requests, 'a refused stage never dials');
    }

    public function testAnIdThatIsNotAModelRefusesTheStage(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'never')]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))->execute(
            $this->stageWithModel('two words'),
            $this->modelWire(),
        );

        self::assertSame(AgentStatus::Failed, $result->status);
        self::assertStringContainsString('which is not a model id', (string) $result->error?->getMessage());
        self::assertStringContainsString('was not started', (string) $result->error?->getMessage());
        self::assertSame([], $provider->requests);
    }

    public function testAnAbsentModelAskDialsTheSessionDefaultUnchanged(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'stage report')]);
        $agent = $this->stageWithModel(null);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))->execute($agent, $this->modelWire());

        self::assertSame(AgentStatus::Completed, $result->status);
        self::assertSame('m', $provider->requests[0]->model, 'the legacy dial is byte-identical');
        self::assertSame('', $agent->runModel, 'nothing wrote the run model — the field keeps its pre-S2 shape');
        self::assertSame('stage report', $result->output);
    }

    public function testAStageAskingTheSessionModelRunsUntouchedButReported(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'stage report')]);
        $agent = $this->stageWithModel('m');

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))->execute($agent, $this->modelWire());

        self::assertSame(AgentStatus::Completed, $result->status, 'asking the session model is never a refusal');
        self::assertSame('m', $provider->requests[0]->model);
        self::assertSame('m', $agent->runModel, 'the run model is reported whenever the stage named one');
    }

    public function testInheritDefersToThePresetAndNeverRefuses(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'stage report')]);
        $agent = $this->stageWithModel('inherit');

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))->execute($agent, $this->modelWire());

        self::assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());
        self::assertSame('m', $provider->requests[0]->model, 'a bare coder preset has no model of its own — session floor');
    }

    // ------------------------------------------------------------------ YAML boundary

    public function testTheYamlModelKeyLandsOnTheTaskDtoInEveryForm(): void
    {
        $yaml = <<< "YAML"
        name: models-forms
        description: every spelling of the stage model key
        stages:
          - name: single
            agent: coder
            prompt: one
            model: fast-a
          - name: padded
            agent: coder
            prompt: two
            model: '  spaced  '
          - name: fanned
            parallel: true
            agents:
              - name: left
                prompt: three
                model: fast-b
              - name: right
                prompt: four
          - name: listed
            tasks:
              - agent: coder
                prompt: five
                model: fast-c
        YAML;

        $workflow = (new WorkflowRegistry($this->tempDir . '/workflows'))->fromYamlString($yaml, 'models-forms');

        self::assertSame('fast-a', $this->modelTaskOf($workflow, 0, 0)->model, 'a stage carries it');
        self::assertSame('spaced', $this->modelTaskOf($workflow, 1, 0)->model, 'the boundary trims');
        self::assertSame('fast-b', $this->modelTaskOf($workflow, 2, 0)->model, 'parallel agents carry their own');
        self::assertNull($this->modelTaskOf($workflow, 2, 1)->model, 'a sibling without one stays null');
        self::assertSame('fast-c', $this->modelTaskOf($workflow, 3, 0)->model, 'task-list entries carry their own');
    }

    public function testAModelKeyThatNamesNothingFailsTheLoad(): void
    {
        $cases = [
            'a number names no model' => ['model: 7', '/must be a string naming a model/'],
            'a list names no model' => ["model:\n      - fast", '/must be a string naming a model/'],
            'blank is refused, not read as session' => ['model: "   "', '/names no model/'],
        ];

        foreach ($cases as $why => [$line, $shape]) {
            $yaml = "name: closed\n"
                . "description: {$why}\n"
                . "stages:\n"
                . "  - name: work\n"
                . "    agent: coder\n"
                . "    prompt: check\n"
                . "    {$line}\n";

            $failed = false;
            try {
                (new WorkflowRegistry($this->tempDir . '/workflows'))->fromYamlString($yaml, 'closed');
            } catch (WorkflowLoadException $refusal) {
                $failed = true;
                self::assertMatchesRegularExpression($shape, $refusal->getMessage(), $why);
            }
            self::assertTrue($failed, $why . ' — a key the author wrote must name something');
        }
    }

    public function testTheStageTaskCollisionGuardCoversTheModelKey(): void
    {
        $yaml = "name: collide\n"
            . "description: the key belongs on each task\n"
            . "stages:\n"
            . "  - name: work\n"
            . "    model: fast-x\n"
            . "    tasks:\n"
            . "      - agent: coder\n"
            . "        prompt: check\n";

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessageMatches('/"model" belongs on each task/');

        (new WorkflowRegistry($this->tempDir . '/workflows'))->fromYamlString($yaml, 'collide');
    }

    // ------------------------------------------------------------------ helpers

    /** @internal the capturing fake's body; public only so the anonymous class can reach it */
    public function captureModelDispatch(SubAgent $subAgent): AgentResult
    {
        $this->modelDispatched[] = $subAgent;

        return new AgentResult(
            agentId: $subAgent->id,
            status: AgentStatus::Completed,
            output: 'OUT[' . $subAgent->task . ']',
            error: null,
            tokensUsed: 1,
            costUsd: 0.0,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    private function threadEngine(WorkflowRegistry $registry): WorkflowEngine
    {
        return new WorkflowEngine($registry, new AgentWorkerPool(1, $this->capturingExecutor()));
    }

    private function capturingExecutor(): ExecutorInterface
    {
        return new class ($this) implements ExecutorInterface {
            public function __construct(private readonly StageModelSelectionTest $test)
            {
            }

            public function execute(SubAgent $subAgent, CompleteRequest $request): AgentResult
            {
                return $this->test->captureModelDispatch($subAgent);
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
    }

    private function stageWithModel(?string $model): SubAgent
    {
        return new SubAgent(
            id: 'stage-model-' . bin2hex(random_bytes(4)),
            agent: RosterAgent::named('coder'),
            task: 'check the lib',
            timeout: 30,
            model: $model,
        );
    }

    private function modelWire(): CompleteRequest
    {
        return new CompleteRequest(model: 'm', messages: [['role' => 'user', 'content' => 'check the lib']]);
    }

    private function modelTaskOf(Workflow $workflow, int $stageIndex, int $taskIndex): WorkflowTask
    {
        return $workflow->stages[$stageIndex]['tasks'][$taskIndex];
    }
}

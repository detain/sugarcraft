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
use SugarCraft\Crush\Workflows\Workflow;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowRegistry;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * AUDIT WF-3: `{{name.results}}` never resolved for a parallel agent — the
 * purpose the docs gave it — and a run-context key equal to an agent type
 * crashed the stage after its agent had run.
 *
 * Measured before the fix with the audit's repro (`fan` and `three` below):
 *
 *     fan: completed; verify prompt = styler said {{styler.results}} / checker said {{checker.results}}
 *     three with coder=x: failed; stage a error=Cannot access offset of type string on string; agents run=1 tokens=0
 *
 * Only the sequential executor wrote a result, keyed by the agent TYPE for an
 * unnamed YAML stage, into the same flat namespace as the user's context.
 * Results now live under {@see WorkflowEngine::RESULTS_CONTEXT_KEY}, are
 * written for every stage type, and are named by task name with a stage-based
 * fallback. Every test drives the real engine with a fake executor.
 */
final class WorkflowAgentResultsTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;

    /** @var list<string> every prompt the executor was handed, in order */
    private array $prompts = [];

    /** @var array<string, \Closure(SubAgent): ?AgentResult> prompt prefix => behaviour override */
    private array $script = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_wf_results_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);

        // The audit's two repro workflows, verbatim.
        file_put_contents($this->tempDir . '/workflows/fan.yaml', <<<'YAML'
            name: fan
            stages:
              - name: fix
                parallel: true
                agents:
                  - name: styler
                    prompt: style
                  - name: checker
                    prompt: check
              - name: verify
                prompt: "styler said {{styler.results}} / checker said {{checker.results}}"
            YAML);
        file_put_contents($this->tempDir . '/workflows/three.yaml', <<<'YAML'
            name: three
            stages:
              - name: a
                prompt: do a
              - name: b
                prompt: do b using {{a.output}}
              - name: c
                prompt: do c using {{b.output}}
            YAML);

        $this->registry = new WorkflowRegistry($this->tempDir . '/workflows');
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

        return new AgentResult(
            agentId: $subAgent->id,
            status: AgentStatus::Completed,
            output: 'OUT[' . $subAgent->task . ']',
            tokensUsed: 7,
            costUsd: 0.5,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    public function testEachParallelAgentsResultResolvesToItsOwnOutput(): void
    {
        $result = $this->engine()->run('fan');

        self::assertSame(WorkflowStatus::Completed, $result->status);
        self::assertSame(
            'styler said OUT[style] / checker said OUT[check]',
            end($this->prompts),
            'the verify stage must see each parallel agent\'s own output, not the literal token',
        );
        self::assertSame(
            [
                'styler' => 'OUT[style]',
                'checker' => 'OUT[check]',
                'verify' => 'OUT[styler said OUT[style] / checker said OUT[check]]',
            ],
            $result->context[WorkflowEngine::RESULTS_CONTEXT_KEY],
        );
    }

    public function testARunContextKeyEqualToAnAgentTypeNoLongerCrashesTheStage(): void
    {
        $result = $this->engine()->run('three', ['coder' => 'x']);

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(['do a', 'do b using OUT[do a]', 'do c using OUT[do b using OUT[do a]]'], $this->prompts);
        self::assertSame(21, $result->totalTokens, 'every agent that ran is counted');
        self::assertSame('x', $result->context['coder'], 'the caller\'s context entry is left alone');
    }

    public function testTwoUnnamedStagesOnTheSameAgentTypeKeepSeparateResults(): void
    {
        $result = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('twice')
            ->stage('first', Tasks::agent('coder')->prompt('one'))
            ->stage('second', Tasks::agent('coder')->prompt('two'))
            ->stage('after', Tasks::agent('coder')->prompt('{{first.results}}|{{second.results}}|{{coder.results}}'))
            ->build());

        self::assertSame(WorkflowStatus::Completed, $result->status);
        self::assertSame(
            'OUT[one]|OUT[two]|{{coder.results}}',
            $this->prompts[2],
            'an unnamed stage is keyed by its stage name; the agent type is no longer a key, so it stays literal',
        );
    }

    public function testANamedTaskIsKeyedByItsNameNotItsStage(): void
    {
        $result = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('named')
            ->stage('build', Tasks::agent('coder')->name('builder')->prompt('make it'))
            ->stage('after', Tasks::agent('coder')->prompt('{{builder.results}}|{{build.results}}|{{build.output}}'))
            ->build());

        self::assertSame('OUT[make it]|{{build.results}}|OUT[make it]', $this->prompts[1]);
        self::assertSame(
            ['builder' => 'OUT[make it]', 'after' => 'OUT[OUT[make it]|{{build.results}}|OUT[make it]]'],
            $result->context[WorkflowEngine::RESULTS_CONTEXT_KEY] ?? null,
            'results live in the reserved map, not beside the caller\'s context',
        );
        self::assertArrayNotHasKey('builder', $result->context);
    }

    public function testUnnamedParallelAgentsFallBackToTheStageNameAndTheirOrdinal(): void
    {
        $result = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('fanout')
            ->parallel('fix', [
                Tasks::agent('coder')->prompt('left'),
                Tasks::agent('coder')->name('middle')->prompt('mid'),
                Tasks::agent('coder')->prompt('right'),
            ])
            ->stage('after', Tasks::agent('coder')->prompt('{{fix_1.results}}|{{middle.results}}|{{fix_3.results}}|{{fix_2.results}}'))
            ->build());

        self::assertSame(WorkflowStatus::Completed, $result->status);
        self::assertSame('OUT[left]|OUT[mid]|OUT[right]|{{fix_2.results}}', end($this->prompts));
    }

    public function testParallelResultsAreMatchedToTheirAgentByIdNotByCompletionOrder(): void
    {
        $records = new \ReflectionMethod(WorkflowEngine::class, 'recordsInDeclarationOrder');
        $finished = static fn (string $id, string $output): AgentResult => new AgentResult(
            agentId: $id,
            status: AgentStatus::Completed,
            output: $output,
        );

        // The pool yields in completion order: the third agent first. And an
        // executor that did not echo the id it was handed still lands in the
        // first unclaimed slot.
        $paired = $records->invoke(null, ['id-1' => 'one', 'id-2' => 'two', 'id-3' => 'three'], [
            $finished('id-3', 'third'),
            $finished('foreign', 'unidentified'),
            $finished('id-1', 'first'),
        ]);

        self::assertSame(
            ['one' => 'first', 'two' => 'unidentified', 'three' => 'third'],
            array_column(array_map(static fn (array $r): array => [$r['key'], $r['result']->output], $paired), 1, 0),
        );
    }

    public function testPipelineStepResultsAreAddressableByLaterStepsAndStages(): void
    {
        $result = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('chain')
            ->pipeline('steps', [
                Tasks::agent('coder')->name('draft')->prompt('write'),
                Tasks::agent('reviewer')->prompt('review {{draft.results}}'),
            ])
            ->stage('after', Tasks::agent('coder')->prompt('{{draft.results}}|{{reviewer.results}}'))
            ->build());

        self::assertSame(WorkflowStatus::Completed, $result->status);
        self::assertSame('review OUT[write]', $this->prompts[1], 'a later step sees an earlier step\'s result');
        self::assertSame(
            'OUT[write]|OUT[review OUT[write]]',
            $this->prompts[2],
            'an unnamed step is keyed by its step name, which WorkflowBuilder::pipeline() sets to its agent type',
        );
    }

    public function testVerificationTaskAndVerifierResultsAreAddressable(): void
    {
        $result = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('checked')
            ->withVerification('check', Tasks::agent('coder')->prompt('make'), Tasks::agent('tester')->prompt('verify {{check.results}}'))
            ->stage('after', Tasks::agent('coder')->prompt('{{check.results}}|{{check_verifier.results}}'))
            ->build());

        self::assertSame(WorkflowStatus::Completed, $result->status);
        self::assertSame('verify OUT[make]', $this->prompts[1], 'the verifier sees the task\'s result');
        self::assertSame('OUT[make]|OUT[verify OUT[make]]', $this->prompts[2]);
    }

    public function testAFailingVerifierKeepsTheTasksSpendOnTheStage(): void
    {
        $this->script['verify'] = static fn (SubAgent $a): AgentResult => new AgentResult(
            agentId: $a->id,
            status: AgentStatus::Failed,
            error: new \RuntimeException('not good enough'),
            tokensUsed: 3,
        );

        $result = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('checked')
            ->withVerification('check', Tasks::agent('coder')->prompt('make'), Tasks::agent('tester')->prompt('verify it'))
            ->build());

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame('not good enough', $result->stageResults[0]->error);
        self::assertCount(2, $result->stageResults[0]->agents);
        self::assertSame(10, $result->totalTokens, 'the task\'s 7 tokens plus the verifier\'s 3');
    }

    public function testAThrowAfterAnAgentRanKeepsThatAgentsTokens(): void
    {
        // An empty tool registry refuses any declaration, and a pipeline
        // resolves each step's tools only when it reaches that step — so step 2
        // throws with step 1 already done.
        $engine = new WorkflowEngine($this->registry, new AgentWorkerPool(1, $this->executor()), toolRegistry: []);

        $result = $engine->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('half')
            ->pipeline('steps', [
                Tasks::agent('coder')->name('draft')->prompt('write'),
                Tasks::agent('coder')->name('polish')->prompt('polish')->tools(['Read']),
            ])
            ->build());

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame(['write'], $this->prompts);
        self::assertCount(1, $result->stageResults[0]->agents, 'the step that ran stays on the failed stage');
        self::assertSame(7, $result->totalTokens);
        self::assertSame('OUT[write]', $result->context[WorkflowEngine::RESULTS_CONTEXT_KEY]['draft']);
    }

    public function testAReservedContextKeyIsRefusedBeforeAnythingRuns(): void
    {
        $engine = $this->engine();

        foreach ([
            'run' => static fn () => $engine->run('three', ['@results' => 'x']),
            'runFromPhp' => static fn () => $engine->runFromPhp(
                static fn (): Workflow => (new WorkflowBuilder())->name('p')->stage('a', Tasks::agent('coder')->prompt('do a'))->build(),
                ['@anything' => 'x'],
            ),
        ] as $entry => $call) {
            try {
                $call();
                self::fail("{$entry}() accepted a reserved context key");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('is reserved', $e->getMessage());
            }
        }

        self::assertSame([], $this->prompts, 'a refused run dispatches nothing');
    }

    public function testResultsSurviveThePauseFileAndAResumedRunInterpolatesThem(): void
    {
        $this->registry->register((new WorkflowBuilder())
            ->name('later')
            ->stage('a', Tasks::agent('coder')->prompt('do a'))
            ->stage('b', Tasks::agent('coder')->prompt('do b'))
            ->stage('c', Tasks::agent('coder')->prompt('c sees {{a.results}} and {{b.results}}'))
            ->build());

        $engine = $this->engine();
        $this->script['do b'] = static function () use ($engine): ?AgentResult {
            $engine->pause('later');

            return null;
        };

        $paused = $engine->run('later');
        self::assertSame(WorkflowStatus::Paused, $paused->status);

        $file = $this->tempDir . '/workflows/.running/later.json';
        $data = json_decode((string) file_get_contents($file), true);
        self::assertSame(
            ['a' => 'OUT[do a]', 'b' => 'OUT[do b]'],
            $data['context'][WorkflowEngine::RESULTS_CONTEXT_KEY] ?? null,
            'the pause file carries the results map',
        );

        // A fresh engine: only the file can supply the results now.
        unset($this->script['do b']);
        $this->prompts = [];
        $resumed = $this->engine()->resume('later');

        self::assertSame(WorkflowStatus::Completed, $resumed->status);
        self::assertSame(['c sees OUT[do a] and OUT[do b]'], $this->prompts);
    }

    public function testAMalformedResultsMapInAPauseFileIsDiscardedNotTrusted(): void
    {
        $this->registry->register((new WorkflowBuilder())
            ->name('later')
            ->stage('a', Tasks::agent('coder')->prompt('do a'))
            ->stage('c', Tasks::agent('coder')->prompt('c sees {{a.results}}'))
            ->build());

        $engine = $this->engine();
        $run = $engine->run('later');
        $engine->pause($run->workflowId);

        $file = $this->tempDir . '/workflows/.running/later.json';
        $data = json_decode((string) file_get_contents($file), true);
        $data['stagesCompleted'] = 1;
        $data['stageResults'] = array_slice($data['stageResults'], 0, 1);
        $data['context'][WorkflowEngine::RESULTS_CONTEXT_KEY] = 'not a map';
        file_put_contents($file, json_encode($data));
        $this->prompts = [];

        $resumed = $engine->resume('later');

        self::assertSame(WorkflowStatus::Completed, $resumed->status);
        self::assertSame(['c sees {{a.results}}'], $this->prompts);
    }

    private function engine(): WorkflowEngine
    {
        return new WorkflowEngine($this->registry, new AgentWorkerPool(1, $this->executor()));
    }

    private function executor(): ExecutorInterface
    {
        return new class ($this) implements ExecutorInterface {
            public function __construct(private readonly WorkflowAgentResultsTest $test)
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

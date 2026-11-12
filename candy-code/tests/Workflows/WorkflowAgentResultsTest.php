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
use SugarCraft\Code\Workflows\Workflow;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;

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

        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_results_' . uniqid('', true);
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
        // first unclaimed slot. Entries carry the stage's schema flag, which
        // must ride through to the record.
        $paired = $records->invoke(null, [
            'id-1' => ['key' => 'one', 'schema' => false],
            'id-2' => ['key' => 'two', 'schema' => true],
            'id-3' => ['key' => 'three', 'schema' => false],
        ], [
            $finished('id-3', 'third'),
            $finished('foreign', 'unidentified'),
            $finished('id-1', 'first'),
        ]);

        self::assertSame(
            ['one' => 'first', 'two' => 'unidentified', 'three' => 'third'],
            array_column(array_map(static fn (array $r): array => [$r['key'], $r['result']->output], $paired), 1, 0),
        );
        self::assertSame(
            ['one' => false, 'two' => true, 'three' => false],
            array_column(array_map(static fn (array $r): array => [$r['key'], $r['schema']], $paired), 1, 0),
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

    public function testASchemaStageStoresATypedRowBesideTheTextRow(): void
    {
        $this->script['emit'] = fn (SubAgent $agent): AgentResult => $this->typedResult($agent, '{"summary":"green","items":[{"id":7}]}');

        $result = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('typed')
            ->stage('build', Tasks::agent('coder')->prompt('emit json')->outputSchema(['type' => 'object']))
            ->build());

        $document = '{"summary":"green","items":[{"id":7}]}';

        self::assertSame(WorkflowStatus::Completed, $result->status);
        self::assertSame(
            ['build' => $document],
            $result->context[WorkflowEngine::RESULTS_CONTEXT_KEY],
            'the text row keeps the raw bytes and nothing else joins its map',
        );
        self::assertSame(
            ['build' => $document],
            $result->context[WorkflowEngine::TYPED_RESULTS_CONTEXT_KEY] ?? null,
            'the typed row rides in its own map, keyed by the raw result name',
        );
    }

    public function testPointerReferencesReachTypedLeavesAndMissesStayVerbatim(): void
    {
        $this->script['emit'] = fn (SubAgent $agent): AgentResult => $this->typedResult(
            $agent,
            '{"summary":"green","items":[{"id":7}],"a":{"b":"nested"}}',
        );

        $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('typed')
            ->stage('build', Tasks::agent('coder')->prompt('emit json')->outputSchema(['type' => 'object']))
            ->stage(
                'next',
                Tasks::agent('coder')->prompt(
                    'S={{build.summary}} I={{build.items/0/id}} L={{build./summary}}'
                    . ' B={{build.bogus}} N={{build.items}} X={{nothere.summary}} D={{build.a.b}}',
                ),
            )
            ->build());

        self::assertSame(
            'S=green I=7 L=green B={{build.bogus}} N={{build.items}} X={{nothere.summary}} D={{build.a.b}}',
            end($this->prompts),
            'a hit resolves, every miss — unknown leaf, interior object, unknown stage, dotted literal — stays loud',
        );
    }

    public function testPointerEscapesFollowRfc6901Order(): void
    {
        // Keys: "a/b", "c~d", "~1". The last one only survives the ~1-before-~0
        // unescape order: reversing it would route "~01" to a key named "/".
        $this->script['encode'] = fn (SubAgent $agent): AgentResult => $this->typedResult(
            $agent,
            json_encode(['a/b' => 'slash', 'c~d' => 'tilde', '~1' => 'deep']),
        );

        $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('escapes')
            ->stage('coded', Tasks::agent('coder')->prompt('encode keys')->outputSchema(['type' => 'object']))
            ->stage('next', Tasks::agent('coder')->prompt('{{coded.a~1b}}|{{coded.c~0d}}|{{coded.~01}}'))
            ->build());

        self::assertSame('slash|tilde|deep', end($this->prompts));
    }

    public function testScalarLeavesInterpolateAndInteriorOrNullDoNot(): void
    {
        $this->script['shape'] = fn (SubAgent $agent): AgentResult => $this->typedResult(
            $agent,
            '{"ok":true,"no":false,"count":12,"nothing":null,"obj":{"x":1}}',
        );

        $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('scalars')
            ->stage('shaped', Tasks::agent('coder')->prompt('shape out')->outputSchema(['type' => 'object']))
            ->stage('next', Tasks::agent('coder')->prompt(
                '{{shaped.ok}}|{{shaped.no}}|{{shaped.count}}|{{shaped.nothing}}|{{shaped.obj}}',
            ))
            ->build());

        self::assertSame(
            'true|false|12|{{shaped.nothing}}|{{shaped.obj}}',
            end($this->prompts),
            'bools spell true/false; a null leaf and an interior object miss verbatim',
        );
    }

    public function testASchemaStageWhoseOutputIsNotJsonStaysTextOnly(): void
    {
        $this->script['babble'] = fn (SubAgent $agent): AgentResult => $this->typedResult($agent, 'not json at all');

        $result = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('coercion')
            ->stage('babbler', Tasks::agent('coder')->prompt('babble words')->outputSchema(['type' => 'object']))
            ->stage('next', Tasks::agent('coder')->prompt('{"n":1} and {{babbler.n}}'))
            ->build());

        self::assertSame(
            ['babbler' => 'not json at all', 'next' => 'OUT[{"n":1} and {{babbler.n}}]'],
            $result->context[WorkflowEngine::RESULTS_CONTEXT_KEY],
            'no typed row is invented from prose, and the pointer misses loudly',
        );
        self::assertArrayNotHasKey(
            WorkflowEngine::TYPED_RESULTS_CONTEXT_KEY,
            $result->context,
            'a stage with a schema whose output never parses leaves the typed map unset — no half-typed state',
        );
    }

    public function testReservedTailsBelongToTheirOwnPassesWhateverTheTypedDocumentHolds(): void
    {
        $document = json_encode(['results' => 'FROM-TYPED', 'output' => 'FROM-TYPED', 'inner' => 'keep']);
        $this->script['tail'] = fn (SubAgent $agent): AgentResult => $this->typedResult($agent, (string) $document);

        $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('tails')
            ->stage('tailer', Tasks::agent('coder')->prompt('tail the token')->outputSchema(['type' => 'object']))
            ->stage('next', Tasks::agent('coder')->prompt('{{tailer.results}}|{{tailer.output}}|{{tailer./output}}'))
            ->build());

        self::assertSame(
            $document . '|' . $document . '|FROM-TYPED',
            end($this->prompts),
            'the bare results/output tails keep their dedicated rows; only the leading-slash pointer form reaches document members',
        );
    }

    public function testThePointerPassRunsBeforeTheOutputPassAndNeverReReadsItsPredecessor(): void
    {
        $this->script['weave'] = fn (SubAgent $agent): AgentResult => $this->typedResult(
            $agent,
            '{"next":"{{c.output}}","again":"{{b.results}}","plain":"{{a.results}}"}',
        );

        $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('ordering')
            ->stage('a', Tasks::agent('coder')->prompt('do a'))
            ->stage('b', Tasks::agent('coder')->prompt('weave tokens')->outputSchema(['type' => 'object']))
            ->stage('c', Tasks::agent('coder')->prompt('do c'))
            ->stage('d', Tasks::agent('coder')->prompt('{{b.next}}|{{b.again}}|{{b.plain}}'))
            ->build());

        self::assertSame(
            'OUT[do c]|{{b.results}}|{{a.results}}',
            end($this->prompts),
            'an emitted {{stage.output}} is still ahead of its pass and resolves;' .
            ' emitted .results tokens are behind theirs and stay verbatim',
        );
    }

    public function testDottedTaskNamesNeverShareASlotWithTypedRows(): void
    {
        // Fan-out names tasks by relative path, so a result key may itself end
        // in ".json" or contain dots. Text rows and typed rows live in separate
        // maps, which makes both collision directions structurally impossible:
        // a dotted text row impersonating a typed leaf, and a typed row
        // clobbering a dotted text row. Both orders are pinned.
        $green = '{"summary":"green"}';
        $impostor = '{"summary":"IMPOSTOR"}';
        $this->script['emit two'] = fn (SubAgent $agent): AgentResult => $this->typedResult($agent, $green);
        $this->script['raw one'] = fn (SubAgent $agent): AgentResult => $this->typedResult($agent, $impostor);

        $schemaFirst = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('both-orders')
            ->stage('real', Tasks::agent('coder')->name('a')->prompt('emit two')->outputSchema(['type' => 'object']))
            ->stage('dot', Tasks::agent('coder')->name('a.json')->prompt('raw one'))
            ->stage('read', Tasks::agent('coder')->prompt('x={{a.summary}}'))
            ->build());
        self::assertSame(WorkflowStatus::Completed, $schemaFirst->status);
        self::assertSame('x=green', end($this->prompts), 'the dotted text row cannot impersonate the typed leaf');
        self::assertSame($impostor, $schemaFirst->context[WorkflowEngine::RESULTS_CONTEXT_KEY]['a.json'] ?? null);
        self::assertSame(['a' => $green], $schemaFirst->context[WorkflowEngine::TYPED_RESULTS_CONTEXT_KEY] ?? null);

        $this->prompts = [];
        $dotFirst = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('both-orders')
            ->stage('dot', Tasks::agent('coder')->name('a.json')->prompt('raw one'))
            ->stage('real', Tasks::agent('coder')->name('a')->prompt('emit two')->outputSchema(['type' => 'object']))
            ->stage('read', Tasks::agent('coder')->prompt('x={{a.summary}} y={{a.results}}'))
            ->build());
        self::assertSame(WorkflowStatus::Completed, $dotFirst->status);
        self::assertSame(
            'x=green y=' . $green,
            end($this->prompts),
            'the later typed store cannot clobber the dotted text row either',
        );
        self::assertSame($impostor, $dotFirst->context[WorkflowEngine::RESULTS_CONTEXT_KEY]['a.json'] ?? null);
        self::assertSame($green, $dotFirst->context[WorkflowEngine::RESULTS_CONTEXT_KEY]['a'] ?? null);
        self::assertSame(['a' => $green], $dotFirst->context[WorkflowEngine::TYPED_RESULTS_CONTEXT_KEY] ?? null);
    }

    private function typedResult(SubAgent $agent, string $output): AgentResult
    {
        return new AgentResult(
            agentId: $agent->id,
            status: AgentStatus::Completed,
            output: $output,
            tokensUsed: 3,
            costUsd: 0.1,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
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

    // ------------------------------------------------------- C9 input: fan-in joins

    public function testTheFourJoinShapesRenderPriorRowsThroughTheHandles(): void
    {
        $expected = [
            'first' => 'M OUT[do a]',
            'file-list' => "M OUT[do a]\nOUT[do b]",
            'all' => "M === a ===\nOUT[do a]\n=== b ===\nOUT[do b]",
            'results' => 'M {"a":"OUT[do a]","b":"OUT[do b]"}',
        ];

        foreach ($expected as $as => $want) {
            $this->prompts = [];
            $result = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
                ->name('shape-' . $as)
                ->stage('a', Tasks::agent('coder')->prompt('do a'))
                ->stage('b', Tasks::agent('coder')->prompt('do b'))
                ->stage('m', Tasks::agent('coder')->prompt('M {{input}}')->input(['from' => ['a', 'b'], 'as' => $as]))
                ->build());

            self::assertSame(WorkflowStatus::Completed, $result->status, $as);
            self::assertSame($want, end($this->prompts), "as: {$as} joins prior rows under the fixed handle");
        }

        $this->prompts = [];
        $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('shape-handles')
            ->stage('a', Tasks::agent('coder')->prompt('do a'))
            ->stage('m', Tasks::agent('coder')->prompt('R={{input.results}} D={{input.output}}')
                ->input(['from' => ['a'], 'as' => 'first']))
            ->build());

        self::assertSame(
            'R=OUT[do a] D={{input.output}}',
            end($this->prompts),
            'the results handle reads the same local row; .output is not a join spelling and stays loud',
        );
    }

    public function testAPointerReachesInsideTheJoinedResultsDocument(): void
    {
        $this->script['emit'] = fn (SubAgent $agent): AgentResult => $this->typedResult($agent, '{"summary":"green"}');

        $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('typed-join')
            ->stage('build', Tasks::agent('coder')->prompt('emit json')->outputSchema(['type' => 'object']))
            ->stage('ship', Tasks::agent('coder')->prompt('S={{input.build/summary}} D={{input.build}}')
                ->input(['from' => ['build'], 'as' => 'results']))
            ->build());

        self::assertSame(
            'S=green D={{input.build}}',
            end($this->prompts),
            'as: results injects a typed row so C3 pointers traverse the joined document (slash-pointer law: the '
            . 'dot only names the row); an interior object leaf stays verbatim exactly as the pointer law spells it',
        );
    }

    public function testFileListDegradesToTheNoteAndTheOtherShapesRefuseWhenEverySourceIsSkipped(): void
    {
        $clauses = static fn (): array => ['stage' => 'gate', 'equals' => 'NEVER'];

        $this->prompts = [];
        $degrade = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('skip-file-list')
            ->stage('gate', Tasks::agent('coder')->prompt('do gate'))
            ->stage('b', Tasks::agent('coder')->prompt('do b'))->when($clauses())
            ->stage('m', Tasks::agent('coder')->prompt('M {{input}}')->input(['from' => ['b'], 'as' => 'file-list']))
            ->build());
        self::assertSame(WorkflowStatus::Completed, $degrade->status);
        self::assertSame('M [input: no sources joined]', end($this->prompts), 'file-list is a manifest: empty is honest');

        foreach (['first', 'all', 'results'] as $as) {
            $result = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
                ->name('skip-' . $as)
                ->stage('gate', Tasks::agent('coder')->prompt('do gate'))
                ->stage('b', Tasks::agent('coder')->prompt('do b'))->when($clauses())
                ->stage('m', Tasks::agent('coder')->prompt('M {{input}}')->input(['from' => ['b'], 'as' => $as]))
                ->build());

            self::assertSame(WorkflowStatus::Failed, $result->status, $as);
            self::assertStringContainsString('every source is missing', (string) $result->firstFailure()?->error);
        }
    }

    public function testASkippedSourceVanishesFromAnOtherwiseLiveJoin(): void
    {
        $result = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('half-skip')
            ->stage('gate', Tasks::agent('coder')->prompt('do gate'))
            ->stage('a', Tasks::agent('coder')->prompt('do a'))
            ->stage('b', Tasks::agent('coder')->prompt('do b'))->when(['stage' => 'a', 'equals' => 'NEVER'])
            ->stage('m', Tasks::agent('coder')->prompt('M {{input}}')->input(['from' => ['a', 'b'], 'as' => 'all']))
            ->build());

        self::assertSame(WorkflowStatus::Completed, $result->status);
        self::assertSame("M === a ===\nOUT[do a]", end($this->prompts), 'absent sources are skipped, not mourned');
    }

    public function testTheJoinReDerivesByteIdenticalAcrossPauseAndResume(): void
    {
        foreach (['first', 'results'] as $as) {
            $name = 'pause-' . $as;
            $this->registry->register((new WorkflowBuilder())
                ->name($name)
                ->stage('a', Tasks::agent('coder')->prompt('do a'))
                ->stage('b', Tasks::agent('coder')->prompt('do b'))
                ->stage('m', Tasks::agent('coder')->prompt('M {{input}} R={{input.results}}')
                    ->input(['from' => ['a', 'b'], 'as' => $as]))
                ->build());

            $engine = $this->engine();
            $this->script['do b'] = static function () use ($engine, $name): ?AgentResult {
                $engine->pause($name);

                return null;
            };

            $paused = $engine->run($name);
            self::assertSame(WorkflowStatus::Paused, $paused->status);

            $raw = (string) file_get_contents($this->tempDir . '/workflows/.running/' . $name . '.json');
            self::assertStringNotContainsString(
                '"input"',
                $raw,
                'the pause record never carries a join row — the join is re-derived from recorded rows, not replayed',
            );

            unset($this->script['do b']);
            $this->prompts = [];
            $resumed = $this->engine()->resume($name);
            self::assertSame(WorkflowStatus::Completed, $resumed->status);

            $joinPrompts = array_values(array_filter(
                $this->prompts,
                static fn (string $prompt): bool => str_starts_with($prompt, 'M '),
            ));
            self::assertCount(1, $joinPrompts, 'the joining stage dispatches exactly once, after the resume');

            $joined = $as === 'first'
                ? 'OUT[do a]'
                : '{"a":"OUT[do a]","b":"OUT[do b]"}';
            self::assertSame(
                'M ' . $joined . ' R=' . $joined,
                $joinPrompts[0],
                'resumed join bytes equal what a live run would have built from the same rows',
            );
        }
    }

    public function testTheJoinReachesTheVerificationTaskPrompt(): void
    {
        $result = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('verify-task-join')
            ->stage('a', Tasks::agent('coder')->prompt('do a'))
            ->withVerification(
                'v',
                Tasks::agent('coder')->prompt('V {{input}}')->input(['from' => ['a'], 'as' => 'first']),
                // chr(63) spelling: a bare 'ok?' is a glob-shaped literal ('?' wildcard)
                // and would drift the GlobDialect corpus figure.
                Tasks::agent('coder')->prompt('ok' . chr(63)),
            )
            ->build());

        self::assertSame(WorkflowStatus::Completed, $result->status);
        self::assertSame('V OUT[do a]', $this->prompts[1], 'the verification task prompt carries the join');
    }

    public function testTheJoinReachesTheVerifierPrompt(): void
    {
        $result = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('verify-verifier-join')
            ->stage('a', Tasks::agent('coder')->prompt('do a'))
            ->withVerification(
                'v2',
                Tasks::agent('coder')->prompt('work v2'),
                Tasks::agent('coder')->prompt('OK? {{input}}')->input(['from' => ['a'], 'as' => 'first']),
            )
            ->build());

        self::assertSame(WorkflowStatus::Completed, $result->status);
        // chr(63) spelling for the same GlobDialect reason as above.
        $marker = 'OK' . chr(63);
        $verifierPrompts = array_values(array_filter(
            $this->prompts,
            static fn (string $prompt): bool => str_starts_with($prompt, $marker),
        ));
        self::assertSame(['OK? OUT[do a]'], $verifierPrompts, 'the verifier prompt carries the join too');
    }

    public function testTheJoinReachesEveryParallelTaskPrompt(): void
    {
        $result = $this->engine()->runFromPhp(static fn (): Workflow => (new WorkflowBuilder())
            ->name('parallel-join')
            ->stage('a', Tasks::agent('coder')->prompt('do a'))
            ->parallel('p', [
                Tasks::agent('coder')->prompt('P1 {{input}}')->input(['from' => ['a'], 'as' => 'first']),
                Tasks::agent('coder')->prompt('P2 {{input}}')->input(['from' => ['a'], 'as' => 'first']),
            ])
            ->build());

        self::assertSame(WorkflowStatus::Completed, $result->status);
        self::assertContains('P1 OUT[do a]', $this->prompts, 'task zero dispatches its own resolved prompt');
        self::assertContains('P2 OUT[do a]', $this->prompts, 'and so does every task after it');
    }

    public function testEveryPromptInterpolationCarriesTheJoin(): void
    {
        // Structural census over the engine source: every prompt that travels
        // through interpolateContext either resolves the join first, or is the
        // uses-carry surface where no task of the carried stage is in scope.
        // This is also the discriminator for the parallel-first leg, whose
        // interpolation seeds request metadata while the dispatched content
        // rides the per-task line the parallel pin above proves.
        $enginePath = (string) (new \ReflectionClass(WorkflowEngine::class))->getFileName();
        $needle = '$this->interpolateContext(';
        $joined = 0;
        $carried = 0;

        foreach (explode("\n", (string) file_get_contents($enginePath)) as $line) {
            if (strpos($line, $needle) === false) {
                continue;
            }

            if (str_contains($line, 'self::resolveStageInput(')) {
                ++$joined;
                continue;
            }

            self::assertTrue(
                str_contains($line, '$innerContext['),
                'a prompt interpolation that skips the join must be the uses-carry surface: ' . trim($line),
            );
            ++$carried;
        }

        self::assertSame(7, $joined, 'seven prompt paths resolve the join before interpolating (P1-4 adds the verifier-panel vote)');
        self::assertSame(1, $carried, 'exactly one surface is join-less by design');
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

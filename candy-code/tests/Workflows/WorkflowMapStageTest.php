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
use SugarCraft\Code\Workflows\WorkflowLinter;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowResult;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * P1-2: `type: map` — every `from:` item flows through `steps:`
 * independently, with no barrier between steps; item-scoped `{{prevResult}}`
 * and step results, per-item `when:` guards, a failed step dropping only its
 * item, `minSuccess` over items, a pointer-addressable JSON output, and real
 * overlap whenever a dispatch waits (the pool suspends the item's Fiber).
 */
final class WorkflowMapStageTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;

    /** @var list<string> dispatch log: "start <prompt>" / "end <prompt>" */
    public array $events = [];

    /** @var list<string> */
    public array $prompts = [];

    /** @var \Closure(SubAgent): AgentResult */
    public \Closure $answer;

    /** @var \Closure(string): int how many times a dispatch of this prompt suspends its Fiber before answering */
    public \Closure $waits;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_map_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
        $this->registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $this->answer = fn (SubAgent $a): AgentResult => $this->ok($a, 'OUT[' . $a->task . ']');
        $this->waits = static fn (string $prompt): int => 0;
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->tempDir);
    }

    // ------------------------------------------------------------------ grammar

    public function testTheYamlMapNormalizes(): void
    {
        $workflow = $this->registry->fromYamlString(<<<'YAML'
            name: m
            stages:
              - name: find
                prompt: f
                outputSchema: {type: object}
              - name: per
                type: map
                from: {stage: find, pointer: /findings, key: /id, chunk: 3}
                minSuccess: 50%
                steps:
                  - name: refute
                    prompt: "Refute {{item.claim}}"
                    outputSchema: {type: object}
                  - name: fix
                    when: {step: refute, pointer: /refuted, equals: "false"}
                    prompt: "Fix {{item.claim}}: {{prevResult}}"
                  - name: check
                    type: verification
                    task: {prompt: "re-run"}
                    verifier: {prompt: "judge {{lens}}", reject: '/NO/', votes: 2, lenses: [a, b]}
            YAML);

        $stage = $workflow->stages[1];
        self::assertSame('map', $stage['type']);
        self::assertSame(['stage' => 'find', 'pointer' => '/findings', 'key' => '/id', 'chunk' => 3], $stage['from']);
        self::assertSame(['percent' => 50], $stage['minSuccess']);
        self::assertSame(['refute', 'fix', 'check'], array_column($stage['steps'], 'name'));
        self::assertSame('stage', $stage['steps'][0]['type']);
        self::assertSame('refute', $stage['steps'][0]['tasks'][0]->name);
        self::assertSame(['stage' => 'refute', 'pointer' => '/refuted', 'op' => 'equals', 'value' => 'false'], $stage['steps'][1]['when']);
        self::assertSame('verification', $stage['steps'][2]['type']);
        self::assertSame(2, $stage['steps'][2]['verifier']->votes);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedMaps(): iterable
    {
        $head = "  - name: per\n    type: map\n";
        $from = "    from: {context: xs}\n";
        $step = "    steps:\n      - {name: a, prompt: p}\n";

        yield 'no from' => [$head . $step, 'is type: map, so it needs a "from" source'];
        yield 'no steps' => [$head . $from, 'is type: map, so it needs a "steps" list'];
        yield 'empty steps' => [$head . $from . "    steps: []\n", '"steps" must list at least one step'];
        yield 'steps as a map' => [$head . $from . "    steps: {a: {prompt: p}}\n", '"steps" must be a list, got a map'];
        yield 'an unnamed step' => [$head . $from . "    steps:\n      - {prompt: p}\n", 'step #1 needs a "name"'];
        yield 'a duplicate step' => [$head . $from . "    steps:\n      - {name: a, prompt: p}\n      - {name: a, prompt: q}\n", "reuses the step name 'a'"];
        yield 'a parallel step' => [$head . $from . "    steps:\n      - {name: a, type: parallel, prompt: p}\n", 'declares type: parallel; a map step is a task map or "type: verification"'];
        yield 'a map step' => [$head . $from . "    steps:\n      - {name: a, type: map, prompt: p}\n", 'declares type: map'];
        yield 'a uses step' => [$head . $from . "    steps:\n      - {name: a, uses: other}\n", 'carries "uses", which a map step cannot'];
        yield 'a guard on a later step' => [$head . $from . "    steps:\n      - {name: a, prompt: p, when: {step: b, equals: x}}\n      - {name: b, prompt: q}\n", "\"when\" reads step 'b', which has not run yet for the item"];
        yield 'a guard on itself' => [$head . $from . "    steps:\n      - {name: a, prompt: p, when: {step: a, equals: x}}\n", "\"when\" reads step 'a', which has not run yet"];
        yield 'step and stage both' => [$head . $from . "    steps:\n      - {name: a, prompt: p}\n      - {name: b, prompt: q, when: {step: a, stage: a, equals: x}}\n", 'leaf carries both "step" and "stage"'];
        yield 'a task key on the stage' => [$head . $from . "    prompt: p\n" . $step, 'is type: map, so "prompt" belongs on a step, not on the stage'];
        yield 'an agents body' => [$head . $from . "    agents: []\n" . $step, 'its body is "steps"; it cannot carry "agents"'];
        yield 'steps on a plain stage' => ["  - name: solo\n    prompt: p\n" . $step, 'carries "steps", which only a "type: map" stage runs'];
        yield 'parallel flag beside map' => ["  - name: per\n    type: map\n    parallel: true\n" . $from . $step, 'contradicts "type: map"'];
        yield 'an unknown verification-step key' => [$head . $from . "    steps:\n      - {name: a, type: verification, task: {prompt: t}, verifier: {prompt: v}, prompt: x}\n", 'unknown'];
        yield 'from a later stage' => [$head . "    from: {stage: later, pointer: /x}\n" . $step . "  - name: later\n    prompt: p\n", '"stage": "later" is not a stage declared before this one'];
    }

    /**
     * @dataProvider refusedMaps
     */
    public function testAMalformedMapDiesAtLoad(string $stages, string $needle): void
    {
        try {
            $this->registry->fromYamlString("name: m\nstages:\n" . $stages);
            self::fail("'{$needle}' still loaded");
        } catch (WorkflowLoadException $e) {
            self::assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function testTheBuilderSpellsTheSameShape(): void
    {
        $stage = (new WorkflowBuilder())->name('m')
            ->map('per', ['context' => 'xs', 'max' => 5], [
                Tasks::agent('a')->name('first')->prompt('p'),
                WorkflowBuilder::mapStep(Tasks::agent('a')->name('second')->prompt('q'), ['step' => 'first', 'contains' => 'ok']),
                WorkflowBuilder::mapVerification('third', Tasks::agent('a')->prompt('t'), Tasks::agent('v')->prompt('v')->votes(3)),
            ])
            ->minSuccess(2)
            ->loop(['max' => 2, 'until' => ['stage' => 'per', 'contains' => 'x']])
            ->build()->stages[0];

        self::assertSame(['context' => 'xs', 'chunk' => null, 'max' => 5], $stage['from']);
        self::assertSame(['first', 'second', 'third'], array_column($stage['steps'], 'name'));
        self::assertSame(['stage' => 'first', 'op' => 'contains', 'value' => 'ok'], $stage['steps'][1]['when']);
        self::assertSame(['count' => 2], $stage['minSuccess']);
        self::assertSame(2, $stage['loop']['max']);

        foreach ([
            static fn () => (new WorkflowBuilder())->map('m', [], [Tasks::agent('a')->name('s')]),
            static fn () => (new WorkflowBuilder())->map('m', ['context' => 'xs'], []),
            static fn () => (new WorkflowBuilder())->map('m', ['context' => 'xs'], [Tasks::agent('a')]),
            static fn () => (new WorkflowBuilder())->map('m', ['context' => 'xs'], ['nope']),
            static fn () => (new WorkflowBuilder())->map('m', ['context' => 'xs'], [['type' => 'parallel']]),
        ] as $i => $attempt) {
            try {
                $attempt();
                self::fail("attempt #{$i} was accepted");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ------------------------------------------------------------------ run time

    public function testEachItemFlowsThroughItsStepsWithItemScopedResults(): void
    {
        $this->answer = function (SubAgent $a): AgentResult {
            if (str_starts_with($a->task, 'refute')) {
                return $this->ok($a, str_contains($a->task, 'refute x') ? '{"refuted":true}' : '{"refuted":false}');
            }

            return $this->ok($a, 'OUT[' . $a->task . ']');
        };

        $result = $this->runYaml(<<<'YAML'
            name: m
            stages:
              - name: per
                type: map
                from: {context: findings, key: /id}
                steps:
                  - name: refute
                    prompt: "refute {{item.claim}} #{{index}}"
                    outputSchema: {type: object}
                  - name: fix
                    when: {step: refute, pointer: /refuted, equals: "false"}
                    prompt: "fix {{item.id}} after {{prevResult}} / {{refute.refuted}}"
                  - name: note
                    prompt: "note {{item.id}}: prev={{prevResult}} fix={{fix.output}}"
              - name: report
                prompt: "report {{per.items/1/steps/fix/output}} {{per.items/0/steps/fix/status}}"
            YAML, ['findings' => [['id' => 'A1', 'claim' => 'x'], ['id' => 'B2', 'claim' => 'y'], ['id' => 'A1', 'claim' => 'dup']]]);

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame([
            'refute x #0',
            'note A1: prev={"refuted":true} fix={{fix.output}}',
            'refute y #1',
            'fix B2 after {"refuted":false} / false',
            'note B2: prev=OUT[fix B2 after {"refuted":false} / false] fix=OUT[fix B2 after {"refuted":false} / false]',
            'report OUT[fix B2 after {"refuted":false} / false] skipped',
        ], $this->prompts, 'a skipped step passes prevResult through; the duplicate key is deduped; results never leak between items');

        $document = json_decode((string) $result->stageResults[0]->output, true);
        self::assertSame(['items'], array_keys($document));
        self::assertSame(['key' => 'A1', 'index' => 0, 'status' => 'completed'], array_slice($document['items'][0], 0, 3));
        self::assertSame([
            'refute' => ['status' => 'completed', 'output' => '{"refuted":true}', 'data' => ['refuted' => true]],
            'fix' => ['status' => 'skipped'],
            'note' => ['status' => 'completed', 'output' => 'OUT[note A1: prev={"refuted":true} fix={{fix.output}}]'],
        ], $document['items'][0]['steps']);
        self::assertSame($result->stageResults[0]->output, $result->context['@results']['per'], 'the document is the stage result');
        self::assertArrayNotHasKey('refute', $result->context['@results'], 'step results are item-scoped');
        self::assertCount(5, $result->stageResults[0]->agents, 'every step dispatch is billed');
    }

    public function testAFailedStepDropsOnlyItsItemAndFailsTheStageWithoutMinSuccess(): void
    {
        $this->answer = fn (SubAgent $a): AgentResult => $a->task === 'one b'
            ? new AgentResult(agentId: $a->id, status: AgentStatus::Failed, error: new \RuntimeException('b broke'), completedAt: new \DateTimeImmutable())
            : $this->ok($a, 'ok');

        $result = $this->runYaml(
            "name: m\nstages:\n  - name: per\n    type: map\n    from: {context: xs}\n    steps:\n"
            . "      - {name: one, prompt: 'one {{item}}'}\n      - {name: two, prompt: 'two {{item}}'}\n",
            ['xs' => ['a', 'b', 'c']],
        );

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame(['one a', 'two a', 'one b', 'one c', 'two c'], $this->prompts, 'item b never reaches step two; c still runs');
        self::assertSame("Map stage 'per': 1 of 3 items failed: per_2 (step 'one' failed: b broke)", $result->firstFailure()?->error);
        $items = json_decode((string) $result->stageResults[0]->output, true)['items'];
        self::assertSame(['completed', 'failed', 'completed'], array_column($items, 'status'));
        self::assertSame(['one'], array_keys($items[1]['steps']));
    }

    public function testMinSuccessCountsItemsAndListsTheCasualties(): void
    {
        $this->answer = fn (SubAgent $a): AgentResult => $a->task === 'go k2'
            ? new AgentResult(agentId: $a->id, status: AgentStatus::TimedOut, error: new \RuntimeException('slow'), completedAt: new \DateTimeImmutable())
            : $this->ok($a, 'ok');

        $yaml = static fn (string $min): string => "name: m\nstages:\n  - name: per\n    type: map\n    from: {context: xs, key: /k}\n    minSuccess: {$min}\n"
            . "    steps:\n      - {name: go, prompt: 'go {{item.k}}'}\n  - name: retry\n    prompt: 'retry {{per.failed}}'\n";
        $xs = ['xs' => [['k' => 'k1'], ['k' => 'k2'], ['k' => 'k3']]];

        $met = $this->runYaml($yaml('2'), $xs);
        self::assertSame(WorkflowStatus::Completed, $met->status, (string) $met->firstFailure()?->error);
        self::assertSame('retry ["k2"]', end($this->prompts));

        $this->prompts = [];
        $missed = $this->runYaml($yaml("'100%'"), $xs);
        self::assertSame(WorkflowStatus::Failed, $missed->status);
        self::assertSame("minSuccess 100% not met: 2 of 3 items succeeded and 3 were needed; failed: k2 (step 'go' failed: slow)", $missed->firstFailure()?->error);
    }

    public function testAVerificationStepConvenesItsPanelPerItem(): void
    {
        $this->answer = fn (SubAgent $a): AgentResult => $this->ok($a, $a->task === 'judge b via x' ? 'REJECT' : 'fine ' . $a->task);

        $result = $this->runYaml(
            "name: m\nstages:\n  - name: per\n    type: map\n    from: {context: xs}\n    minSuccess: 1\n    steps:\n"
            . "      - name: check\n        type: verification\n        task: {prompt: 'work {{item}}'}\n"
            . "        verifier: {prompt: 'judge {{item}} via {{lens}}', reject: '/REJECT/', votes: 2, lenses: [x, y], quorum: 2}\n"
            . "      - {name: after, prompt: 'after {{item}} saw {{check.votes}}'}\n",
            ['xs' => ['a', 'b']],
        );

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        $items = json_decode((string) $result->stageResults[0]->output, true)['items'];
        self::assertSame('completed', $items[0]['status']);
        self::assertSame([false, false], array_column($items[0]['steps']['check']['votes'], 'rejected'));
        self::assertSame('failed', $items[1]['status']);
        self::assertStringContainsString('verdict rejected by the panel: 1 of 2 votes passed and the quorum is 2; vote 1 (x) matched /REJECT/', $items[1]['error']);
        self::assertContains('after a saw [{"lens":"x","rejected":false,"output":"fine judge a via x"},{"lens":"y","rejected":false,"output":"fine judge a via y"}]', $this->prompts);
    }

    public function testItemsDoNotWaitForEachOtherBetweenSteps(): void
    {
        // Item a's first step waits for five polls; item b's steps answer at
        // once. With no barrier b runs BOTH its steps while a is still in
        // its first.
        $this->waits = static fn (string $prompt): int => $prompt === 'one a' ? 5 : 0;

        $result = $this->runYaml(
            "name: m\nconfig: {maxConcurrent: 2}\nstages:\n  - name: per\n    type: map\n    from: {context: xs}\n    steps:\n"
            . "      - {name: one, prompt: 'one {{item}}'}\n      - {name: two, prompt: 'two {{item}}'}\n",
            ['xs' => ['a', 'b']],
        );

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertLessThan(array_search('end one a', $this->events, true), array_search('end two b', $this->events, true), implode(', ', $this->events));
        self::assertSame(['start one a', 'start one b'], array_slice($this->events, 0, 2), 'both chains are admitted up front');
    }

    public function testTheWidthBoundsTheChainsInFlight(): void
    {
        $this->waits = static fn (string $prompt): int => 1;

        $result = $this->runYaml(
            "name: m\nconfig: {maxConcurrent: 1}\nstages:\n  - name: per\n    type: map\n    from: {context: xs}\n    steps:\n"
            . "      - {name: one, prompt: 'one {{item}}'}\n      - {name: two, prompt: 'two {{item}}'}\n",
            ['xs' => ['a', 'b']],
        );

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(
            ['start one a', 'end one a', 'start two a', 'end two a', 'start one b', 'end one b', 'start two b', 'end two b'],
            $this->events,
            'one chain at a time, admitted as the previous finishes',
        );
    }

    public function testAnEmptyDataListCompletesWithNoItems(): void
    {
        $result = $this->runYaml(
            "name: m\nstages:\n  - name: per\n    type: map\n    from: {context: xs}\n    minSuccess: 1\n    steps:\n      - {name: one, prompt: p}\n",
            ['xs' => []],
        );

        self::assertSame(WorkflowStatus::Completed, $result->status);
        self::assertSame('{"items":[]}', $result->stageResults[0]->output);
        self::assertSame('[]', $result->context['per.failed']);
        self::assertSame([], $this->prompts);
    }

    public function testATruncationIsRecordedInTheDocument(): void
    {
        $result = $this->runYaml(
            "name: m\nstages:\n  - name: per\n    type: map\n    from: {context: xs, max: 1}\n    steps:\n      - {name: one, prompt: 'one {{item}}'}\n",
            ['xs' => ['a', 'b', 'c']],
        );

        self::assertSame(WorkflowStatus::Completed, $result->status);
        $document = json_decode((string) $result->stageResults[0]->output, true);
        self::assertSame(3, $document['matched']);
        self::assertSame(['b', 'c'], $document['dropped']);
        self::assertSame(['one a'], $this->prompts);
    }

    public function testAGlobMapCarriesThePath(): void
    {
        mkdir($this->tempDir . '/src');
        touch($this->tempDir . '/src/a.php');
        touch($this->tempDir . '/src/b.php');

        $result = $this->runYaml(
            "name: m\nstages:\n  - name: per\n    type: map\n    from: {glob: 'src/*.php'}\n    steps:\n      - {name: one, prompt: 'read {{path}} = {{item}}'}\n",
            [],
            $this->tempDir,
        );

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(['read src/a.php = src/a.php', 'read src/b.php = src/b.php'], $this->prompts);
        self::assertSame(['src/a.php', 'src/b.php'], array_column(json_decode((string) $result->stageResults[0]->output, true)['items'], 'key'));
    }

    public function testTheAgentCeilingStopsAdmittingItemsAndFailsTheStage(): void
    {
        $result = $this->runYaml(
            "name: m\nconfig: {maxAgents: 3, maxConcurrent: 1}\nstages:\n  - name: per\n    type: map\n    from: {context: xs}\n    minSuccess: 1\n    steps:\n"
            . "      - {name: one, prompt: 'one {{item}}'}\n      - {name: two, prompt: 'two {{item}}'}\n",
            ['xs' => ['a', 'b', 'c']],
        );

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertStringContainsString('agent ceiling reached (3)', (string) $result->firstFailure()?->error);
        self::assertSame(['one a', 'two a', 'one b'], $this->prompts);
        $items = json_decode((string) $result->stageResults[0]->output, true)['items'];
        self::assertSame(['completed', 'failed', 'cancelled'], array_column($items, 'status'));
    }

    public function testStopOnFirstFailureAdmitsNoItemAfterTheFirstCasualty(): void
    {
        $this->answer = fn (SubAgent $a): AgentResult => $a->task === 'one a'
            ? new AgentResult(agentId: $a->id, status: AgentStatus::Failed, error: new \RuntimeException('no'), completedAt: new \DateTimeImmutable())
            : $this->ok($a, 'ok');
        $workflow = (new WorkflowBuilder())->name('m')->maxConcurrent(1)->stopOnFirstFailure(true)
            ->map('per', ['context' => 'xs'], [Tasks::agent('a')->name('one')->prompt('one {{item}}')])
            ->build();

        $result = $this->engine()->runWorkflow($workflow, ['xs' => ['a', 'b']]);

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame(['one a'], $this->prompts);
        self::assertSame(['failed', 'cancelled'], array_column(json_decode((string) $result->stageResults[0]->output, true)['items'], 'status'));
    }

    public function testAPanelOrRejectOnAMapTaskStepRefusesTheRun(): void
    {
        $result = $this->runYaml(
            "name: m\nstages:\n  - name: per\n    type: map\n    from: {context: xs}\n    steps:\n      - {name: one, prompt: p, votes: 3}\n",
            ['xs' => ['a']],
        );

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertStringContainsString("Map stage 'per' step 'one': votes, lenses and quorum convene a verifier panel", (string) $result->firstFailure()?->error);
        self::assertSame([], $this->prompts);
    }

    public function testALoopedMapReRunsItsItems(): void
    {
        $result = $this->runYaml(
            "name: m\nstages:\n  - name: per\n    type: map\n    from: {context: xs}\n    loop: {max: 2, until: {stage: per, contains: never}}\n    steps:\n      - {name: one, prompt: 'one {{item}} r{{round}}'}\n",
            ['xs' => ['a']],
        );

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(['one a r1', 'one a r2'], $this->prompts);
    }

    public function testTheMaterialisedListRidesThePauseRecord(): void
    {
        $engine = $this->engine();
        $calls = 0;
        $broke = false;
        $this->answer = function (SubAgent $a) use (&$calls, &$broke, $engine): AgentResult {
            if ($a->task === 'find') {
                $calls++;

                return $this->ok($a, $calls === 1 ? '{"f":["one","two"]}' : '{"f":["changed"]}');
            }
            if ($a->task === 'do two' && !$broke) {
                $broke = true;
                $engine->pause('replay');

                return new AgentResult(agentId: $a->id, status: AgentStatus::Failed, error: new \RuntimeException('two broke'));
            }

            return $this->ok($a, 'ok');
        };
        $this->registry->register((new WorkflowBuilder())->name('replay')
            ->stage('find', Tasks::agent('a')->prompt('find')->outputSchema(['type' => 'object']))
            ->map('v', ['stage' => 'find', 'pointer' => '/f'], [Tasks::agent('a')->name('s')->prompt('do {{item}}')])
            ->build());

        $run = $engine->run('replay');
        self::assertSame(WorkflowStatus::Failed, $run->status);
        $record = json_decode((string) file_get_contents($this->tempDir . '/workflows/.running/replay.json'), true);
        self::assertSame(['items' => ['one', 'two'], 'names' => [null, null], 'chunk' => null], $record['dynamicWaves']['v']);

        $this->prompts = [];
        $resumed = $engine->resume($run->workflowId);

        self::assertSame(WorkflowStatus::Completed, $resumed->status, (string) $resumed->firstFailure()?->error);
        self::assertSame(['do one', 'do two'], $this->prompts, 'the whole stage re-runs from the recorded list; find is not re-read');
        self::assertSame(1, $calls);
    }

    // ------------------------------------------------------------------ lint

    public function testTheLinterReadsStepScopes(): void
    {
        $workflow = $this->registry->fromYamlString(<<<'YAML'
            name: m
            stages:
              - name: per
                type: map
                from: {context: xs}
                minSuccess: 1
                steps:
                  - name: a
                    prompt: "a {{item}} {{index}} {{prevResult}} {{b.results}}"
                  - name: b
                    prompt: "b {{a.results}} {{a.output}} {{prevResult}}"
                    when: {stage: ghost, equals: x}
              - name: after
                prompt: "{{per.output}} {{per.results}} {{per.failed}}"
            YAML);

        $messages = array_map(
            static fn (array $f): string => $f['severity'] . ' ' . $f['stage'] . ' ' . $f['message'],
            WorkflowLinter::lint($workflow, ['xs' => []])->findings,
        );

        self::assertSame([
            'error per/a:"a" {{b.results}} names no producer in this plan: the token survives interpolation and the agent sees the raw braces.',
            'warning per/b when reads "ghost", which is neither an earlier step of this map nor an earlier stage; the guard is never satisfied and the step always skips.',
        ], $messages);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $context
     */
    private function runYaml(string $yaml, array $context, ?string $envRoot = null): WorkflowResult
    {
        return $this->engine($envRoot)->runWorkflow($this->registry->fromYamlString($yaml), $context);
    }

    public function dispatch(SubAgent $agent): AgentResult
    {
        $this->prompts[] = $agent->task;
        $this->events[] = 'start ' . $agent->task;
        // A waiting agent: the pool's own idle() suspends the item's Fiber
        // while a forked child runs; this stands in for it inline.
        for ($n = ($this->waits)($agent->task); $n > 0 && \Fiber::getCurrent() !== null; $n--) {
            \Fiber::suspend();
        }
        $result = ($this->answer)($agent);
        $this->events[] = 'end ' . $agent->task;

        return $result;
    }

    private function ok(SubAgent $agent, string $output): AgentResult
    {
        return new AgentResult(
            agentId: $agent->id,
            status: AgentStatus::Completed,
            output: $output,
            tokensUsed: 1,
            costUsd: 0.01,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    private function engine(?string $envRoot = null): WorkflowEngine
    {
        $executor = new class ($this) implements ExecutorInterface {
            public function __construct(private readonly WorkflowMapStageTest $test)
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

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? self::removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}

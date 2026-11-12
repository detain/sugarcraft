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
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * P1-1: `from:` fans a parallel stage out over DATA — an array in a prior
 * stage's typed row (`stage` + `pointer`) or a run-context list (`context`)
 * — with `key:` dedup/naming, `{{item}}`/`{{item.<pointer>}}`/`{{index}}`
 * interpolation, the shared `max`/ceiling rails, and a materialised list
 * that rides the pause record. Plus array run context (`{{key}}` renders
 * JSON) and the template-field fix (stallTimeout/restarts/input now ride
 * every fanned-out task).
 */
final class WorkflowDataFanOutTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;

    /** @var list<string> */
    private array $prompts = [];

    /** @var list<SubAgent> */
    private array $agents = [];

    /** @var array<string, \Closure(SubAgent): ?AgentResult> prompt prefix => answer */
    private array $script = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_datafan_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
        $this->registry = new WorkflowRegistry($this->tempDir . '/workflows');
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->tempDir);
    }

    // ------------------------------------------------------------------ grammar

    public function testTheYamlDataSourcesNormalize(): void
    {
        $workflow = $this->registry->fromYamlString(
            "name: r\nstages:\n"
            . "  - name: find\n    prompt: p\n    outputSchema: {type: object}\n"
            . "  - name: verify\n    parallel: true\n    prompt: 'check {{item.claim}}'\n"
            . "    from: {stage: find, pointer: /findings, key: /id, chunk: '4', max: 9}\n"
            . "  - name: each\n    parallel: true\n    prompt: 'look at {{item}}'\n"
            . "    from: {context: files}\n"
        );

        self::assertSame(['stage' => 'find', 'pointer' => '/findings', 'key' => '/id', 'chunk' => 4, 'max' => 9], $workflow->stages[1]['from']);
        self::assertSame(['context' => 'files', 'chunk' => null], $workflow->stages[2]['from']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedSources(): iterable
    {
        $find = "  - name: find\n    prompt: p\n";
        $fan = static fn (string $from): string => "  - name: verify\n    parallel: true\n    prompt: q\n    from: {$from}\n";

        yield 'two sources' => [$find . $fan('{glob: x, context: files}'), 'from carries "glob" and "context"; a fan-out reads exactly one source'];
        yield 'no source' => [$find . $fan('{chunk: 2}'), 'needs exactly one source'];
        yield 'a later stage' => [$fan('{stage: find, pointer: /a}') . $find, '"stage": "find" is not a stage declared before this one'];
        yield 'itself' => [$fan('{stage: verify, pointer: /a}'), '"stage": "verify" is not a stage declared before this one'];
        yield 'an unknown stage' => [$find . $fan('{stage: nope, pointer: /a}'), '"stage": "nope" is not a stage declared before this one'];
        yield 'a stage without a pointer' => [$find . $fan('{stage: find}'), 'from "stage" needs a "pointer"'];
        yield 'a malformed pointer' => [$find . $fan("{stage: find, pointer: '/a~2'}"), 'from "pointer" is not a valid RFC 6901 pointer'];
        yield 'a malformed key' => [$find . $fan("{stage: find, pointer: /a, key: '~x'}"), 'from "key" is not a valid RFC 6901 pointer'];
        yield 'a pointer beside context' => [$fan('{context: files, pointer: /a}'), 'beside "context" the value itself must be the list'];
        yield 'a reserved context key' => [$fan("{context: '@results'}"), 'from "context" must name a run-context key'];
        yield 'a key beside glob' => [$fan('{glob: x, key: /id}'), 'from "key" belongs to a data source'];
        yield 'a non-string stage' => [$find . $fan('{stage: [a], pointer: /a}'), 'from "stage" must be a string, got array'];
    }

    /**
     * @dataProvider refusedSources
     */
    public function testARefusedSourceDiesAtLoad(string $stages, string $needle): void
    {
        try {
            $this->registry->fromYamlString("name: r\nstages:\n" . $stages);
            self::fail("'{$needle}' still loaded");
        } catch (WorkflowLoadException $e) {
            self::assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function testTheBuilderHoldsADataSourceToTheLoaderShape(): void
    {
        $stage = (new WorkflowBuilder())->name('w')
            ->parallel('wave', [Tasks::agent('a')->prompt('p')], from: ['stage' => 'find', 'pointer' => '/x', 'key' => '/id', 'max' => 3])
            ->build()->stages[0];
        self::assertSame(['stage' => 'find', 'pointer' => '/x', 'key' => '/id', 'chunk' => null, 'max' => 3], $stage['from']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Stage 'wave' from carries \"glob\" and \"stage\"");
        (new WorkflowBuilder())->parallel('wave', [Tasks::agent('a')], from: ['glob' => 'x', 'stage' => 'y', 'pointer' => '/z']);
    }

    // ------------------------------------------------------------------ run time

    public function testAStageArrayFansOutOneAgentPerItemWithItemTokens(): void
    {
        $this->script['find'] = fn (SubAgent $a): AgentResult => $this->ok($a, '{"findings":[{"id":"F-1","claim":"x leaks","line":3},{"id":"F-2","claim":"y races","line":9}]}');
        $this->registry->register((new WorkflowBuilder())->name('hybrid')
            ->stage('find', Tasks::agent('a')->prompt('find')->outputSchema(['type' => 'object']))
            ->parallel('verify', [Tasks::agent('a')->prompt('refute {{item.claim}} at {{item.line}} (#{{index}}) {{item}}')], from: ['stage' => 'find', 'pointer' => '/findings'])
            ->stage('report', Tasks::agent('a')->prompt('report {{verify_1.results}} | {{verify_2.results}}'))
            ->build());

        $result = $this->engine()->run('hybrid');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame([
            'find',
            'refute x leaks at 3 (#0) {"id":"F-1","claim":"x leaks","line":3}',
            'refute y races at 9 (#1) {"id":"F-2","claim":"y races","line":9}',
            'report OUT[refute x leaks at 3 (#0) {"id":"F-1","claim":"x leaks","line":3}] | OUT[refute y races at 9 (#1) {"id":"F-2","claim":"y races","line":9}]',
        ], $this->prompts, 'key-less items are named <stage>_<n>, 1-based');
    }

    public function testAKeyDedupsItemsAndNamesTheirTasks(): void
    {
        $this->script['find'] = fn (SubAgent $a): AgentResult => $this->ok($a, '{"f":[{"id":"a-1"},{"id":"b.2"},{"id":"a-1"},{"id":"a_1"}]}');
        $this->registry->register((new WorkflowBuilder())->name('keyed')
            ->stage('find', Tasks::agent('a')->prompt('find')->outputSchema(['type' => 'object']))
            ->parallel('v', [Tasks::agent('a')->prompt('do {{item.id}}')], from: ['stage' => 'find', 'pointer' => 'f', 'key' => '/id'])
            ->stage('report', Tasks::agent('a')->prompt('{{v_a_1.results}}/{{v_b_2.results}}/{{v_a_1_2.results}}'))
            ->build());

        $result = $this->engine()->run('keyed');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(['find', 'do a-1', 'do b.2', 'do a_1', 'OUT[do a-1]/OUT[do b.2]/OUT[do a_1]'], $this->prompts, 'the duplicate a-1 dispatches once; a_1 sanitises onto a-1 and takes _2');
    }

    public function testAMissingKeyLeafFailsTheStageLoudly(): void
    {
        $this->script['find'] = fn (SubAgent $a): AgentResult => $this->ok($a, '{"f":[{"id":"a"},{"name":"b"}]}');
        $this->registry->register((new WorkflowBuilder())->name('keyless')
            ->stage('find', Tasks::agent('a')->prompt('find')->outputSchema(['type' => 'object']))
            ->parallel('v', [Tasks::agent('a')->prompt('do')], from: ['stage' => 'find', 'pointer' => '/f', 'key' => '/id'])
            ->build());

        $result = $this->engine()->run('keyless');

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame("Stage 'v' fans out from stage 'find' at \"/f\": item #2 has no string, number or bool key at \"/id\"", $result->firstFailure()?->error);
        self::assertSame(['find'], $this->prompts, 'nothing of the fan-out dispatched');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonArrays(): iterable
    {
        yield 'an object' => ['{"f":{"a":1}}', 'which is an object, not an array'];
        yield 'a string' => ['{"f":"a,b"}', 'which is a string, not an array'];
        yield 'a miss' => ['{"g":[]}', 'which is nothing, not an array'];
    }

    /**
     * @dataProvider nonArrays
     */
    public function testANonArrayPointerFailsTheStageLoudly(string $row, string $needle): void
    {
        $this->script['find'] = fn (SubAgent $a): AgentResult => $this->ok($a, $row);
        $this->registry->register((new WorkflowBuilder())->name('bad')
            ->stage('find', Tasks::agent('a')->prompt('find')->outputSchema(['type' => 'object']))
            ->parallel('v', [Tasks::agent('a')->prompt('do')], from: ['stage' => 'find', 'pointer' => '/f'])
            ->build());

        $result = $this->engine()->run('bad');

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertStringContainsString($needle, (string) $result->firstFailure()?->error);
    }

    public function testAStageWithoutATypedRowFailsLoudly(): void
    {
        $this->registry->register((new WorkflowBuilder())->name('untyped')
            ->stage('find', Tasks::agent('a')->prompt('find'))
            ->parallel('v', [Tasks::agent('a')->prompt('do')], from: ['stage' => 'find', 'pointer' => '/f'])
            ->build());

        $result = $this->engine()->run('untyped');

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertStringContainsString("fans out from stage 'find', which left no typed row to point into", (string) $result->firstFailure()?->error);
    }

    public function testAnEmptyListCompletesWithNoAgents(): void
    {
        $this->script['find'] = fn (SubAgent $a): AgentResult => $this->ok($a, '{"f":[]}');
        $this->registry->register((new WorkflowBuilder())->name('empty')
            ->stage('find', Tasks::agent('a')->prompt('find')->outputSchema(['type' => 'object']))
            ->parallel('v', [Tasks::agent('a')->prompt('do')], from: ['stage' => 'find', 'pointer' => '/f'])
            ->stage('after', Tasks::agent('a')->prompt('after [{{v.output}}]'))
            ->build());

        $result = $this->engine()->run('empty');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(['find', 'after []'], $this->prompts);
        self::assertSame([], $result->stageResults[1]->agents);
    }

    public function testAContextListFansOutAndMaxRecordsTheCut(): void
    {
        $this->registry->register((new WorkflowBuilder())->name('ctx')
            ->parallel('each', [Tasks::agent('a')->prompt('read {{item}}')], from: ['context' => 'files', 'max' => 2])
            ->build());

        $result = $this->engine()->run('ctx', ['files' => ['a.php', 'b.php', 'c.php']]);

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(['read a.php', 'read b.php'], $this->prompts);
        self::assertSame(
            "[from: kept 2 of 3 matches (max: 2); 1 dropped, listed at the end of this output]\n"
            . "OUT[read a.php]\nOUT[read b.php]\ndropped: c.php",
            $result->stageResults[0]->output,
        );
    }

    public function testANonListContextValueFailsLoudly(): void
    {
        $this->registry->register((new WorkflowBuilder())->name('csv')
            ->parallel('each', [Tasks::agent('a')->prompt('read {{item}}')], from: ['context' => 'files'])
            ->build());

        $result = $this->engine()->run('csv', ['files' => 'a.php,b.php']);
        self::assertSame("Stage 'each' fans out from context 'files', which is a string, not an array", $result->firstFailure()?->error);

        $missing = $this->engine()->run('csv');
        self::assertStringContainsString('which is nothing (the run was handed no such key)', (string) $missing->firstFailure()?->error);
    }

    public function testTheMaterialisedListRidesThePauseRecordAndResumeReplaysIt(): void
    {
        $engine = $this->engine();
        $calls = 0;
        $this->script['find'] = function (SubAgent $a) use (&$calls): AgentResult {
            $calls++;

            return $this->ok($a, $calls === 1 ? '{"f":["one","two"]}' : '{"f":["changed"]}');
        };
        $this->script['do two'] = static function (SubAgent $a) use ($engine): AgentResult {
            $engine->pause('replay');

            return new AgentResult(agentId: $a->id, status: AgentStatus::Failed, error: new \RuntimeException('two broke'));
        };
        $this->registry->register((new WorkflowBuilder())->name('replay')
            ->stage('find', Tasks::agent('a')->prompt('find')->outputSchema(['type' => 'object']))
            ->parallel('v', [Tasks::agent('a')->prompt('do {{item}}')], from: ['stage' => 'find', 'pointer' => '/f'])
            ->build());

        $run = $engine->run('replay');
        self::assertSame(WorkflowStatus::Failed, $run->status);

        $record = json_decode((string) file_get_contents($this->tempDir . '/workflows/.running/replay.json'), true);
        self::assertSame(['items' => ['one', 'two'], 'names' => [null, null], 'chunk' => null], $record['dynamicWaves']['v']);

        unset($this->script['do two']);
        $this->prompts = [];
        $resumed = $engine->resume($run->workflowId);

        self::assertSame(WorkflowStatus::Completed, $resumed->status, (string) $resumed->firstFailure()?->error);
        self::assertSame(['do one', 'do two'], $this->prompts, 'the recorded list replays; find is not re-read');
        self::assertSame(1, $calls);
    }

    public function testAnUnreplayableRecordRefusesTheResume(): void
    {
        $engine = $this->engine();
        $this->script['find'] = fn (SubAgent $a): AgentResult => $this->ok($a, '{"f":["one"]}');
        $this->script['do one'] = static function (SubAgent $a) use ($engine): AgentResult {
            $engine->pause('mangled');

            return new AgentResult(agentId: $a->id, status: AgentStatus::Failed, error: new \RuntimeException('broke'));
        };
        $this->registry->register((new WorkflowBuilder())->name('mangled')
            ->stage('find', Tasks::agent('a')->prompt('find')->outputSchema(['type' => 'object']))
            ->parallel('v', [Tasks::agent('a')->prompt('do {{item}}')], from: ['stage' => 'find', 'pointer' => '/f'])
            ->build());

        $run = $engine->run('mangled');
        $file = $this->tempDir . '/workflows/.running/mangled.json';
        $record = json_decode((string) file_get_contents($file), true);
        $record['dynamicWaves']['v']['names'] = ['too', 'many'];
        file_put_contents($file, json_encode($record));

        $resumed = $engine->resume($run->workflowId);

        self::assertSame(WorkflowStatus::Failed, $resumed->status);
        self::assertStringContainsString('cannot replay — refusing to re-read stage \'find\'', (string) $resumed->firstFailure()?->error);
    }

    public function testTheTemplateCarriesStallTimeoutRestartsAndInput(): void
    {
        $this->registry->register((new WorkflowBuilder())->name('fields')
            ->stage('seed', Tasks::agent('a')->prompt('seed'))
            ->parallel('each', [Tasks::agent('a')->prompt('use {{input}} on {{item}}')->stallTimeout(30)->restarts(2)->input(['from' => ['seed'], 'as' => 'first'])], from: ['context' => 'files'])
            ->build());

        $result = $this->engine()->run('fields', ['files' => ['x']]);

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        $agent = $this->agents[1];
        self::assertSame(30, $agent->stallTimeout);
        self::assertSame(2, $agent->stallRestarts);
        self::assertSame('use OUT[seed] on x', $agent->task, 'the input join reaches the fanned-out item');
    }

    public function testAGlobItemAlsoSeesItemAndIndex(): void
    {
        $root = $this->tempDir . '/tree';
        mkdir($root);
        file_put_contents($root . '/a.txt', 'a');
        file_put_contents($root . '/b.txt', 'b');
        $this->registry->register((new WorkflowBuilder())->name('globbed')
            ->parallel('each', [Tasks::agent('a')->prompt('{{index}}:{{item}}:{{path}}')], from: ['glob' => '*.txt'])
            ->build());

        $this->engine($root)->run('globbed');

        self::assertSame(['0:a.txt:a.txt', '1:b.txt:b.txt'], $this->prompts);
    }

    // ------------------------------------------------------------------ array context

    public function testAnArrayContextValueInterpolatesAsCompactJson(): void
    {
        $this->registry->register((new WorkflowBuilder())->name('arr')
            ->stage('s', Tasks::agent('a')->prompt('files={{files}} meta={{meta}} flag={{flag}}'))
            ->build());

        $this->engine()->run('arr', ['files' => ['a/b.php', 'é'], 'meta' => ['n' => 1.0], 'flag' => true]);

        self::assertSame(['files=["a/b.php","é"] meta={"n":1.0} flag={{flag}}'], $this->prompts, 'arrays render JSON; a bool is still left verbatim');
    }

    public function testAnArrayContextSurvivesThePauseRecord(): void
    {
        $engine = $this->engine();
        $this->script['first'] = static function (SubAgent $a) use ($engine): AgentResult {
            $engine->pause('arrpause');

            return new AgentResult(agentId: $a->id, status: AgentStatus::Completed, output: 'done');
        };
        $this->registry->register((new WorkflowBuilder())->name('arrpause')
            ->stage('one', Tasks::agent('a')->prompt('first'))
            ->stage('two', Tasks::agent('a')->prompt('second {{files}}'))
            ->build());

        $run = $engine->run('arrpause', ['files' => ['x', 'y']]);
        self::assertSame(WorkflowStatus::Paused, $run->status);

        unset($this->script['first']);
        $this->prompts = [];
        $resumed = $engine->resume($run->workflowId);

        self::assertSame(['second ["x","y"]'], $this->prompts);
        self::assertSame(['x', 'y'], $resumed->context['files']);
    }

    public function testAnAtKeyIsStillRefusedBesideArrays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow context key '@items' is reserved");

        $this->engine()->runWorkflow((new WorkflowBuilder())->name('x')->stage('s', Tasks::agent('a')->prompt('p'))->build(), ['@items' => ['a']]);
    }

    // ------------------------------------------------------------------ harness

    /** @internal the fake executor's body */
    public function dispatch(SubAgent $subAgent): AgentResult
    {
        $this->prompts[] = $subAgent->task;
        $this->agents[] = $subAgent;

        foreach ($this->script as $prefix => $answer) {
            if (str_starts_with($subAgent->task, $prefix)) {
                $scripted = $answer($subAgent);
                if ($scripted !== null) {
                    return $scripted;
                }
            }
        }

        return $this->ok($subAgent, 'OUT[' . $subAgent->task . ']');
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
            public function __construct(private readonly WorkflowDataFanOutTest $test)
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

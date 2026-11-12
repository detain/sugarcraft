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
use SugarCraft\Code\Workflows\StageCondition;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * P1-5 engine half: `loop:` on parallel and verification stages,
 * `{{previous}}`, and loop-until-dry — `loop.accumulate: {pointer, key}`
 * keeps a deduplicated set across rounds (`{{seen}}`, `{{fresh}}`), the
 * engine maintains the dry-round count `until: {dry: N}` reads, and the
 * stage's recorded result is `{"items":[…],"keys":[…],"rounds":n}`.
 */
final class WorkflowLoopAccumulateTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;

    /** @var list<string> */
    private array $prompts = [];

    /** @var array<string, list<string>> prompt prefix => outputs by call */
    private array $outputs = [];

    /** @var array<string, int> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_accumulate_' . uniqid('', true);
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

    // ------------------------------------------------------------------ grammar

    public function testTheYamlLoopCarriesAccumulateAndADryLeaf(): void
    {
        $workflow = $this->registry->fromYamlString(
            "name: r\nstages:\n  - name: find\n    parallel: true\n    agents:\n"
            . "      - {type: f, name: f_a, prompt: 'skip {{seen}}', outputSchema: {type: object}}\n"
            . "    loop:\n      max: 6\n      accumulate: {pointer: /findings, key: /id}\n"
            . "      until: {any: [{dry: 2}, {budgetBelow: 100}]}\n"
        );

        $loop = $workflow->stages[0]['loop'];
        self::assertSame(['pointer' => '/findings', 'key' => '/id'], $loop['accumulate']);
        self::assertSame(6, $loop['max']);
        self::assertSame('any', $loop['until']['combinator']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedLoops(): iterable
    {
        $stage = "  - name: find\n    prompt: p\n    outputSchema: {type: object}\n    loop:\n";

        yield 'dry without accumulate' => [$stage . "      max: 3\n      until: {dry: 2}\n", 'uses {dry: N}, which counts rounds that add nothing new'];
        yield 'accumulate without a pointer' => [$stage . "      max: 3\n      accumulate: {key: /id}\n      until: {dry: 1}\n", 'loop "accumulate" needs a "pointer"'];
        yield 'accumulate with an unknown key' => [$stage . "      max: 3\n      accumulate: {pointer: /a, by: /id}\n      until: {dry: 1}\n", "has unknown key(s) 'by'"];
        yield 'a scalar accumulate' => [$stage . "      max: 3\n      accumulate: /a\n      until: {dry: 1}\n", 'loop "accumulate" must be a map'];
        yield 'a malformed key pointer' => [$stage . "      max: 3\n      accumulate: {pointer: /a, key: '~9'}\n      until: {dry: 1}\n", '"key" is not a valid RFC 6901 pointer'];
        yield 'dry inside when' => ["  - name: find\n    prompt: p\n    when: {dry: 1}\n", 'is loop-only'];
    }

    /**
     * @dataProvider refusedLoops
     */
    public function testARefusedLoopDiesAtLoad(string $stages, string $needle): void
    {
        try {
            $this->registry->fromYamlString("name: r\nstages:\n" . $stages);
            self::fail("'{$needle}' still loaded");
        } catch (WorkflowLoadException $e) {
            self::assertStringContainsString($needle, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ run time

    public function testPreviousIsThePriorRoundsOutputAndAbsentInRoundOne(): void
    {
        $this->outputs['fix'] = ['first try', 'second try', 'OK done'];
        $this->registry->register((new WorkflowBuilder())->name('prev')
            ->stage('fix', Tasks::agent('a')->prompt('fix round {{round}} after [{{previous}}]'))
            ->loop(['max' => 4, 'until' => ['stage' => 'fix', 'contains' => 'OK']])
            ->stage('after', Tasks::agent('a')->prompt('after {{round}} {{previous}}'))
            ->build());

        $result = $this->engine()->run('prev');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame([
            'fix round 1 after [{{previous}}]',
            'fix round 2 after [first try]',
            'fix round 3 after [second try]',
            'after {{round}} {{previous}}',
        ], $this->prompts);
    }

    public function testAnAuthorsContextKeyComesBackAfterTheLoop(): void
    {
        $this->outputs['fix'] = ['OK'];
        $this->registry->register((new WorkflowBuilder())->name('shadow')
            ->stage('fix', Tasks::agent('a')->prompt('fix {{round}} {{seen}}'))
            ->loop(['max' => 2, 'until' => ['stage' => 'fix', 'contains' => 'OK']])
            ->stage('after', Tasks::agent('a')->prompt('after {{round}} {{seen}}'))
            ->build());

        $this->engine()->run('shadow', ['round' => 'mine', 'seen' => 'theirs']);

        self::assertSame(['fix 1 theirs', 'after mine theirs'], $this->prompts, 'round shadows only while the loop runs; seen is not injected without accumulate');
    }

    public function testAParallelStageLoops(): void
    {
        $this->outputs['left'] = ['l1', 'l2'];
        $this->outputs['right'] = ['r1', 'GO'];
        $this->registry->register((new WorkflowBuilder())->name('fanloop')
            ->parallel('fan', [Tasks::agent('a')->prompt('left {{round}}'), Tasks::agent('a')->prompt('right {{round}} {{previous}}')])
            ->loop(['max' => 5, 'until' => ['stage' => 'fan', 'contains' => 'GO']])
            ->build());

        $result = $this->engine()->run('fanloop');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(['left 1', 'right 1 {{previous}}', 'left 2', "right 2 l1\nr1"], $this->prompts);
        self::assertSame("l2\nGO", $result->stageResults[0]->output);
    }

    public function testAVerificationStageLoops(): void
    {
        $this->outputs['do'] = ['draft 1', 'draft 2'];
        $this->outputs['check'] = ['NO', 'YES'];
        $this->registry->register((new WorkflowBuilder())->name('vloop')
            ->withVerification('gate', Tasks::agent('a')->prompt('do {{round}}'), Tasks::agent('r')->prompt('check {{prevResult}}'))
            ->loop(['max' => 3, 'until' => ['stage' => 'gate_verifier', 'equals' => 'YES']])
            ->build());

        $result = $this->engine()->run('vloop');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(['do 1', 'check draft 1', 'do 2', 'check draft 2'], $this->prompts);
    }

    public function testLoopUntilDryAccumulatesDedupsAgainstSeenAndRecordsTheSet(): void
    {
        $schema = ['type' => 'object'];
        $this->outputs['logic'] = [
            '{"findings":[{"id":"A","t":"a"},{"id":"B","t":"b"}]}',
            '{"findings":[{"id":"B","t":"b again"},{"id":"C","t":"c"}]}',
            '{"findings":[{"id":"A","t":"a"}]}',
            '{"findings":[]}',
        ];
        $this->outputs['sec'] = [
            '{"findings":[{"id":"S","t":"s"}]}',
            '{"findings":[]}',
            '{"findings":[{"id":"C","t":"c dup"}]}',
            '{"findings":[]}',
            '{"findings":[{"id":"Z","t":"never reached"}]}',
        ];
        $this->registry->register((new WorkflowBuilder())->name('dry')
            ->parallel('find', [
                Tasks::agent('f')->name('logic')->prompt('logic skip {{seen}} fresh {{fresh}}')->outputSchema($schema),
                Tasks::agent('f')->name('sec')->prompt('sec')->outputSchema($schema),
            ])
            ->loop(['max' => 6, 'accumulate' => ['pointer' => '/findings', 'key' => '/id'], 'until' => ['dry' => 2]])
            ->stage('verify', Tasks::agent('a')->prompt('verify {{find.items/2/t}} of {{find.rounds}}'))
            ->build());

        $result = $this->engine()->run('dry');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        $logicPrompts = array_values(array_filter($this->prompts, static fn (string $p): bool => str_starts_with($p, 'logic')));
        self::assertSame([
            'logic skip [] fresh []',
            'logic skip ["A","B","S"] fresh [{"id":"A","t":"a"},{"id":"B","t":"b"},{"id":"S","t":"s"}]',
            'logic skip ["A","B","S","C"] fresh [{"id":"C","t":"c"}]',
            'logic skip ["A","B","S","C"] fresh []',
        ], $logicPrompts, 'round 3 and round 4 add nothing — two dry rounds stop the loop before round 5');
        self::assertSame(
            '{"items":[{"id":"A","t":"a"},{"id":"B","t":"b"},{"id":"S","t":"s"},{"id":"C","t":"c"}],"keys":["A","B","S","C"],"rounds":4}',
            $result->stageResults[0]->output,
        );
        self::assertSame('verify s of 4', end($this->prompts), 'the accumulated set is the typed row under the stage name');
    }

    public function testTheAccumulatedSetFeedsADataFanOut(): void
    {
        $this->outputs['find'] = ['{"f":["x","y"]}', '{"f":["y"]}'];
        $this->registry->register((new WorkflowBuilder())->name('chain')
            ->stage('find', Tasks::agent('f')->prompt('find')->outputSchema(['type' => 'object']))
            ->loop(['max' => 5, 'accumulate' => ['pointer' => '/f'], 'until' => ['dry' => 1]])
            ->parallel('verify', [Tasks::agent('a')->prompt('verify {{item}}')], from: ['stage' => 'find', 'pointer' => '/items'])
            ->build());

        $result = $this->engine()->run('chain');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(['find', 'find', 'verify x', 'verify y'], $this->prompts, 'key-less identity is the item itself');
    }

    public function testARowWithoutTheArrayFailsTheRoundAndALaterRoundCanRescueIt(): void
    {
        $this->outputs['find'] = ['{"oops":1}', '{"f":["a"]}', '{"f":["a"]}'];
        $this->registry->register((new WorkflowBuilder())->name('rescue')
            ->stage('find', Tasks::agent('f')->prompt('find')->outputSchema(['type' => 'object']))
            ->loop(['max' => 5, 'accumulate' => ['pointer' => '/f'], 'until' => ['dry' => 1]])
            ->build());

        $result = $this->engine()->run('rescue');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame('{"items":["a"],"keys":["a"],"rounds":3}', $result->stageResults[0]->output);
    }

    public function testTheLastRoundFailingToFoldFailsTheStage(): void
    {
        $this->outputs['find'] = ['{"f":"nope"}'];
        $this->registry->register((new WorkflowBuilder())->name('nofold')
            ->stage('find', Tasks::agent('f')->prompt('find')->outputSchema(['type' => 'object']))
            ->loop(['max' => 1, 'accumulate' => ['pointer' => '/f'], 'until' => ['dry' => 1]])
            ->build());

        $result = $this->engine()->run('nofold');

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame("loop.accumulate: agent 'find' of stage 'find' answered a string at \"/f\", not an array", $result->firstFailure()?->error);
    }

    public function testAStageWithNoSchemadAgentCannotAccumulate(): void
    {
        $this->outputs['find'] = ['{"f":[]}'];
        $this->registry->register((new WorkflowBuilder())->name('noschema')
            ->stage('find', Tasks::agent('f')->prompt('find'))
            ->loop(['max' => 1, 'accumulate' => ['pointer' => '/f'], 'until' => ['dry' => 1]])
            ->build());

        $result = $this->engine()->run('noschema');

        self::assertStringContainsString('no schema\'d agent result to read "/f" from', (string) $result->firstFailure()?->error);
    }

    public function testTheDryCounterNeverLeaksIntoTheRunContext(): void
    {
        $this->outputs['find'] = ['{"f":[]}'];
        $this->registry->register((new WorkflowBuilder())->name('clean')
            ->stage('find', Tasks::agent('f')->prompt('find')->outputSchema(['type' => 'object']))
            ->loop(['max' => 3, 'accumulate' => ['pointer' => '/f'], 'until' => ['dry' => 1]])
            ->build());

        $result = $this->engine()->run('clean');

        self::assertArrayNotHasKey(StageCondition::LOOP_DRY_ROUNDS_CONTEXT_KEY, $result->context);
        self::assertArrayNotHasKey('seen', $result->context);
        self::assertArrayNotHasKey('fresh', $result->context);
        self::assertSame('{"items":[],"keys":[],"rounds":1}', $result->context['find.output']);
    }

    // ------------------------------------------------------------------ harness

    /** @internal the fake executor's body */
    public function dispatch(SubAgent $subAgent): AgentResult
    {
        $this->prompts[] = $subAgent->task;

        $output = 'OUT[' . $subAgent->task . ']';
        foreach ($this->outputs as $prefix => $answers) {
            if (str_starts_with($subAgent->task, $prefix)) {
                $n = $this->calls[$prefix] = ($this->calls[$prefix] ?? 0) + 1;
                $output = $answers[min($n, count($answers)) - 1];
                break;
            }
        }

        return new AgentResult(
            agentId: $subAgent->id,
            status: AgentStatus::Completed,
            output: $output,
            tokensUsed: 1,
            costUsd: 0.01,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    private function engine(): WorkflowEngine
    {
        $executor = new class ($this) implements ExecutorInterface {
            public function __construct(private readonly WorkflowLoopAccumulateTest $test)
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

        return new WorkflowEngine($this->registry, new AgentWorkerPool(1, $executor));
    }
}

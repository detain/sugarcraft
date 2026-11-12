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
use SugarCraft\Code\Workflows\WorkflowLinter;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;
use SugarCraft\Code\Workflows\WorkflowTask;

/**
 * P1-3: `minSuccess:` partial-failure tolerance on parallel stages — the
 * stage completes when enough agents succeed, lists every casualty in its
 * output, and exposes them as `{{<stage>.failed}}`.
 */
final class WorkflowMinSuccessTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;

    /** @var list<string> */
    private array $prompts = [];

    /** @var array<string, AgentStatus> prompt prefix => status to settle with */
    private array $statusFor = [];

    /** @var ?\Closure(SubAgent): void */
    private ?\Closure $onDispatch = null;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_minsuccess_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
        $this->registry = new WorkflowRegistry($this->tempDir . '/workflows');
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Grammar
    // -------------------------------------------------------------------------

    public function testTheYamlSpellingsNormalize(): void
    {
        foreach (['2' => ['count' => 2], '"3"' => ['count' => 3], '"90%"' => ['percent' => 90], "' 100 % '" => ['percent' => 100]] as $yaml => $expected) {
            $stage = $this->registry->fromYamlString($this->fanYaml("    minSuccess: {$yaml}\n"))->stages[0];
            self::assertSame($expected, $stage['minSuccess'], "minSuccess: {$yaml}");
        }

        self::assertArrayNotHasKey('minSuccess', $this->registry->fromYamlString($this->fanYaml(''))->stages[0], 'absent stays absent');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedYaml(): iterable
    {
        $fan = "  - name: fan\n    parallel: true\n    agents:\n      - {type: a, prompt: one}\n      - {type: a, prompt: two}\n      - {type: a, prompt: three}\n";

        yield 'a plain stage' => ["  - name: solo\n    prompt: p\n    minSuccess: 1\n", "stage 'solo' carries \"minSuccess\", which only a parallel or map stage can tolerate failures with"];
        yield 'a pipeline' => ["  - name: pipe\n    type: pipeline\n    minSuccess: 1\n    stages:\n      - {prompt: p}\n", "stage 'pipe' carries \"minSuccess\""];
        yield 'a verification' => ["  - name: v\n    type: verification\n    minSuccess: 1\n    task: {prompt: p}\n    verifier: {prompt: q}\n", "stage 'v' carries \"minSuccess\""];
        yield 'a uses stage' => ["  - name: u\n    uses: other\n    minSuccess: 1\n", "stage 'u' carries \"minSuccess\""];
        yield 'zero' => [$fan . "    minSuccess: 0\n", "stage 'fan' minSuccess must be at least 1, got 0"];
        yield 'zero percent' => [$fan . "    minSuccess: '0%'\n", 'minSuccess percentage must be from 1% to 100%, got 0%'];
        yield 'over a hundred percent' => [$fan . "    minSuccess: '101%'\n", 'minSuccess percentage must be from 1% to 100%, got 101%'];
        yield 'a fraction' => [$fan . "    minSuccess: 0.9\n", 'minSuccess must be a whole number of agents or a "NN%" percentage, got float'];
        yield 'a fractional percent' => [$fan . "    minSuccess: '90.5%'\n", 'got "90.5%"'];
        yield 'a word' => [$fan . "    minSuccess: most\n", 'got "most"'];
        yield 'null' => [$fan . "    minSuccess: ~\n", 'got null'];
        yield 'more than the static list' => [$fan . "    minSuccess: 4\n", "minSuccess 4 exceeds the 3 agents stage 'fan' declares; it could never be met"];
    }

    /**
     * @dataProvider refusedYaml
     */
    public function testARefusedSpellingDiesAtLoad(string $stages, string $needle): void
    {
        try {
            $this->registry->fromYamlString("name: r\nstages:\n" . $stages);
            self::fail("'{$needle}' still loaded");
        } catch (WorkflowLoadException $e) {
            self::assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function testTheBuilderAttachesToAParallelStageOnly(): void
    {
        $built = (new WorkflowBuilder())->name('w')
            ->parallel('fan', [Tasks::agent('a'), Tasks::agent('a')])->minSuccess('50%')
            ->build();
        self::assertSame(['percent' => 50], $built->stages[0]['minSuccess']);

        // A fan-out's width is unknown until it expands, so a count is not
        // judged against the one template task.
        $fanOut = (new WorkflowBuilder())->name('w')
            ->parallel('wave', [Tasks::agent('a')], from: ['glob' => 'x'])->minSuccess(150)
            ->build();
        self::assertSame(['count' => 150], $fanOut->stages[0]['minSuccess']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("minSuccess() judges parallel and map stages only; the last stage 'solo' is a 'stage' stage");
        (new WorkflowBuilder())->stage('solo', Tasks::agent('a'))->minSuccess(1);
    }

    public function testTheRequiredCountRoundsAPercentageUp(): void
    {
        self::assertSame(180, WorkflowBuilder::requiredSuccesses(['percent' => 90], 200));
        self::assertSame(2, WorkflowBuilder::requiredSuccesses(['percent' => 50], 3));
        self::assertSame(1, WorkflowBuilder::requiredSuccesses(['percent' => 1], 3));
        self::assertSame(3, WorkflowBuilder::requiredSuccesses(['percent' => 100], 3));
        self::assertSame(2, WorkflowBuilder::requiredSuccesses(['count' => 2], 3));
        self::assertSame('90%', WorkflowBuilder::minSuccessLabel(['percent' => 90]));
        self::assertSame('2', WorkflowBuilder::minSuccessLabel(['count' => 2]));
    }

    // -------------------------------------------------------------------------
    // Engine
    // -------------------------------------------------------------------------

    public function testAMetThresholdCompletesTheStageAndListsEveryCasualty(): void
    {
        $this->statusFor = ['two' => AgentStatus::Failed, 'three' => AgentStatus::TimedOut];
        $this->registerFan(1, 'retry {{fan.failed}}');

        $result = $this->engine()->run('fan');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        $fan = $result->stageResults[0];
        self::assertSame(WorkflowStatus::Completed, $fan->status);
        self::assertNull($fan->error);
        self::assertSame(
            "OUT[one]\nOUT[two]\nOUT[three]\n[2 of 3 agents failed: fan_2 (failed), fan_3 (timed_out)]",
            $fan->output,
        );
        self::assertSame('["fan_2","fan_3"]', $result->context['fan.failed']);
        self::assertSame('retry ["fan_2","fan_3"]', end($this->prompts), 'the follow-up stage reads the casualty list');
    }

    public function testAnUnmetThresholdFailsTheStageNamingTheCount(): void
    {
        $this->statusFor = ['two' => AgentStatus::Failed];
        $this->registerFan('100%', 'never');

        $result = $this->engine()->run('fan');

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame(
            'minSuccess 100% not met: 2 of 3 agents succeeded and 3 were needed; failed: fan_2 (failed)',
            $result->firstFailure()?->error,
        );
        self::assertStringEndsWith("\n[1 of 3 agents failed: fan_2 (failed)]", (string) $result->firstFailure()?->output);
        self::assertNotContains('never', $this->prompts);
    }

    public function testNoCasualtiesLeavesTheOutputByteIdenticalAndRecordsAnEmptyList(): void
    {
        $this->registerFan(2, 'next {{fan.failed}}');
        $tolerant = $this->engine()->run('fan');
        $followUp = end($this->prompts);

        $this->registry->register((new WorkflowBuilder())->name('plain')
            ->parallel('fan', [Tasks::agent('a')->prompt('one'), Tasks::agent('a')->prompt('two'), Tasks::agent('a')->prompt('three')])
            ->build());
        $plain = $this->engine()->run('plain');

        self::assertSame($plain->stageResults[0]->output, $tolerant->stageResults[0]->output);
        self::assertSame('[]', $tolerant->context['fan.failed']);
        self::assertArrayNotHasKey('fan.failed', $plain->context, 'a stage without minSuccess records nothing');
        self::assertSame('next []', $followUp);
    }

    public function testWithoutMinSuccessOneFailureStillFailsTheStage(): void
    {
        $this->statusFor = ['two' => AgentStatus::Failed];
        $this->registry->register((new WorkflowBuilder())->name('plain')
            ->parallel('fan', [Tasks::agent('a')->prompt('one'), Tasks::agent('a')->prompt('two')])
            ->build());

        $result = $this->engine()->run('plain');

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame("OUT[one]\nOUT[two]", $result->firstFailure()?->output, 'no casualty line without the key');
    }

    public function testStopOnFirstFailureDoesNotCancelAMinSuccessStage(): void
    {
        $this->statusFor = ['one' => AgentStatus::Failed];
        $this->registry->register((new WorkflowBuilder())->name('fan')
            ->maxConcurrent(1)
            ->stopOnFirstFailure(true)
            ->parallel('fan', [Tasks::agent('a')->prompt('one'), Tasks::agent('a')->prompt('two'), Tasks::agent('a')->prompt('three')])
            ->minSuccess(2)
            ->build());

        $result = $this->engine()->run('fan');

        self::assertSame(['one', 'two', 'three'], $this->prompts, 'every sibling ran — the tolerance is judged on all of them');
        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
    }

    public function testTheCasualtyListRidesThePauseRecord(): void
    {
        $this->statusFor = ['two' => AgentStatus::Failed];
        $this->registry->register((new WorkflowBuilder())->name('fan')
            ->parallel('fan', [Tasks::agent('a')->prompt('one'), Tasks::agent('a')->prompt('two')])
            ->minSuccess(1)
            ->stage('mid', Tasks::agent('a')->prompt('mid'))
            ->stage('fixup', Tasks::agent('a')->prompt('fix {{fan.failed}}'))
            ->build());
        $engine = $this->engine();
        $this->onDispatch = static function (SubAgent $a) use ($engine): void {
            if ($a->task === 'mid') {
                $engine->pause('fan');
            }
        };

        $run = $engine->run('fan');
        self::assertSame(WorkflowStatus::Paused, $run->status);
        $record = (array) json_decode((string) file_get_contents($this->tempDir . '/workflows/.running/fan.json'), true);
        self::assertSame('["fan_2"]', $record['context']['fan.failed'] ?? null);

        $this->onDispatch = null;
        $this->prompts = [];
        $resumed = $engine->resume($run->workflowId);

        self::assertSame(WorkflowStatus::Completed, $resumed->status, (string) $resumed->firstFailure()?->error);
        self::assertSame(['fix ["fan_2"]'], $this->prompts);
    }

    public function testAFanOutJudgesAPercentageOfWhatItExpanded(): void
    {
        $envRoot = $this->tempDir . '/env';
        mkdir($envRoot, 0755, true);
        foreach (['a', 'b', 'c', 'd'] as $name) {
            touch("{$envRoot}/{$name}.txt");
        }
        $this->statusFor = ['fix c' => AgentStatus::Failed];
        $this->registry->register($this->registry->fromYamlString(
            "name: wave\nstages:\n  - name: sweep\n    parallel: true\n    prompt: fix {{path}}\n    minSuccess: '75%'\n"
            . "    from: {glob: '" . chr(42) . ".txt'}\n"
        ));

        $result = $this->engine($envRoot)->run('wave');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertStringEndsWith('[1 of 4 agents failed: c.txt (failed)]', (string) $result->stageResults[0]->output);
        self::assertSame('["c.txt"]', $result->context['sweep.failed']);
    }

    // -------------------------------------------------------------------------
    // Linter
    // -------------------------------------------------------------------------

    public function testTheLinterMirrorsTheShapeLaw(): void
    {
        $task = static fn (string $prompt): WorkflowTask => new WorkflowTask('a', $prompt, tools: ['Read']);
        $plan = new Workflow('w', 'd', [
            ['name' => 'solo', 'type' => 'stage', 'tasks' => [$task('{{fan.failed}}')], 'minSuccess' => ['count' => 1]],
            ['name' => 'fan', 'type' => 'parallel', 'tasks' => [$task('a'), $task('b')], 'minSuccess' => ['count' => 3]],
            ['name' => 'odd', 'type' => 'parallel', 'tasks' => [$task('a')], 'minSuccess' => ['percent' => 0]],
            ['name' => 'after', 'type' => 'stage', 'tasks' => [$task('{{fan.failed}} {{ghost.failed}}')]],
        ], stopOnFirstFailure: true);

        $findings = WorkflowLinter::lint($plan, [], ['Read'])->renderFindings();

        self::assertStringContainsString("error [solo] minSuccess on a 'stage' stage is never read", $findings);
        self::assertStringContainsString('error [solo] {{fan.failed}} reads a casualty list recorded at stage #2 — not earlier than this stage #1', $findings);
        self::assertStringContainsString('error [fan] minSuccess 3 exceeds the 2 agents this stage declares', $findings);
        self::assertStringContainsString('warning [fan] stopOnFirstFailure does not apply to this minSuccess stage', $findings);
        self::assertStringContainsString('error [odd] minSuccess {"percent":0} is not', $findings);
        self::assertStringContainsString('warning [after] {{ghost.failed}} names no minSuccess parallel stage', $findings);
        self::assertStringNotContainsString('[after] {{fan.failed}}', $findings, 'a later stage reads an earlier list cleanly');
    }

    // -------------------------------------------------------------------------

    /** @internal the fake executor's body; public only so the anonymous class can reach it */
    public function dispatch(SubAgent $subAgent): AgentResult
    {
        $this->prompts[] = $subAgent->task;
        if ($this->onDispatch !== null) {
            ($this->onDispatch)($subAgent);
        }

        $status = AgentStatus::Completed;
        foreach ($this->statusFor as $prefix => $settled) {
            if (str_starts_with($subAgent->task, $prefix)) {
                $status = $settled;
            }
        }

        return new AgentResult(
            agentId: $subAgent->id,
            status: $status,
            output: 'OUT[' . $subAgent->task . ']',
            error: $status === AgentStatus::Completed ? null : new \RuntimeException('agent gave up'),
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    private function registerFan(int|string $minSuccess, string $followUp): void
    {
        $this->registry->register((new WorkflowBuilder())->name('fan')
            ->parallel('fan', [Tasks::agent('a')->prompt('one'), Tasks::agent('a')->prompt('two'), Tasks::agent('a')->prompt('three')])
            ->minSuccess($minSuccess)
            ->stage('next', Tasks::agent('a')->prompt($followUp))
            ->build());
    }

    private function fanYaml(string $extra): string
    {
        return "name: r\nstages:\n  - name: fan\n    parallel: true\n    agents:\n      - {type: a, prompt: one}\n      - {type: a, prompt: two}\n      - {type: a, prompt: three}\n" . $extra;
    }

    private function engine(?string $envRoot = null): WorkflowEngine
    {
        $executor = new class ($this) implements ExecutorInterface {
            public function __construct(private readonly WorkflowMinSuccessTest $test)
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

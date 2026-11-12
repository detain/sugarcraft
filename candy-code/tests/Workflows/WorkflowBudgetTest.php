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

/**
 * wf-impl P1-7: `config.maxTokens` / `config.maxCost` are HARD ceilings
 * checked before every dispatch; a refused dispatch fails its stage with
 * "budget exhausted (spent X of Y tokens|USD)" and PAUSES the run, so a
 * resume under a raised ceiling continues. `{{budget.remaining}}`,
 * `until: {budgetBelow: N}` and the external per-run token budget.
 */
final class WorkflowBudgetTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;

    /** @var list<string> every prompt dispatched, in order */
    public array $prompts = [];

    /** tokens / USD each dispatch reports */
    public int $tokensPerAgent = 400;
    public float $costPerAgent = 0.0;

    /** prompts (prefix) whose dispatch fails */
    public array $failing = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_budget_' . uniqid('', true);
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
                return new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Failed,
                    error: new \RuntimeException('it broke'),
                    tokensUsed: $this->tokensPerAgent,
                    costUsd: $this->costPerAgent,
                    completedAt: new \DateTimeImmutable(),
                );
            }
        }

        return new AgentResult(
            agentId: $agent->id,
            status: AgentStatus::Completed,
            output: 'out:' . $agent->task,
            tokensUsed: $this->tokensPerAgent,
            costUsd: $this->costPerAgent,
            completedAt: new \DateTimeImmutable(),
        );
    }

    // ------------------------------------------------------------------ loader / builder

    public function testTheLoaderReadsBothCeilings(): void
    {
        $wf = $this->registry->fromYamlString("name: b\nconfig: {maxTokens: 400000, maxCost: 5}\nstages:\n  - {name: a, prompt: go}\n");
        self::assertSame(400000, $wf->maxTokens);
        self::assertSame(5.0, $wf->maxCost);

        $plain = $this->registry->fromYamlString("name: b\nstages:\n  - {name: a, prompt: go}\n");
        self::assertNull($plain->maxTokens);
        self::assertNull($plain->maxCost);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function badConfigs(): iterable
    {
        yield 'zero tokens' => ['maxTokens: 0', '"config.maxTokens" must be at least 1, got 0'];
        yield 'float tokens' => ['maxTokens: 1.5', '"config.maxTokens" must be a whole number'];
        yield 'zero cost' => ['maxCost: 0', '"config.maxCost" must be a positive number of USD, got 0'];
        yield 'negative cost' => ['maxCost: -2.5', '"config.maxCost" must be a positive number of USD, got -2.5'];
        yield 'word cost' => ['maxCost: lots', '"config.maxCost" must be a positive number of USD, got string'];
    }

    /** @dataProvider badConfigs */
    public function testBadCeilingsAreRefused(string $line, string $message): void
    {
        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage($message);

        $this->registry->fromYamlString("name: b\nconfig: {{$line}}\nstages:\n  - {name: a, prompt: go}\n");
    }

    public function testTheBuilderRefusesNonPositiveCeilings(): void
    {
        $wf = (new WorkflowBuilder())->name('x')->description('d')->maxTokens(10)->maxCost(0.25)->build();
        self::assertSame(10, $wf->maxTokens);
        self::assertSame(0.25, $wf->maxCost);

        foreach ([static fn () => (new WorkflowBuilder())->maxTokens(0), static fn () => (new WorkflowBuilder())->maxCost(0)] as $bad) {
            try {
                $bad();
                self::fail('a non-positive ceiling was accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ------------------------------------------------------------------ engine

    public function testADispatchPastTheTokenCeilingPausesTheRunAndAResumeUnderARaisedCeilingContinues(): void
    {
        $this->registry->register($this->three(maxTokens: 700));

        $paused = $this->engine()->run('three');

        self::assertSame(WorkflowStatus::Paused, $paused->status);
        self::assertSame(['do a', 'do b'], $this->prompts, 'c never started');
        $c = $paused->stageResults[2];
        self::assertSame(WorkflowStatus::Failed, $c->status);
        self::assertStringStartsWith('budget exhausted (spent 800 of 700 tokens)', (string) $c->error);
        self::assertContains("Stage 'c': budget exhausted (spent 800 of 700 tokens); the run is paused before this dispatch", $paused->announcements);
        self::assertFileExists($this->tempDir . '/workflows/.running/three.json');

        // Raise the ceiling (the resume re-reads the definition) and continue.
        $this->registry->register($this->three(maxTokens: 5000));
        $resumed = $this->engine()->resume('three');

        self::assertSame(WorkflowStatus::Completed, $resumed->status, (string) $resumed->firstFailure()?->error);
        self::assertSame(['do a', 'do b', 'do c using out:do b'], $this->prompts);
        self::assertSame(1200, $resumed->totalTokens);
    }

    public function testAResumeUnderTheSameCeilingPausesAgainWithNothingDispatched(): void
    {
        $this->registry->register($this->three(maxTokens: 700));
        $this->engine()->run('three');

        $again = $this->engine()->resume('three');

        self::assertSame(WorkflowStatus::Paused, $again->status);
        self::assertSame(['do a', 'do b'], $this->prompts);
    }

    public function testTheCostCeilingRefusesInUsd(): void
    {
        $this->tokensPerAgent = 1;
        $this->costPerAgent = 0.3;
        $workflow = $this->three(maxCost: 0.5);

        $result = $this->engine()->runWorkflow($workflow);

        self::assertSame(WorkflowStatus::Paused, $result->status);
        self::assertStringStartsWith('budget exhausted (spent 0.60 of 0.50 USD)', (string) $result->stageResults[2]->error);
    }

    public function testTheExternalBudgetAndTheConfigCeilingTakeTheTighter(): void
    {
        $result = $this->engine()->runWorkflow($this->three(), budgetTokens: 500);
        self::assertSame(WorkflowStatus::Paused, $result->status, 'the external budget alone is a ceiling');
        self::assertStringStartsWith('budget exhausted (spent 800 of 500 tokens)', (string) $result->stageResults[2]->error);
        self::assertFileDoesNotExist($this->tempDir . '/workflows/.running/three.json', 'a caller-built plan writes no pause record');

        $this->prompts = [];
        $tight = $this->engine()->runWorkflow($this->three(maxTokens: 300), budgetTokens: 5000);
        self::assertSame(['do a'], $this->prompts, 'config 300 beats a 5000 budget');
        self::assertStringStartsWith('budget exhausted (spent 400 of 300 tokens)', (string) $tight->stageResults[1]->error);

        $this->prompts = [];
        self::assertTrue($this->engine()->runWorkflow($this->three())->isSuccess(), 'no ceiling, no stop');

        $this->expectException(\InvalidArgumentException::class);
        $this->engine()->runWorkflow($this->three(), budgetTokens: 0);
    }

    public function testARetryPastTheCeilingIsNotStartedAndTheRunPauses(): void
    {
        $this->failing = ['flaky'];
        $workflow = (new WorkflowBuilder())->name('r')->description('d')->maxTokens(1000)
            ->stage('a', Tasks::agent('x')->prompt('flaky one')->retries(5))
            ->build();

        $result = $this->engine()->runWorkflow($workflow);

        self::assertSame(WorkflowStatus::Paused, $result->status);
        self::assertCount(3, $this->prompts, '400 + 400 + 400: the fourth attempt would start at 1200 of 1000');
        self::assertStringContainsString('not retried after attempt 3 of 6: budget exhausted (spent 1200 of 1000 tokens)', (string) $result->stageResults[0]->error);
    }

    public function testAParallelWaveIsRefusedWholeWhenTheBudgetIsSpent(): void
    {
        $workflow = (new WorkflowBuilder())->name('p')->description('d')->maxTokens(400)
            ->stage('a', Tasks::agent('x')->prompt('first'))
            ->parallel('b', [Tasks::agent('x')->prompt('one'), Tasks::agent('x')->prompt('two')])
            ->build();

        $result = $this->engine()->runWorkflow($workflow);

        self::assertSame(WorkflowStatus::Paused, $result->status);
        self::assertSame(['first'], $this->prompts);
    }

    public function testBudgetRemainingInterpolatesAndBudgetBelowStopsALoop(): void
    {
        $workflow = (new WorkflowBuilder())->name('l')->description('d')->maxTokens(2000)
            ->stage('dig', Tasks::agent('x')->prompt('round {{round}}, {{budget.remaining}} left'))
            ->loop(['max' => 10, 'until' => ['budgetBelow' => 500]])
            ->build();

        $result = $this->engine()->runWorkflow($workflow);

        self::assertTrue($result->isSuccess(), (string) $result->firstFailure()?->error);
        self::assertSame(
            ['round 1, 2000 left', 'round 2, 1600 left', 'round 3, 1200 left', 'round 4, 800 left'],
            $this->prompts,
            'the loop stops once fewer than 500 tokens remain (400 after round 4)',
        );
        self::assertArrayNotHasKey('@budgetRemaining', $result->context, 'the remaining figure never lands in the run context');
    }

    public function testWithoutACeilingBudgetTokensStayVerbatimAndBudgetBelowNeverHolds(): void
    {
        $workflow = (new WorkflowBuilder())->name('l')->description('d')
            ->stage('dig', Tasks::agent('x')->prompt('{{budget.remaining}} left'))
            ->loop(['max' => 3, 'until' => ['budgetBelow' => 500]])
            ->build();

        $result = $this->engine()->runWorkflow($workflow);

        self::assertTrue($result->isSuccess());
        self::assertSame(array_fill(0, 3, '{{budget.remaining}} left'), $this->prompts);

        $lint = WorkflowLinter::lint($workflow);
        self::assertStringContainsString('until {budgetBelow: N} reads the tokens left', $lint->renderFindings());
        self::assertStringContainsString('{{budget.remaining}} resolves only when the run has a token ceiling', $lint->renderFindings());
        $capped = WorkflowLinter::lint(new Workflow('w', 'd', $workflow->stages, maxTokens: 10))->renderFindings();
        self::assertStringNotContainsString('budgetBelow', $capped, 'a ceiling silences both budget warnings');
        self::assertStringNotContainsString('budget.remaining', $capped);
    }

    public function testTheLinterRefusesAHandBuiltNonPositiveCeiling(): void
    {
        $lint = WorkflowLinter::lint(new Workflow('w', 'd', [], maxTokens: 0, maxCost: -1.0));

        self::assertStringContainsString('maxTokens 0 is not positive', $lint->renderFindings());
        self::assertStringContainsString('maxCost -1.0 is not a positive number of USD', $lint->renderFindings());
    }

    // ------------------------------------------------------------------ helpers

    private function three(?int $maxTokens = null, ?float $maxCost = null): Workflow
    {
        $b = (new WorkflowBuilder())
            ->name('three')
            ->description('a then b then c')
            ->stage('a', Tasks::agent('coder')->prompt('do a'))
            ->stage('b', Tasks::agent('coder')->prompt('do b'))
            ->stage('c', Tasks::agent('coder')->prompt('do c using {{b.output}}'))
            ->maxTokens($maxTokens);

        return $b->maxCost($maxCost)->build();
    }

    private function engine(): WorkflowEngine
    {
        $test = $this;
        $executor = new class ($test) implements ExecutorInterface {
            public function __construct(private readonly WorkflowBudgetTest $test)
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

        return new WorkflowEngine($this->registry, new AgentWorkerPool(1, $executor));
    }
}

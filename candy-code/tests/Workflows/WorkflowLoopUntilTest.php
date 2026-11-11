<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\ExecutorInterface;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * C4: loop-until stage semantics — the engine re-dispatches a plain stage
 * until its `until:` clause holds, at most `max:` rounds, and records ONLY
 * the final round (the cache door and the pause file never see intermediate
 * rounds; a resumed run re-runs a looped stage from round 1).
 *
 * The clause is C2's grammar verbatim, and the round number is injected as
 * {{round}} into the stage's interpolation only while a round is running.
 * Exhausting `max:` with the clause still false COMPLETES the stage on its
 * last round's result — "try up to N times and move on", never a new failure
 * kind. These are the pins that keep each of those sentences load-bearing.
 */
final class WorkflowLoopUntilTest extends TestCase
{
    private WorkflowRegistry $registry;
    private AgentWorkerPool $pool;
    private WorkflowEngine $engine;
    private ExecutorInterface $mockExecutor;

    protected function setUp(): void
    {
        $this->registry = new WorkflowRegistry();

        // AgentWorkerPool is final, so we cannot mock it directly.
        // Instead we inject a mock ExecutorInterface which executeOne delegates to.
        $this->mockExecutor = $this->getMockBuilder(ExecutorInterface::class)
            ->onlyMethods(['execute', 'executeStream', 'cancel', 'cancelAll'])
            ->getMock();

        $this->pool = new AgentWorkerPool(5, $this->mockExecutor);
        $this->engine = new WorkflowEngine($this->registry, $this->pool);
    }

    private function successfulAgentResult(string $output, int $tokens = 100, float $cost = 0.01): AgentResult
    {
        return new AgentResult(
            agentId: 'agent-' . uniqid((string) getmypid(), true),
            status: AgentStatus::Completed,
            output: $output,
            tokensUsed: $tokens,
            costUsd: $cost,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    private function failedAgentResult(string $error): AgentResult
    {
        return new AgentResult(
            agentId: 'agent-' . uniqid((string) getmypid(), true),
            status: AgentStatus::Failed,
            output: 'failed',
            error: new \RuntimeException($error),
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    public function testTheLoopRedispatchesUntilTheClauseHolds(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('loop-holds')
            ->description('fix keeps going until it says DONE')
            ->stage('fix', Tasks::agent('coder')->prompt('make it green'))
            ->loop(['max' => 3, 'until' => ['stage' => 'fix', 'contains' => 'DONE']])
            ->build();

        $this->registry->register($workflow);

        $prompts = [];
        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (SubAgent $agent, CompleteRequest $request) use (&$prompts): AgentResult {
                $prompts[] = $request->messages[0]['content'];

                return $prompts === ['make it green']
                    ? $this->successfulAgentResult('nope, still red')
                    : $this->successfulAgentResult('DONE green');
            });

        $result = $this->engine->run('loop-holds', []);

        $this->assertSame(WorkflowStatus::Completed, $result->status);
        $this->assertCount(1, $result->stageResults, 'only the final round is recorded — a loop is one stage row');
        $this->assertSame(WorkflowStatus::Completed, $result->stageResults[0]->status);
        $this->assertSame('DONE green', $result->stageResults[0]->output);
        $this->assertSame('DONE green', $result->context['fix.output'], 'the clause reads the stage\'s own last round, and so do downstream stages');
        $this->assertSame(['fix' => 'DONE green'], $result->context[WorkflowEngine::RESULTS_CONTEXT_KEY]);
    }

    public function testRoundNumbersThePromptFromOneAndStopsAtMax(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('loop-counted')
            ->description('three tries, never green')
            ->stage('fix', Tasks::agent('coder')->prompt('attempt {{round}} of the suite'))
            ->loop(['max' => 3, 'until' => ['stage' => 'fix', 'contains' => 'DONE']])
            ->build();

        $this->registry->register($workflow);

        $prompts = [];
        $this->mockExecutor
            ->expects($this->exactly(3))
            ->method('execute')
            ->willReturnCallback(function (SubAgent $agent, CompleteRequest $request) use (&$prompts): AgentResult {
                $prompts[] = $request->messages[0]['content'];

                return $this->successfulAgentResult('nope');
            });

        $result = $this->engine->run('loop-counted', []);

        $this->assertSame(
            ['attempt 1 of the suite', 'attempt 2 of the suite', 'attempt 3 of the suite'],
            $prompts,
            '{{round}} rides every round 1-based',
        );
        $this->assertSame(WorkflowStatus::Completed, $result->status, 'max exhausted with the clause false still completes: last round is the recorded state');
        $this->assertCount(1, $result->stageResults);
        $this->assertSame('nope', $result->stageResults[0]->output);
    }

    public function testEveryRoundBillsAndOnlyTheLastRoundRows(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('loop-bills')
            ->description('two rounds of real spend')
            ->stage('fix', Tasks::agent('coder')->prompt('try {{round}}'))
            ->loop(['max' => 2, 'until' => ['stage' => 'fix', 'contains' => 'DONE']])
            ->build();

        $this->registry->register($workflow);

        $count = 0;
        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function () use (&$count): AgentResult {
                $count++;

                return $count === 1
                    ? $this->successfulAgentResult('red', 100, 0.01)
                    : $this->successfulAgentResult('DONE', 50, 0.005);
            });

        $result = $this->engine->run('loop-bills', []);

        $this->assertSame(150, $result->totalTokens, 'a round is a full dispatch — its tokens are billed');
        $this->assertEqualsWithDelta(0.015, $result->totalCost, 1e-9);
        $this->assertCount(1, $result->stageResults, 'the billed rounds still settle exactly one row');
    }

    public function testAFailedRoundIsRescuedByTheNextRound(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('loop-rescue')
            ->description('round one dies, round two lands')
            ->stage('fix', Tasks::agent('coder')->prompt('retry me'))
            ->loop(['max' => 3, 'until' => ['stage' => 'fix', 'contains' => 'DONE']])
            ->build();

        $this->registry->register($workflow);

        $count = 0;
        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function () use (&$count): AgentResult {
                $count++;

                return $count === 1
                    ? $this->failedAgentResult('provider exploded')
                    : $this->successfulAgentResult('DONE');
            });

        $result = $this->engine->run('loop-rescue', []);

        $this->assertSame(
            WorkflowStatus::Completed,
            $result->status,
            'a failing round does not fail-fast out of the stage — the until clause still gets its rounds',
        );
        $this->assertCount(1, $result->stageResults);
        $this->assertSame(WorkflowStatus::Completed, $result->stageResults[0]->status);
        $this->assertSame('DONE', $result->stageResults[0]->output);
    }

    public function testTheFinalRoundFailingSettlesTheStageAsItsNormalFailure(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('loop-dies')
            ->description('every round dies')
            ->stage('fix', Tasks::agent('coder')->prompt('doomed'))
            ->loop(['max' => 2, 'until' => ['stage' => 'fix', 'contains' => 'DONE']])
            ->stage('after', Tasks::agent('coder')->prompt('never runs'))
            ->build();

        $this->registry->register($workflow);

        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(fn (): AgentResult => $this->failedAgentResult('still broken'));

        $result = $this->engine->run('loop-dies', []);

        $this->assertSame(WorkflowStatus::Failed, $result->status, 'the last round\'s failure IS the stage\'s failure — fail-fast then takes over');
        $this->assertCount(1, $result->stageResults, 'the failed stage is recorded once, and the later stage never starts');
        $this->assertSame(WorkflowStatus::Failed, $result->stageResults[0]->status);
    }

    public function testACancelMidLoopEndsTheLoopAndCancelsTheRow(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('loop-cancel')
            ->description('cancel lands between rounds')
            ->stage('fix', Tasks::agent('coder')->prompt('looping'))
            ->loop(['max' => 5, 'until' => ['stage' => 'fix', 'contains' => 'DONE']])
            ->build();

        $this->registry->register($workflow);

        $token = new CancellationToken();
        $this->mockExecutor
            ->expects($this->exactly(1))
            ->method('execute')
            ->willReturnCallback(function () use ($token): AgentResult {
                $token->cancel();

                return $this->successfulAgentResult('not DONE yet');
            });

        $result = $this->engine->run('loop-cancel', [], $token);

        $this->assertSame(WorkflowStatus::Cancelled, $result->status);
        $this->assertCount(1, $result->stageResults);
        $this->assertSame(WorkflowStatus::Cancelled, $result->stageResults[0]->status, 'a cancel that lands mid-loop stops the rounds and the row settles cancelled');
        $this->assertSame(WorkflowEngine::CANCELLED_STAGE_ERROR, $result->stageResults[0]->error);
    }

    public function testTheRoundSlotIsFreedForStagesAfterTheLoop(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('loop-freed')
            ->description('round belongs to its stage only')
            ->stage('fix', Tasks::agent('coder')->prompt('try {{round}}'))
            ->loop(['max' => 2, 'until' => ['stage' => 'fix', 'contains' => 'DONE']])
            ->stage('after', Tasks::agent('coder')->prompt('sees {{round}} raw'))
            ->build();

        $this->registry->register($workflow);

        $prompts = [];
        $this->mockExecutor
            ->expects($this->exactly(3))
            ->method('execute')
            ->willReturnCallback(function (SubAgent $agent, CompleteRequest $request) use (&$prompts): AgentResult {
                $prompts[] = $request->messages[0]['content'];

                return $this->successfulAgentResult('not yet');
            });

        $this->engine->run('loop-freed', []);

        $this->assertSame('try 1', $prompts[0]);
        $this->assertSame('try 2', $prompts[1]);
        $this->assertStringContainsString(
            '{{round}}',
            $prompts[2],
            'after the looped stage the round slot is unset again — a later stage sees the raw token, never a stale count',
        );
    }

    public function testAWhenGuardSkipsTheWholeLoopWithoutDispatching(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('loop-guarded')
            ->description('the guard outranks the loop')
            ->stage('a', Tasks::agent('coder')->prompt('plan'))
            ->stage('b', Tasks::agent('coder')->prompt('would loop'))
            ->when(['stage' => 'a', 'contains' => 'GO'])
            ->loop(['max' => 4, 'until' => ['stage' => 'b', 'contains' => 'DONE']])
            ->build();

        $this->registry->register($workflow);

        $this->mockExecutor
            ->expects($this->exactly(1))
            ->method('execute')
            ->willReturnCallback(fn (): AgentResult => $this->successfulAgentResult('halt, nothing to see'));

        $result = $this->engine->run('loop-guarded', []);

        $this->assertSame(WorkflowStatus::Completed, $result->status);
        $this->assertSame(WorkflowStatus::Skipped, $result->stageResults[1]->status, 'an unsatisfied guard skips the stage — its rounds are never started');
    }

    public function testBuilderLoopRefusesAStageThatIsNotThereYet(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('loop() needs a stage to attach to');

        (new WorkflowBuilder())->name('nowhere')->loop(['max' => 2, 'until' => ['stage' => 'x', 'equals' => 'y']]);
    }

    public function testBuilderLoopIsStageOnlyInV1(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("loop() is stage-only in v1; the last stage 'fan' is a 'parallel' stage");

        (new WorkflowBuilder())
            ->name('wrong-shape')
            ->parallel('fan', [Tasks::agent('coder')->prompt('left'), Tasks::agent('coder')->prompt('right')])
            ->loop(['max' => 2, 'until' => ['stage' => 'fan', 'equals' => 'y']]);
    }

    public function testBuilderLoopNeedsBothHalvesOfThePair(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('loop() needs both max and until');

        (new WorkflowBuilder())
            ->name('half-loop')
            ->stage('fix', Tasks::agent('coder')->prompt('q'))
            ->loop(['max' => 2]);
    }

    public function testBuilderLoopMaxMustBeAWholeNumberOfAtLeastOne(): void
    {
        foreach ([0, -3, 'lots'] as $bad) {
            try {
                (new WorkflowBuilder())
                    ->name('bad-max')
                    ->stage('fix', Tasks::agent('coder')->prompt('q'))
                    ->loop(['max' => $bad, 'until' => ['stage' => 'fix', 'equals' => 'y']]);
                $this->fail('loop() accepted max=' . var_export($bad, true));
            } catch (\InvalidArgumentException $reason) {
                $this->assertStringContainsString('whole number of at least 1', $reason->getMessage());
            }
        }
    }
}

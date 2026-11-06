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
use SugarCraft\Code\Workflows\WorkflowTask;

/**
 * Verdict-aware verification (wf-g1): a verification stage's verifier may
 * carry a `reject` PCRE; when the verifier COMPLETES with output matching the
 * pattern, the STAGE fails on the verdict — not on the transport status — so
 * the engine's existing retry and pause/resume machinery treats a refused
 * review exactly like a crashed agent.
 *
 * POLARITY (the trap this option's naming exists to dodge): a MATCH rejects.
 * `reject('/REJECT/')` means "this text means the verifier refused", so the
 * absent option keeps the legacy behavior byte-for-byte and no hopeful
 * reading of "expect" can invert it.
 *
 * Every test drives the real engine with a fake {@see ExecutorInterface}
 * (the WorkflowPauseResumeTest shape): prompts are recorded, behaviour is
 * scripted per prompt prefix, and the pool's own retry folding stays live so
 * the attempt arithmetic tested here is the production arithmetic.
 */
final class WorkflowVerdictRejectionTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;
    private WorkflowEngine $engine;

    /** @var list<string> every prompt the executor was handed, in order */
    private array $prompts = [];

    /** @var array<string, \Closure(SubAgent): ?AgentResult> prompt prefix => behaviour override */
    private array $script = [];

    /** @var array<string, int> dispatches seen per prompt prefix, incremented AFTER the override runs */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_verdict_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
        $this->registry = new WorkflowRegistry($this->tempDir . '/workflows');

        $test = $this;
        $executor = new class ($test) implements ExecutorInterface {
            public int $calls = 0;

            public function __construct(private readonly WorkflowVerdictRejectionTest $test)
            {
            }

            public function execute(SubAgent $subAgent, CompleteRequest $request): AgentResult
            {
                $this->calls++;

                return $this->test->dispatchScripted($subAgent);
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

        $this->engine = new WorkflowEngine($this->registry, new AgentWorkerPool(1, $executor));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /** @internal the fake executor's body; public only so the anonymous class can reach it */
    public function dispatchScripted(SubAgent $subAgent): AgentResult
    {
        $this->prompts[] = $subAgent->task;

        foreach ($this->script as $prefix => $behaviour) {
            if (str_starts_with($subAgent->task, $prefix)) {
                $result = $behaviour($subAgent);
                $this->seen[$prefix] = ($this->seen[$prefix] ?? 0) + 1;
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return self::completed($subAgent, 'OUT[' . $subAgent->task . ']');
    }

    /** How many times a scripted prefix has ALREADY been dispatched (0 on its first call). */
    public function seenCount(string $prefix): int
    {
        return $this->seen[$prefix] ?? 0;
    }

    private static function completed(SubAgent $subAgent, string $output): AgentResult
    {
        return new AgentResult(
            agentId: $subAgent->id,
            status: AgentStatus::Completed,
            output: $output,
            tokensUsed: 10,
            costUsd: 0.5,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Script a completed verifier reply per call number: $outcome receives
     * 0 on the prefix's first dispatch, 1 on its second, ...
     */
    private function scriptVerdict(string $prefix, \Closure $outcome): void
    {
        $test = $this;
        $this->script[$prefix] = static function (SubAgent $subAgent) use ($test, $prefix, $outcome): ?AgentResult {
            $output = $outcome($test->seenCount($prefix));
            if ($output === null) {
                return null;
            }

            return self::completed($subAgent, $output);
        };
    }

    /** A one-stage build+verify workflow, verifier rejecting on '/REJECT/'. */
    private function registerRejectingVerification(?int $verifierRetries = null): void
    {
        $verifier = Tasks::agent('reviewer')->prompt('review {{prevResult}}');
        if ($verifierRetries !== null) {
            $verifier = $verifier->retries($verifierRetries);
        }

        $this->registry->register(
            (new WorkflowBuilder())
                ->name('verdict')
                ->description('build then verdict')
                ->withVerification('check', Tasks::agent('coder')->prompt('do the work'), $verifier->reject('/REJECT/'))
                ->build(),
        );
    }

    // ----------------------------------------------------------------- (a)

    public function testAVerifierOutputMatchingTheRejectPatternFailsTheStageCitingPatternAndLine(): void
    {
        $this->registerRejectingVerification();
        $this->scriptVerdict('review', static fn (int $n): string => "notes line\nREJECT: missing error handling\nfinal line");

        $run = $this->engine->run('verdict');

        $this->assertSame(WorkflowStatus::Failed, $run->status);
        $stage = $run->stageResults[0];
        $this->assertSame(WorkflowStatus::Failed, $stage->status);
        $this->assertStringContainsString('verdict rejected', (string) $stage->error);
        $this->assertStringContainsString('/REJECT/', (string) $stage->error, 'the reason must name the pattern');
        $this->assertStringContainsString('REJECT: missing error handling', (string) $stage->error, 'the reason must quote the matched line');
        $this->assertStringNotContainsString('notes line', (string) $stage->error, 'only the matched line, never the whole output');
        $this->assertStringNotContainsString('final line', (string) $stage->error);
    }

    // ----------------------------------------------------------------- (b)

    public function testACleanCompletedVerifierStillCompletesTheStage(): void
    {
        $this->registerRejectingVerification();

        // No script override: the default 'OUT[review ...]' reply carries no marker.
        $run = $this->engine->run('verdict');

        $this->assertSame(WorkflowStatus::Completed, $run->status);
        $this->assertSame(WorkflowStatus::Completed, $run->stageResults[0]->status);
        $this->assertNull($run->stageResults[0]->error);
    }

    // ----------------------------------------------------------------- (c)

    public function testAnAbsentRejectOptionKeepsTheLegacyOneDispatchShapeByteIdentical(): void
    {
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('legacy')
                ->description('build then verify, no verdict rule')
                ->withVerification(
                    'check',
                    Tasks::agent('coder')->prompt('do the work'),
                    Tasks::agent('reviewer')->prompt('review {{prevResult}}'),
                )
                ->build(),
        );

        $run = $this->engine->run('legacy');

        $this->assertSame(WorkflowStatus::Completed, $run->status);
        $this->assertSame(
            ['do the work', 'review OUT[do the work]'],
            $this->prompts,
            'one task dispatch and one verifier dispatch, nothing else',
        );
        $this->assertSame(
            "OUT[do the work]\nOUT[review OUT[do the work]]",
            $run->stageResults[0]->output,
            'the combined stage output is the legacy task-newline-verifier text',
        );
        $this->assertCount(2, $run->stageResults[0]->agents);
    }

    // ----------------------------------------------------------------- (d)

    public function testEveryAttemptCountsWhenTheVerdictRejectsOnAllOfThem(): void
    {
        $this->registerRejectingVerification(verifierRetries: 2);
        $this->scriptVerdict('review', static fn (int $n): string => 'REJECT again attempt ' . $n);

        $run = $this->engine->run('verdict');

        $this->assertSame(WorkflowStatus::Failed, $run->status);
        $this->assertCount(4, $this->prompts, 'the task ran once, the verifier re-ran up to 1+retries');
        $this->assertSame(40, $run->totalTokens, 'every verifier attempt spent real tokens');
        $this->assertStringContainsString('[failed on all 3 attempts]', (string) $run->stageResults[0]->error);
        $this->assertCount(4, $run->stageResults[0]->agents, 'task + all three verifier attempts stay on the stage');
    }

    public function testAFirstAttemptRejectionThenCleanSecondAttemptCompletesTheStage(): void
    {
        $this->registerRejectingVerification(verifierRetries: 1);
        $this->scriptVerdict('review', static fn (int $n): string => $n === 0 ? 'REJECT: too noisy' : 'APPROVE 0 0 0 0');

        $run = $this->engine->run('verdict');

        $this->assertSame(WorkflowStatus::Completed, $run->status);
        $this->assertCount(3, $this->prompts, 'task + rejecting attempt + clean retry');
        $this->assertSame(
            "OUT[do the work]\nAPPROVE 0 0 0 0",
            $run->stageResults[0]->output,
            'the LAST verifier result is what the stage reports',
        );
        $this->assertStringNotContainsString('too noisy', (string) $run->stageResults[0]->output);
    }

    public function testMechanicalPoolAttemptsAndVerdictAttemptsShareOneRetryBudget(): void
    {
        $this->registerRejectingVerification(verifierRetries: 2);
        $test = $this;
        $this->script['review'] = static function (SubAgent $subAgent) use ($test): AgentResult {
            if ($test->seenCount('review') === 0) {
                // First pool attempt crashes; the pool re-runs it, and that
                // retry COMPLETES carrying a rejecting verdict.
                return new AgentResult(
                    agentId: $subAgent->id,
                    status: AgentStatus::Failed,
                    error: new \RuntimeException('flaked'),
                    tokensUsed: 10,
                    costUsd: 0.5,
                    startedAt: new \DateTimeImmutable(),
                    completedAt: new \DateTimeImmutable(),
                );
            }

            return self::completed($subAgent, 'REJECT: refused after crash');
        };

        $run = $this->engine->run('verdict');

        // The folded crash+retry dispatch consumes 2 of 1+retries=3 attempts;
        // exactly one more verifier dispatch fits, and it rejects too.
        $this->assertSame(WorkflowStatus::Failed, $run->status);
        $this->assertCount(4, $this->prompts, 'task once + three verifier attempts total, mechanical and verdict together');
        $this->assertStringContainsString('[failed on all 3 attempts]', (string) $run->stageResults[0]->error);
        $this->assertSame(40, $run->totalTokens);
    }

    public function testAVerifierThatFailsMechanicallyStillFailsOnTheTransportErrorNotAVerdict(): void
    {
        $this->registerRejectingVerification();
        $this->script['review'] = static fn (SubAgent $a): AgentResult => new AgentResult(
            agentId: $a->id,
            status: AgentStatus::Failed,
            error: new \RuntimeException('verifier crashed'),
            tokensUsed: 3,
            costUsd: 0.1,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );

        $run = $this->engine->run('verdict');

        $this->assertSame(WorkflowStatus::Failed, $run->status);
        $this->assertSame('verifier crashed', (string) $run->stageResults[0]->error);
        $this->assertStringNotContainsString('verdict', (string) $run->stageResults[0]->error, 'no scan runs on a non-completed result');
        $this->assertCount(2, $this->prompts);
    }

    // ----------------------------------------------------------------- (e)

    public function testAPausedVerdictFailureResumesByRerunningOnlyTheVerificationStage(): void
    {
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('verdict')
                ->description('a then checked b')
                ->stage('a', Tasks::agent('coder')->prompt('do a'))
                ->withVerification(
                    'check',
                    Tasks::agent('coder')->prompt('do b using {{a.output}}'),
                    Tasks::agent('reviewer')->prompt('review {{prevResult}}')->reject('/REJECT/'),
                )
                ->build(),
        );
        $this->scriptVerdict('review', static fn (int $n): string => 'REJECT: first pass was sloppy');

        $run = $this->engine->run('verdict');
        $this->assertSame(WorkflowStatus::Failed, $run->status);

        $this->engine->pause($run->workflowId);
        $pauseFile = $this->tempDir . '/workflows/.running/verdict.json';
        $this->assertFileExists($pauseFile, 'a verdict failure is a pause-able failure like any other');
        $data = json_decode((string) file_get_contents($pauseFile), true);
        $this->assertSame(1, $data['stagesCompleted'], 'the verdict-failed stage must not count as completed');

        unset($this->script['review']);
        $this->prompts = [];

        $resumed = $this->engine->resume($run->workflowId);

        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertSame(
            ['do b using OUT[do a]', 'review OUT[do b using OUT[do a]]'],
            $this->prompts,
            'resume re-runs the verdict stage (task and verifier) and not stage a',
        );
        $this->assertFileDoesNotExist($pauseFile, 'resume consumes the pause record');
    }

    // ----------------------------------------------------------------- (f)

    public function testTheBuilderSetterAndTheRawTaskBothCarryThePattern(): void
    {
        $this->assertSame('/REJECT/', Tasks::agent('reviewer')->reject('/REJECT/')->build()->reject);
        $this->assertNull(Tasks::agent('reviewer')->build()->reject, 'absent means legacy, spelled as null');
        $this->assertSame('/X/', (new WorkflowTask('reviewer', 'p', reject: '/X/'))->reject);
    }

    public function testYamlCannotSpellAVerificationStageAndSilentlyIgnoresStrayRejectKeys(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        $yaml = <<<'YAML'
name: yaml-verdict
description: a YAML stage cannot become a verification stage

stages:
  - name: one
    agent: coder
    prompt: "plain step"
    reject: "REJECT"
YAML;
        file_put_contents($workflowsDir . '/yaml-verdict.yaml', $yaml);

        $workflow = (new WorkflowRegistry($workflowsDir))->loadYaml('yaml-verdict');

        $task = $workflow->stages[0]['tasks'][0];
        $this->assertInstanceOf(WorkflowTask::class, $task);
        $this->assertNull($task->reject, 'the YAML surface has no reject spelling — the key is ignored, not honoured');
        $this->assertNotSame('verification', $workflow->stages[0]['type'] ?? null, 'YAML stages parse as plain stage/parallel only');
    }

    // ----------------------------------------------------------------- (g)

    public function testAnInvalidPatternIsRefusedAtTheBuilderBoundary(): void
    {
        try {
            Tasks::agent('reviewer')->reject('/unclosed');
            $this->fail('a pattern that cannot compile must be refused at set time');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('/unclosed', $e->getMessage());
            $this->assertStringContainsString('not a valid PCRE', $e->getMessage());
        }
    }

    public function testAHandBuiltInvalidPatternFailsTheStageClosedInsteadOfReadingAsClean(): void
    {
        $workflow = new Workflow(
            name: 'raw-invalid',
            description: 'bypasses the builder guard',
            stages: [[
                'name' => 'check',
                'type' => 'verification',
                'task' => new WorkflowTask('coder', 'do the work'),
                'verifier' => new WorkflowTask('reviewer', 'review {{prevResult}}', reject: '/(['),
            ]],
        );

        $run = $this->engine->runFromPhp(static fn (): Workflow => $workflow);

        $this->assertSame(WorkflowStatus::Failed, $run->status);
        $this->assertStringContainsString('verdict scan failed closed', (string) $run->stageResults[0]->error);
        $this->assertStringContainsString('never ran', (string) $run->stageResults[0]->error);
        $this->assertCount(2, $this->prompts, 'an invalid pattern must not burn a retry loop');
    }

    // ------------------------------------------------------- misuse refusal

    public function testARejectAnchoredToAPlainStageTaskIsRefusedBeforeAnythingRuns(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('misplaced')
            ->description('reject on a plain stage')
            ->stage('work', Tasks::agent('coder')->prompt('do the work')->reject('/REJECT/'))
            ->build();

        $run = $this->engine->runFromPhp(static fn (): Workflow => $workflow);

        $this->assertSame(WorkflowStatus::Failed, $run->status);
        $this->assertStringContainsString('belongs on the verifier slot', (string) $run->stageResults[0]->error);
        $this->assertStringContainsString("Stage 'work'", (string) $run->stageResults[0]->error);
        $this->assertSame([], $this->prompts, 'a placement bug must never cost an agent dispatch');
    }

    public function testARejectAnchoredToAVerificationsTaskSlotIsRefusedWithoutADispatch(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('wrong-half')
            ->description('reject on the producing half')
            ->withVerification(
                'check',
                Tasks::agent('coder')->prompt('do the work')->reject('/REJECT/'),
                Tasks::agent('reviewer')->prompt('review {{prevResult}}'),
            )
            ->build();

        $run = $this->engine->runFromPhp(static fn (): Workflow => $workflow);

        $this->assertSame(WorkflowStatus::Failed, $run->status);
        $this->assertStringContainsString('task slot', (string) $run->stageResults[0]->error);
        $this->assertStringContainsString('belongs on the verifier slot', (string) $run->stageResults[0]->error);
        $this->assertSame([], $this->prompts);
    }

    public function testARejectHiddenInsideAPipelineStepIsAlsoRefused(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('sneaky-pipeline')
            ->description('reject in a pipeline step')
            ->pipeline('flow', [
                Tasks::agent('coder')->prompt('step one'),
                Tasks::agent('coder')->prompt('step two')->reject('/REJECT/'),
            ])
            ->build();

        $run = $this->engine->runFromPhp(static fn (): Workflow => $workflow);

        $this->assertSame(WorkflowStatus::Failed, $run->status);
        $this->assertStringContainsString("Stage 'flow'", (string) $run->stageResults[0]->error);
        $this->assertStringContainsString('pipeline', (string) $run->stageResults[0]->error);
        $this->assertSame([], $this->prompts);
    }

    // ------------------------------------------------- matched-line safety

    public function testTheReasonCarriesOnlyTheMatchedLineClippedAndControlCharFree(): void
    {
        $this->registerRejectingVerification();
        $long = str_repeat('x', 300);
        $this->scriptVerdict('review', static fn (int $n): string => "first\nREJECT " . $long . "\x07\x1b[31mred\x1b[0m\nlast");

        $run = $this->engine->run('verdict');

        $error = (string) $run->stageResults[0]->error;
        $this->assertStringContainsString('verdict rejected', $error);
        $this->assertStringNotContainsString('first', $error);
        $this->assertStringNotContainsString('last', $error);
        $this->assertStringNotContainsString("\x07", $error, 'control bytes never ride a one-line error');
        $this->assertStringNotContainsString("\x1b", $error);
        $this->assertStringNotContainsString($long, $error, 'a 300-char run of filler cannot survive a 160-char clip');
        $this->assertStringContainsString('...', $error, 'the over-long line is clipped with an ellipsis');
        $this->assertStringContainsString('REJECT ' . str_repeat('x', 150) . '...', $error);
    }

    // ------------------------------------------------- occurrence threshold

    public function testASingleMatchStaysBelowAnAtLeastTwoThreshold(): void
    {
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('threshold')
                ->description('build then verdict')
                ->withVerification(
                    'check',
                    Tasks::agent('coder')->prompt('do the work'),
                    Tasks::agent('reviewer')->prompt('review {{prevResult}}')->reject('/REJECT/', atLeast: 2),
                )
                ->build(),
        );
        $this->scriptVerdict('review', static fn (int $n): string => "notes\nREJECT: one stray quoted word\nend");

        $run = $this->engine->run('threshold');

        $this->assertSame(WorkflowStatus::Completed, $run->status, 'one match must not sink an atLeast:2 verdict');
        $this->assertCount(2, $this->prompts, 'a below-threshold verdict never re-dispatches');
    }

    public function testTheThresholdTripsExactlyAtTheCountedMatchesAndCitesTheTally(): void
    {
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('threshold')
                ->description('build then verdict')
                ->withVerification(
                    'check',
                    Tasks::agent('coder')->prompt('do the work'),
                    Tasks::agent('reviewer')->prompt('review {{prevResult}}')->reject('/REJECT/', atLeast: 2),
                )
                ->build(),
        );
        $this->scriptVerdict('review', static fn (int $n): string => "notes\nREJECT: first finding\nREJECT: second finding\nend");

        $run = $this->engine->run('threshold');

        $error = (string) $run->stageResults[0]->error;
        $this->assertSame(WorkflowStatus::Failed, $run->status, 'the non-vacuity pair of the test above');
        $this->assertStringContainsString('2 of at least 2 matches', $error, 'the reason counts the matches');
        $this->assertStringContainsString('/REJECT/', $error);
        $this->assertStringContainsString('REJECT: first finding', $error, 'the reason quotes the FIRST matched line');
        $this->assertStringNotContainsString('second finding', $error);
    }

    public function testTheDefaultThresholdReasonIsByteIdenticalToMatchOnce(): void
    {
        // atLeast: 1 is the default; the reason gains no tally clause, so the
        // pinned reason shape from the match-once tests stands verbatim.
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('threshold')
                ->description('build then verdict')
                ->withVerification(
                    'check',
                    Tasks::agent('coder')->prompt('do the work'),
                    Tasks::agent('reviewer')->prompt('review {{prevResult}}')->reject('/REJECT/', atLeast: 1),
                )
                ->build(),
        );
        $this->scriptVerdict('review', static fn (int $n): string => "notes\nREJECT: missing error handling\nend");

        $run = $this->engine->run('threshold');

        $error = (string) $run->stageResults[0]->error;
        $this->assertSame(WorkflowStatus::Failed, $run->status);
        $this->assertStringNotContainsString('of at least', $error, 'threshold 1 must not decorate the legacy reason');
        $this->assertStringContainsString('matched /REJECT/ on: "REJECT: missing error handling"', $error);
    }

    public function testAZeroAndANegativeThresholdAreRefusedAtTheBuilderBoundary(): void
    {
        foreach ([0, -5] as $n) {
            try {
                Tasks::agent('reviewer')->prompt('p')->reject('/REJECT/', atLeast: $n);
                $this->fail('atLeast ' . $n . ' must throw');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('atLeast', $e->getMessage());
                $this->assertStringContainsString((string) $n, $e->getMessage());
            }
        }

        $this->assertSame(3, Tasks::agent('r')->prompt('p')->reject('/x/i', atLeast: 3)->build()->rejectMinOccurrences);
    }

    public function testAHandBuiltNonPositiveThresholdFailsTheStageClosedAtTheScan(): void
    {
        $workflow = new Workflow(
            name: 'raw-threshold',
            description: 'bypasses the builder guard',
            stages: [[
                'name' => 'check',
                'type' => 'verification',
                'task' => new WorkflowTask('coder', 'do the work'),
                'verifier' => new WorkflowTask('reviewer', 'review {{prevResult}}', reject: '/REJECT/', rejectMinOccurrences: 0),
            ]],
        );
        $this->scriptVerdict('review', static fn (int $n): string => 'REJECT: whatever');

        $run = $this->engine->runFromPhp(static fn (): Workflow => $workflow);

        $this->assertSame(WorkflowStatus::Failed, $run->status);
        $error = (string) $run->stageResults[0]->error;
        $this->assertStringContainsString('verdict scan failed closed', $error);
        $this->assertStringContainsString('occurrence threshold 0', $error);
        $this->assertStringContainsString('can never read clean', $error);
        $this->assertCount(2, $this->prompts, 'an invalid threshold must not burn a retry loop');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Isolation;
use SugarCraft\Code\Workflows\Workflow;
use SugarCraft\Code\Workflows\WorkflowLinter;
use SugarCraft\Code\Workflows\WorkflowTask;

/**
 * C5: the static workflow linter.
 *
 * The linter is a read-only consumer of the compiled DTOs: these tests build
 * Workflow/WorkflowTask values directly (public final ctors) and plant each
 * shape the lint legs exist to catch. The clean-plan pin is the vacuity
 * counterweight — every leg must go red when neutered while this stays green.
 */
final class WorkflowLinterTest extends TestCase
{
    private const UNIVERSE = ['Read', 'Edit'];

    public function testCleanPlanLintsWithoutFindings(): void
    {
        $plan = new Workflow('clean', 'clean plan', [
            self::linterStage('gather', [new WorkflowTask('scout', 'gather notes')]),
            self::linterStage('fix', [new WorkflowTask('coder', 'apply {{gather.results}} to {{gather.output}}', ['Read'])]),
        ]);

        $report = WorkflowLinter::lint($plan, [], self::UNIVERSE);

        self::assertFalse($report->hasFindings(), $report->renderFindings());
        self::assertSame(0, $report->exitStatus());
        self::assertSame('clean — no findings', $report->verdictLine());
        self::assertStringContainsString('gather | stage', $report->renderPlanTable());
        self::assertStringContainsString('3600', $report->renderPlanTable());
    }

    public function testSeedsSatisfyBareReferencesAndCostTheWarningLeg(): void
    {
        $plan = new Workflow('seeded', 'seeded plan', [
            self::linterStage('act', [new WorkflowTask('coder', 'topic {{subject}}')]),
        ]);

        $seeded = WorkflowLinter::lint($plan, ['subject' => 'lint'], ['Read']);
        self::assertFalse($seeded->hasFindings(), $seeded->renderFindings());

        $unseeded = WorkflowLinter::lint($plan, [], ['Read']);
        self::assertTrue($unseeded->hasFindings());
        self::assertFalse($unseeded->hasErrors(), 'a bare miss stays a warning: the run context may supply it later');
        self::assertStringContainsString('matches no run-context seed and no producer name', $unseeded->renderFindings());
    }

    public function testUnknownResultReferenceIsAnError(): void
    {
        $plan = new Workflow('dangling', 'dangling plan', [
            self::linterStage('act', [new WorkflowTask('coder', 'see {{ghost.results}}')]),
        ]);

        $report = WorkflowLinter::lint($plan, [], self::UNIVERSE);

        self::assertTrue($report->hasErrors());
        self::assertStringContainsString('names no producer in this plan', $report->renderFindings());
    }

    public function testForwardReferenceIsAnError(): void
    {
        $plan = new Workflow('forward', 'forward plan', [
            self::linterStage('first', [new WorkflowTask('coder', 'reads {{second.results}} and {{second.output}}')]),
            self::linterStage('second', [new WorkflowTask('coder', 'writes')]),
        ]);

        $report = WorkflowLinter::lint($plan, [], self::UNIVERSE);

        self::assertSame(2, $report->errorCount(), 'one error per forward leg');
        self::assertStringContainsString('later than this task', $report->renderFindings());
    }

    public function testParallelStagesNeverProduceOutputReferences(): void
    {
        $plan = new Workflow('parout', 'parallel output plan', [
            ['name' => 'fan', 'type' => 'parallel', 'tasks' => [
                new WorkflowTask('coder', 'one', isolation: Isolation::Worktree, name: 'one'),
                new WorkflowTask('coder', 'two', isolation: Isolation::Worktree, name: 'two'),
            ]],
            self::linterStage('after', [new WorkflowTask('coder', 'uses {{fan.output}}')]),
        ]);

        $report = WorkflowLinter::lint($plan, [], self::UNIVERSE);

        self::assertTrue($report->hasErrors());
        self::assertStringContainsString('parallel and verification stages never write', $report->renderFindings());
    }

    public function testDuplicateProducerKeysCollide(): void
    {
        $twin = static fn(): WorkflowTask => new WorkflowTask('coder', 'work', name: 'twin');
        $plan = new Workflow('twins', 'colliding names', [
            self::linterStage('first', [$twin()]),
            self::linterStage('second', [$twin()]),
        ]);

        $report = WorkflowLinter::lint($plan, [], self::UNIVERSE);

        self::assertTrue($report->hasErrors());
        self::assertStringContainsString('Duplicate result key "twin"', $report->renderFindings());
    }

    public function testRejectOutsideTheVerifierSlotAndBadPatterns(): void
    {
        $stray = new WorkflowTask('coder', 'work', reject: 'looks off');
        $plan = new Workflow('rejects', 'reject misuse', [
            self::linterStage('act', [$stray]),
            ['name' => 'checked', 'type' => 'verification',
                'task' => new WorkflowTask('coder', 'work'),
                'verifier' => new WorkflowTask('reviewer', 'check', reject: '[unclosed')],
        ]);

        $report = WorkflowLinter::lint($plan, [], self::UNIVERSE);
        $findings = $report->renderFindings();

        self::assertTrue($report->hasErrors());
        self::assertStringContainsString('belongs on the verifier slot', $findings);
        self::assertStringContainsString('does not compile as PCRE', $findings);
    }

    public function testBoundsOnRetriesAndTimeouts(): void
    {
        $plan = new Workflow('bounds', 'bound violations', [
            self::linterStage('act', [
                new WorkflowTask('coder', 'work', retries: -1),
                new WorkflowTask('coder', 'work', retries: 20),
                new WorkflowTask('coder', 'work', timeout: 0),
            ]),
        ], timeout: -5, maxConcurrent: -1);

        $report = WorkflowLinter::lint($plan, [], self::UNIVERSE);
        $findings = $report->renderFindings();

        self::assertTrue($report->hasErrors());
        self::assertStringContainsString('Retries -1 is negative', $findings);
        self::assertStringContainsString('Retries 20 is unusually high', $findings);
        self::assertStringContainsString('Task timeout 0 is not a positive', $findings);
        self::assertStringContainsString('Workflow timeout -5 is not positive', $findings);
        self::assertStringContainsString('maxConcurrent -1 is negative', $findings);
    }

    public function testAnExplicitNoCapWidthWarnsAndAnUnsetOneIsSilent(): void
    {
        $stages = [self::linterStage('act', [new WorkflowTask('coder', 'work', tools: ['Read'])])];

        $nocap = WorkflowLinter::lint(new Workflow('nocap', 'd', $stages, maxConcurrent: 0), [], self::UNIVERSE);
        self::assertFalse($nocap->hasErrors(), $nocap->renderFindings());
        self::assertStringContainsString('warning [workflow] maxConcurrent 0 means no cap', $nocap->renderFindings());

        $unset = WorkflowLinter::lint(new Workflow('unset', 'd', $stages), [], self::UNIVERSE);
        self::assertStringNotContainsString('maxConcurrent', $unset->renderFindings());
    }

    public function testAHandBuiltMaxAgentsOrFromMaxOutOfRangeIsAnError(): void
    {
        $plan = new Workflow('ceil', 'd', [
            ['name' => 'wave', 'type' => 'parallel', 'tasks' => [new WorkflowTask('coder', 'fix {{path}}', tools: ['Read'])], 'from' => ['glob' => 'src/*', 'chunk' => null, 'max' => 5000]],
            ['name' => 'solo', 'type' => 'stage', 'tasks' => [new WorkflowTask('coder', 'x', tools: ['Read'])], 'from' => ['glob' => 'src/*', 'chunk' => null]],
        ], maxAgents: 0);

        $findings = WorkflowLinter::lint($plan, [], self::UNIVERSE)->renderFindings();

        self::assertStringContainsString('maxAgents 0 is not positive', $findings);
        self::assertStringContainsString('from.max 5000 is not a whole number from 1 to 4096', $findings);
        self::assertStringContainsString("\"from\" on a 'stage' stage fans nothing out", $findings);
    }

    public function testUnsupportedStageTypeAndEmptyToolDeclaration(): void
    {
        $plan = new Workflow('broken', 'broken shapes', [
            ['name' => 'weird', 'type' => 'funnel', 'tasks' => []],
            self::linterStage('act', [new WorkflowTask('coder', 'work', [''])]),
        ]);

        $report = WorkflowLinter::lint($plan, [], self::UNIVERSE);
        $findings = $report->renderFindings();

        self::assertTrue($report->hasErrors());
        self::assertStringContainsString("Stage type 'funnel' not supported", $findings);
        self::assertStringContainsString('A tool declaration is empty', $findings);
    }

    public function testToolNamesCheckAgainstTheSessionUniverse(): void
    {
        $withUnknown = new Workflow('known', 'unknown tool', [
            self::linterStage('act', [new WorkflowTask('coder', 'work', ['Grep'])]),
        ]);

        $strict = WorkflowLinter::lint($withUnknown, [], self::UNIVERSE);
        self::assertTrue($strict->hasErrors());
        self::assertStringContainsString('declares tool "Grep"', $strict->renderFindings());

        // A degraded lint (no engine session => no universe) skips the leg instead of lying.
        $degraded = WorkflowLinter::lint($withUnknown, [], null);
        self::assertFalse($degraded->hasFindings(), $degraded->renderFindings());

        // Malformed spellings are refused by the declaration grammar itself.
        $prefixed = new Workflow('prefixed', 'prefixed pattern', [
            self::linterStage('act', [new WorkflowTask('coder', 'work', ['Bash(git status'])]),
        ]);
        $prefixedReport = WorkflowLinter::lint($prefixed, [], self::UNIVERSE);
        self::assertTrue($prefixedReport->hasErrors());
        self::assertStringContainsString('declares the tool pattern "Bash(git status"', $prefixedReport->renderFindings());
    }

    public function testParallelWriteContentionWarnsUnlessWorktreesIsolate(): void
    {
        $tasks = static fn(?Isolation $isolation): array => [
            new WorkflowTask('coder', 'one', isolation: $isolation),
            new WorkflowTask('coder', 'two', isolation: $isolation),
        ];

        $unguarded = new Workflow('race', 'shared writes', [
            ['name' => 'fan', 'type' => 'parallel', 'tasks' => $tasks(null)],
        ]);
        $warned = WorkflowLinter::lint($unguarded, [], self::UNIVERSE);
        self::assertTrue($warned->hasFindings());
        self::assertFalse($warned->hasErrors(), 'contention is a warning, not a refusal');
        self::assertStringContainsString('writes the shared checkout', $warned->renderFindings());

        $isolated = new Workflow('safe', 'worktree fan', [
            ['name' => 'fan', 'type' => 'parallel', 'tasks' => $tasks(Isolation::Worktree)],
        ]);
        self::assertFalse(WorkflowLinter::lint($isolated, [], self::UNIVERSE)->hasFindings());
    }

    // ---- C11: the include-compose (uses: chain) leg -----------------------

    public function testSelfIncludeCyclesAndNamesTheChain(): void
    {
        $plan = new Workflow('a', 'self-including', [
            self::linterStage('body', [], ['uses' => 'a']),
        ]);

        $report = WorkflowLinter::lint($plan, [], self::UNIVERSE, self::includeResolver(['a' => $plan]));

        self::assertTrue($report->hasErrors());
        self::assertStringContainsString('uses-chain cycles: a -> a', $report->renderFindings());
    }

    public function testTwoHopCycleCarriesTheFullChain(): void
    {
        $inner = new Workflow('b', 'includes back', [
            self::linterStage('bounce', [], ['uses' => 'a']),
        ]);
        $outer = new Workflow('a', 'includes out', [
            self::linterStage('call', [], ['uses' => 'b']),
        ]);

        $report = WorkflowLinter::lint(
            $outer,
            [],
            self::UNIVERSE,
            self::includeResolver(['a' => $outer, 'b' => $inner]),
        );

        self::assertSame(1, $report->errorCount());
        self::assertStringContainsString('uses-chain cycles: a -> b -> a', $report->renderFindings());
    }

    public function testDepthThreeChainLintsClean(): void
    {
        $c = new Workflow('c', 'leaf', [self::linterStage('work', [new WorkflowTask('coder', 'write')])]);
        $b = new Workflow('b', 'mid', [self::linterStage('down', [], ['uses' => 'c'])]);
        $a = new Workflow('a', 'top', [self::linterStage('deep', [], ['uses' => 'b'])]);

        $report = WorkflowLinter::lint(
            $a,
            [],
            self::UNIVERSE,
            self::includeResolver(['a' => $a, 'b' => $b, 'c' => $c]),
        );

        self::assertFalse($report->hasFindings(), $report->renderFindings());
    }

    public function testFourthNodeReportsTooDeepAndSpellsTheChain(): void
    {
        $d = new Workflow('d', 'fourth', [self::linterStage('work', [new WorkflowTask('coder', 'write')])]);
        $c = new Workflow('c', 'third', [self::linterStage('deeper', [], ['uses' => 'd'])]);
        $b = new Workflow('b', 'second', [self::linterStage('down', [], ['uses' => 'c'])]);
        $a = new Workflow('a', 'top', [self::linterStage('deep', [], ['uses' => 'b'])]);

        $report = WorkflowLinter::lint(
            $a,
            [],
            self::UNIVERSE,
            self::includeResolver(['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d]),
        );

        self::assertSame(1, $report->errorCount(), 'the walk refuses at the offending edge once');
        self::assertStringContainsString('uses-chain too deep: a -> b -> c -> d', $report->renderFindings());
    }

    public function testUnresolvableIncludeEdgeStaysSilentForLoadTime(): void
    {
        $plan = new Workflow('a', 'ghost edge', [
            self::linterStage('call', [], ['uses' => 'ghost']),
        ]);

        $report = WorkflowLinter::lint($plan, [], self::UNIVERSE, self::includeResolver([]));

        self::assertFalse($report->hasFindings(), 'an unknown name is load()\'s verdict, not the linter\'s');
    }

    public function testWithoutAResolverTheIncludeLegIsSkippedEntirely(): void
    {
        // Legacy shape: a pre-C11 three-arg caller must get its pre-C11
        // verdict even on a cyclic plan — the leg does not exist without a
        // registry view. This is the byte-compatibility pin for the other
        // (unwired) lint entry points.
        $plan = new Workflow('a', 'self-including', [
            self::linterStage('body', [], ['uses' => 'a']),
        ]);

        self::assertFalse(WorkflowLinter::lint($plan, [], self::UNIVERSE)->hasFindings());
    }

    /**
     * @param array<string, Workflow> $byName
     */
    private static function includeResolver(array $byName): callable
    {
        return static fn (string $name): ?Workflow => $byName[$name] ?? null;
    }

    /**
     * @param list<WorkflowTask> $tasks
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function linterStage(string $name, array $tasks, array $extra = []): array
    {
        return ['name' => $name, 'type' => 'stage', 'tasks' => $tasks] + $extra;
    }
}

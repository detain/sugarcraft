<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\Workflow;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use ReflectionClass;

/**
 * Pins rv-php-8: `with*` is reserved tree-wide for copy-on-write returns
 * (AGENTS.md "Immutable + fluent"). WorkflowBuilder's command methods
 * (stage/parallel/pipeline/...) are sanctioned mutable builder DSL — the DSL
 * files under workflows/ chain them and sometimes hold the receiver — but
 * withVerification() carries the wither name, so it MUST NOT mutate the
 * receiver.
 */
final class WorkflowBuilderWithVerificationTest extends TestCase
{
    public function testWithVerificationReturnsANewInstanceAndLeavesTheReceiverUntouched(): void
    {
        $base = (new WorkflowBuilder())->name('verify-immutable');

        $derived = $base->withVerification(
            'check',
            Tasks::agent('coder')->prompt('make'),
            Tasks::agent('tester')->prompt('verify'),
        );

        self::assertNotSame($base, $derived, 'a wither that returns $this is the aliasing trap rv-php-8 names');

        // The receiver must still build WITHOUT the verification stage.
        $baseStageCount = self::stageCount($base->build());
        self::assertSame(0, $baseStageCount, 'discarding the wither result must be a no-op on the receiver');

        // The derived builder carries exactly the one appended stage, typed right.
        $stages = self::stagesOf($derived->build());
        self::assertCount(1, $stages);
        self::assertSame('verification', $stages[0]['type']);
        self::assertSame('check', $stages[0]['name']);
    }

    public function testComposingTwoWithersNeverDoubleAddsInEitherBuilder(): void
    {
        $base = (new WorkflowBuilder())->name('double-wither');

        $first = $base->withVerification('one', Tasks::agent('coder')->prompt('a'), Tasks::agent('tester')->prompt('b'));
        $second = $first->withVerification('two', Tasks::agent('coder')->prompt('c'), Tasks::agent('tester')->prompt('d'));

        self::assertSame(0, self::stageCount($base->build()), 'receiver of the first wither stays empty');
        self::assertSame(1, self::stageCount($first->build()), 'the first result holds exactly its own stage');
        self::assertSame(2, self::stageCount($second->build()), 'the second result appends to a private copy');

        $names = array_map(static fn(array $s): string => $s['name'], self::stagesOf($second->build()));
        self::assertSame(['one', 'two'], $names);
    }

    public function testTheWitherCarriesStagesAccumulatedByCommandMethodsBeforeIt(): void
    {
        $withStage = (new WorkflowBuilder())->name('carry-forward')->stage('pre', Tasks::agent('coder')->prompt('p'));

        $derived = $withStage->withVerification('check', Tasks::agent('coder')->prompt('q'), Tasks::agent('tester')->prompt('r'));

        $stages = self::stagesOf($derived->build());
        self::assertCount(2, $stages, 'the clone must keep the command-accumulated history');
        self::assertSame('stage', $stages[0]['type']);
        self::assertSame('verification', $stages[1]['type']);

        self::assertSame(1, self::stageCount($withStage->build()), 'and still not leak into the receiver');
    }

    public function testTheWitherBodyNeverWritesToTheReceiver(): void
    {
        // Structural tripwire, scoped to the withVerification body: the
        // command methods (stage/parallel/pipeline) are sanctioned mutable
        // builder DSL and may append to $this — the with* is not. A re-spread
        // of the old in-place body reddens this even on call paths the
        // functional pins happen not to walk.
        $src = (string) file_get_contents((new ReflectionClass(WorkflowBuilder::class))->getFileName());
        self::assertNotFalse($src);
        $start = strpos($src, 'public function withVerification(');
        self::assertIsInt($start, 'the wither vanished — call sites in workflows/ would fatal');
        $end = strpos($src, 'public function ', $start + 1);
        self::assertIsInt($end, 'no method follows the wither — widen this slice deliberately');
        $body = substr($src, $start, $end - $start);

        self::assertStringNotContainsString('$this->stages', $body, 'the wither must never touch the receiver');
        self::assertSame(1, substr_count($body, 'clone $this'), 'exactly one clone site');
        self::assertSame(1, substr_count($body, '$clone->stages[]'), 'exactly one append site, on the clone');
    }

    public function testVerificationStageShapeSurvivesRegistryParseRoundTrip(): void
    {
        // The stage array keys mirror what WorkflowRegistry::parseStages() and
        // WorkflowEngine's verification executor consume — a rename upstream
        // must not be quietly stranded by the builder now emitting a clone
        // with drifted keys.
        $workflow = (new WorkflowBuilder())
            ->name('shape-pin')
            ->withVerification('check', Tasks::agent('coder')->prompt('make'), Tasks::agent('tester')->prompt('verify'))
            ->build();

        $stage = self::stagesOf($workflow)[0];
        self::assertArrayHasKey('task', $stage);
        self::assertArrayHasKey('verifier', $stage);
    }

    /** @return list<array<string, mixed>> */
    private static function stagesOf(Workflow $workflow): array
    {
        /** @var list<array<string, mixed>> $stages */
        $stages = $workflow->stages;

        return $stages;
    }

    private static function stageCount(Workflow $workflow): int
    {
        return count(self::stagesOf($workflow));
    }
}

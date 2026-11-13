<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Workflows\StageCondition;
use SugarCraft\Code\Workflows\WorkflowEngine;

/**
 * C2 (wave-R): the fixed-op stage-condition grammar, unit level.
 *
 * parse() is the LOAD-time gate — every refusal is raw English naming the
 * offender; evaluate() is the DISPATCH-time answer — fail-closed by
 * construction, a shape that bypassed parse() reads unsatisfied, never true,
 * never thrown. The engine integration (a false clause settles a Skipped row
 * without dispatch) lives in WorkflowEngineTest/WorkflowPauseResumeTest.
 */
final class StageConditionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // The closed rosters
    // -------------------------------------------------------------------------

    public function testTheGrammarRostersAreClosed(): void
    {
        $this->assertSame(['all', 'any'], StageCondition::COMBINATORS);
        $this->assertSame(['equals', 'contains', 'matches', 'empty'], StageCondition::OPS);
        $this->assertSame(['dry', 'budgetBelow'], StageCondition::LOOP_OPS);
        $this->assertSame('@loopDryRounds', StageCondition::LOOP_DRY_ROUNDS_CONTEXT_KEY);
        $this->assertSame('@budgetRemaining', StageCondition::BUDGET_REMAINING_CONTEXT_KEY);
        $this->assertSame(2, StageCondition::MAX_DEPTH, 'two combinator layers, never an unbounded tree');
    }

    // -------------------------------------------------------------------------
    // parse(): normalized round-trips
    // -------------------------------------------------------------------------

    public function testParseNormalizesEachLeafOp(): void
    {
        $this->assertSame(
            ['stage' => 'a', 'op' => 'equals', 'value' => 'x'],
            StageCondition::parse(['stage' => 'a', 'equals' => 'x']),
        );
        $this->assertSame(
            ['stage' => 'a', 'op' => 'contains', 'value' => 'GO'],
            StageCondition::parse(['stage' => 'a', 'contains' => 'GO']),
        );
        $this->assertSame(
            ['stage' => 'a', 'op' => 'matches', 'value' => '/^PASS/'],
            StageCondition::parse(['stage' => 'a', 'matches' => '/^PASS/']),
            'a valid PCRE rides through verbatim'
        );
    }

    public function testParseNormalizesCombinatorsAndDepthTwoNesting(): void
    {
        $this->assertSame(
            ['combinator' => 'all', 'conditions' => [
                ['stage' => 'a', 'op' => 'equals', 'value' => 'x'],
            ]],
            StageCondition::parse(['all' => [['stage' => 'a', 'equals' => 'x']]]),
        );

        // One nested combinator layer is the ceiling — at the ceiling it must pass.
        $this->assertSame(
            ['combinator' => 'any', 'conditions' => [
                ['stage' => 'a', 'op' => 'equals', 'value' => 'x'],
                ['combinator' => 'all', 'conditions' => [
                    ['stage' => 'b', 'op' => 'contains', 'value' => 'y'],
                ]],
            ]],
            StageCondition::parse([
                'any' => [
                    ['stage' => 'a', 'equals' => 'x'],
                    ['all' => [['stage' => 'b', 'contains' => 'y']]],
                ],
            ]),
        );
    }

    // -------------------------------------------------------------------------
    // parse(): every refusal names itself
    // -------------------------------------------------------------------------

    public function testParseRefusesTheEmptyMap(): void
    {
        $this->assertSame('when needs either a combinator (all/any) or a stage condition, got an empty map', $this->refusalMessage([]));
    }

    public function testParseRefusesBothCombinatorsOnOneNode(): void
    {
        $this->assertStringContainsString(
            'carries both "all" and "any"',
            $this->refusalMessage(['all' => [['stage' => 'a', 'equals' => 'x']], 'any' => []]),
        );
    }

    public function testParseRefusesNestingPastTheCeiling(): void
    {
        $message = $this->refusalMessage([
            'any' => [
                ['all' => [
                    ['any' => [['stage' => 'a', 'equals' => 'x']]],
                ]],
            ],
        ]);
        $this->assertStringContainsString('nests combinators 3 deep', $message);
        $this->assertStringContainsString('stops at 2 layers', $message);
    }

    public function testParseRefusesAnEmptyCombinatorList(): void
    {
        $this->assertStringContainsString(
            'must be a non-empty list of conditions, got a list',
            $this->refusalMessage(['all' => []]),
        );
    }

    public function testParseRefusesACombinatorHoldingSomethingElseThanAList(): void
    {
        $this->assertStringContainsString(
            'must be a non-empty list of conditions, got text',
            $this->refusalMessage(['all' => 'stage a says GO']),
        );
    }

    public function testParseRefusesANonConditionEntry(): void
    {
        $this->assertStringContainsString(
            'entry #0 must be a condition map, got a whole number',
            $this->refusalMessage(['all' => [7]]),
        );
    }

    public function testParseRefusesAUnknownKeyBesideACombinator(): void
    {
        $message = $this->refusalMessage([
            'all' => [['stage' => 'a', 'equals' => 'x']],
            'stage' => 'b',
        ]);
        $this->assertStringContainsString('when node carrying "all" also holds "stage", which is not a condition key', $message);
    }

    public function testParseRefusesALeafWithNoOp(): void
    {
        // A bare stage name is not a condition — there is nothing to answer.
        $message = $this->refusalMessage(['stage' => 'a']);
        $this->assertStringContainsString('when leaf needs one of equals, contains, matches', $message);
        $this->assertStringContainsString('is not a condition key', $message);
    }

    public function testParseRefusesALeafWithMoreThanOneOp(): void
    {
        $this->assertStringContainsString(
            'carries more than one op',
            $this->refusalMessage(['stage' => 'a', 'equals' => 'x', 'contains' => 'y']),
        );
    }

    public function testParseRefusesALeafWithoutAStageName(): void
    {
        $this->assertStringContainsString(
            'needs a non-empty "stage"',
            $this->refusalMessage(['equals' => 'x']),
        );
    }

    public function testParseRefusesAnEmptyStageName(): void
    {
        $this->assertStringContainsString(
            'needs a non-empty "stage"',
            $this->refusalMessage(['stage' => '', 'equals' => 'x']),
        );
    }

    public function testParseRefusesAUnknownKeyInALeaf(): void
    {
        $this->assertStringContainsString(
            'also holds "gt", which is not a condition key',
            $this->refusalMessage(['stage' => 'a', 'equals' => 'x', 'gt' => 'y']),
        );
    }

    public function testParseRefusesANonStringComparisonValue(): void
    {
        $this->assertStringContainsString(
            'value for stage "a" must be a string, got a whole number',
            $this->refusalMessage(['stage' => 'a', 'equals' => 3]),
        );
    }

    public function testParseRefusesAnUnclosedMatchesPatternAtLoadTime(): void
    {
        // The TaskBuilder::reject() precedent: a broken PCRE never rides in
        // to fail silently at every dispatch — it is refused where it is set.
        $this->assertStringContainsString(
            'is not a valid PCRE',
            $this->refusalMessage(['stage' => 'a', 'matches' => '/unclosed(']),
        );
    }

    // -------------------------------------------------------------------------
    // evaluate(): the truth table
    // -------------------------------------------------------------------------

    public function testEvaluateEqualsAndContainsAndMatches(): void
    {
        $context = ['a.output' => 'x', 'b.output' => 'ALL-GO', 'c.output' => 'PASS me'];

        $this->assertTrue(StageCondition::evaluate(
            StageCondition::parse(['stage' => 'a', 'equals' => 'x']), $context));
        $this->assertFalse(StageCondition::evaluate(
            StageCondition::parse(['stage' => 'a', 'equals' => 'x ']), $context),
            'equals is exact — a trailing space is a different string');

        $this->assertTrue(StageCondition::evaluate(
            StageCondition::parse(['stage' => 'b', 'contains' => 'GO']), $context));
        $this->assertFalse(StageCondition::evaluate(
            StageCondition::parse(['stage' => 'b', 'contains' => 'go']), $context),
            'contains is case-sensitive, matching the verdict scan plainness');

        $this->assertTrue(StageCondition::evaluate(
            StageCondition::parse(['stage' => 'c', 'matches' => '/^PASS/']), $context));
        $this->assertFalse(StageCondition::evaluate(
            StageCondition::parse(['stage' => 'c', 'matches' => '/^FAIL/']), $context));
    }

    public function testEvaluateResolvesThroughThePerResultsMap(): void
    {
        // A leaf may name a per-agent result key when no stage output exists.
        $context = [WorkflowEngine::RESULTS_CONTEXT_KEY => ['analyze' => 'from results']];

        $this->assertTrue(StageCondition::evaluate(
            StageCondition::parse(['stage' => 'analyze', 'equals' => 'from results']), $context));
    }

    public function testEvaluateFailsClosedOnAnAbsentStageText(): void
    {
        // The misspelled-stage law: judging a stage that produced nothing is
        // unsatisfied — the stage never runs rather than silently running.
        $this->assertFalse(StageCondition::evaluate(
            StageCondition::parse(['stage' => 'ghost', 'equals' => 'x']),
            ['a.output' => 'x']));
        $this->assertFalse(StageCondition::evaluate(
            StageCondition::parse(['stage' => 'ghost', 'contains' => '']),
            []));
    }

    public function testEvaluateCombinesChildren(): void
    {
        $context = ['a.output' => 'x', 'b.output' => 'y'];

        $this->assertTrue(StageCondition::evaluate(
            StageCondition::parse(['all' => [
                ['stage' => 'a', 'equals' => 'x'],
                ['stage' => 'b', 'equals' => 'y'],
            ]]), $context));
        $this->assertFalse(StageCondition::evaluate(
            StageCondition::parse(['all' => [
                ['stage' => 'a', 'equals' => 'x'],
                ['stage' => 'b', 'equals' => 'nope'],
            ]]), $context));
        $this->assertTrue(StageCondition::evaluate(
            StageCondition::parse(['any' => [
                ['stage' => 'a', 'equals' => 'nope'],
                ['stage' => 'b', 'equals' => 'y'],
            ]]), $context));
        $this->assertFalse(StageCondition::evaluate(
            StageCondition::parse(['any' => [
                ['stage' => 'a', 'equals' => 'nope'],
                ['stage' => 'b', 'equals' => 'nope'],
            ]]), $context));

        // Nested layer: any of {a-miss, all of {hit, hit}} reads satisfied.
        $this->assertTrue(StageCondition::evaluate(
            StageCondition::parse([
                'any' => [
                    ['stage' => 'a', 'equals' => 'nope'],
                    ['all' => [
                        ['stage' => 'a', 'equals' => 'x'],
                        ['stage' => 'b', 'equals' => 'y'],
                    ]],
                ],
            ]), $context));
    }

    public function testEvaluateNeverThrowsOnHandBuiltJunk(): void
    {
        // parse() guarantees shapes; a clause that bypassed it (an engine bug
        // or a hand-built array) must read unsatisfied, never crash a run.
        $context = ['a.output' => 'x'];

        $this->assertFalse(StageCondition::evaluate([], $context));
        $this->assertFalse(StageCondition::evaluate(['combinator' => 'none', 'conditions' => []], $context));
        $this->assertFalse(StageCondition::evaluate(['combinator' => 'all', 'conditions' => []], $context));
        $this->assertFalse(StageCondition::evaluate(['combinator' => 'all', 'conditions' => ['junk']], $context));
        $this->assertFalse(StageCondition::evaluate(['stage' => 'a', 'op' => 'unknown', 'value' => 'x'], $context));
        $this->assertFalse(StageCondition::evaluate(['stage' => 'a', 'op' => 'equals', 'value' => 3], $context));
        $this->assertFalse(StageCondition::evaluate(['stage' => 'a'], $context));
    }

    public function testEvaluateMatchesFailsClosedOnABrokenPatternAtRuntime(): void
    {
        // The verdict-scan law mirrored: a pattern that cannot compile at
        // match time answers unsatisfied — never true, never a warning leak.
        $this->assertFalse(StageCondition::evaluate(
            ['stage' => 'a', 'op' => 'matches', 'value' => '/(unclosed'],
            ['a.output' => 'x']));
    }

    /** Capture the raw-English refusal text for a clause. */
    private function refusalMessage(array $clause): string
    {
        try {
            StageCondition::parse($clause);
        } catch (\InvalidArgumentException $error) {
            return $error->getMessage();
        }

        $this->fail('parse() must refuse ' . json_encode($clause));
    }
}

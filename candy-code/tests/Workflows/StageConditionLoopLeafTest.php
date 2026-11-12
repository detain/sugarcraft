<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Workflows\StageCondition;

/**
 * P1-5: the loop-only leaves `{dry: N}` and `{budgetBelow: N}`. They are
 * admitted only by parse(..., allowLoopLeaves: true) — the loop.until
 * path — so `when:` keeps refusing them, and they answer from engine-kept
 * context keys; an absent key is unsatisfied.
 */
final class StageConditionLoopLeafTest extends TestCase
{
    public function testWhenRefusesLoopLeavesByDefault(): void
    {
        foreach (['dry', 'budgetBelow'] as $op) {
            $message = $this->refusal([$op => 2]);
            $this->assertSame(sprintf('when leaf "%s" is loop-only; it is accepted in a loop\'s until, never in when', $op), $message);
        }

        $this->assertStringContainsString(
            'when leaf "dry" is loop-only',
            $this->refusal(['any' => [['stage' => 'a', 'equals' => 'x'], ['dry' => 1]]]),
            'nested loop leaves are refused too',
        );
    }

    public function testTheDefaultSpellingsListDoesNotAdvertiseLoopLeaves(): void
    {
        $message = $this->refusal(['stage' => 'a']);
        $this->assertStringContainsString('needs one of equals, contains, matches, empty;', $message);
        $this->assertStringNotContainsString('dry', $message);
    }

    public function testUntilNormalizesLoopLeaves(): void
    {
        $this->assertSame(['op' => 'dry', 'value' => 2], StageCondition::parse(['dry' => 2], allowLoopLeaves: true));
        $this->assertSame(['op' => 'budgetBelow', 'value' => 50000], StageCondition::parse(['budgetBelow' => 50000], true));
        $this->assertSame(
            ['combinator' => 'any', 'conditions' => [
                ['op' => 'dry', 'value' => 2],
                ['stage' => 'find', 'pointer' => '/fresh', 'op' => 'empty', 'value' => true],
                ['combinator' => 'all', 'conditions' => [['op' => 'budgetBelow', 'value' => 10]]],
            ]],
            StageCondition::parse(['any' => [
                ['dry' => 2],
                ['stage' => 'find', 'pointer' => '/fresh', 'empty' => true],
                ['all' => [['budgetBelow' => 10]]],
            ]], true),
        );
    }

    public function testUntilStillParsesOrdinaryLeaves(): void
    {
        $this->assertSame(
            ['stage' => 'fix', 'op' => 'contains', 'value' => 'OK ('],
            StageCondition::parse(['stage' => 'fix', 'contains' => 'OK ('], true),
        );
    }

    public function testLoopLeafValuesMustBeWholeNumbersAtLeastOne(): void
    {
        $cases = [
            [0, 'got 0'],
            [-3, 'got -3'],
            [1.5, 'got float'],
            ['2', 'got string'],
            [true, 'got bool'],
            [null, 'got null'],
        ];
        foreach (['dry', 'budgetBelow'] as $op) {
            foreach ($cases as [$value, $tail]) {
                $this->assertSame(
                    sprintf('loop leaf "%s" must be a whole number >= 1, %s', $op, $tail),
                    $this->refusal([$op => $value], true),
                );
            }
        }
    }

    public function testLoopLeavesTakeNoOtherKey(): void
    {
        $this->assertSame(
            'loop leaf "dry" has unknown key "stage"; it is a whole condition on its own, e.g. {dry: 2}',
            $this->refusal(['stage' => 'find', 'dry' => 2], true),
        );
        $this->assertStringContainsString(
            'loop leaf "budgetBelow" has unknown key "pointer"',
            $this->refusal(['budgetBelow' => 5, 'pointer' => '/x'], true),
        );
    }

    public function testALeafCarriesExactlyOneOpAcrossBothRosters(): void
    {
        $message = $this->refusal(['dry' => 2, 'budgetBelow' => 5], true);
        $this->assertStringContainsString('carries more than one op ("dry", "budgetBelow")', $message);
        $this->assertStringContainsString('one of equals, contains, matches, empty, dry, budgetBelow per condition', $message);

        $this->assertStringContainsString(
            'carries more than one op ("equals", "dry")',
            $this->refusal(['stage' => 'a', 'equals' => 'x', 'dry' => 2], true),
        );
    }

    public function testUntilAdvertisesLoopLeavesInItsSpellings(): void
    {
        $this->assertStringContainsString(
            'needs one of equals, contains, matches, empty, dry, budgetBelow;',
            $this->refusal(['stag' => 'a'], true),
        );
    }

    public function testDryCountsConsecutiveDryRounds(): void
    {
        $clause = StageCondition::parse(['dry' => 2], true);
        $key = StageCondition::LOOP_DRY_ROUNDS_CONTEXT_KEY;

        $this->assertFalse(StageCondition::evaluate($clause, []), 'absent key is unsatisfied');
        $this->assertFalse(StageCondition::evaluate($clause, [$key => 0]));
        $this->assertFalse(StageCondition::evaluate($clause, [$key => 1]));
        $this->assertTrue(StageCondition::evaluate($clause, [$key => 2]));
        $this->assertTrue(StageCondition::evaluate($clause, [$key => 5]));
        $this->assertFalse(StageCondition::evaluate($clause, [$key => '2']), 'no coercion of a stringly count');
    }

    public function testBudgetBelowIsStrict(): void
    {
        $clause = StageCondition::parse(['budgetBelow' => 100], true);
        $key = StageCondition::BUDGET_REMAINING_CONTEXT_KEY;

        $this->assertFalse(StageCondition::evaluate($clause, []), 'no budget configured: the loop runs to max');
        $this->assertTrue(StageCondition::evaluate($clause, [$key => 99]));
        $this->assertTrue(StageCondition::evaluate($clause, [$key => 0]));
        $this->assertTrue(StageCondition::evaluate($clause, [$key => 99.5]));
        $this->assertFalse(StageCondition::evaluate($clause, [$key => 100]));
        $this->assertFalse(StageCondition::evaluate($clause, [$key => 5000]));
        $this->assertFalse(StageCondition::evaluate($clause, [$key => null]));
    }

    public function testLoopLeavesComposeWithStageLeaves(): void
    {
        $clause = StageCondition::parse(['any' => [
            ['dry' => 2],
            ['budgetBelow' => 1000],
        ]], true);

        $this->assertFalse(StageCondition::evaluate($clause, [
            StageCondition::LOOP_DRY_ROUNDS_CONTEXT_KEY => 1,
            StageCondition::BUDGET_REMAINING_CONTEXT_KEY => 5000,
        ]));
        $this->assertTrue(StageCondition::evaluate($clause, [
            StageCondition::LOOP_DRY_ROUNDS_CONTEXT_KEY => 1,
            StageCondition::BUDGET_REMAINING_CONTEXT_KEY => 500,
        ]));
        $this->assertTrue(StageCondition::evaluate($clause, [StageCondition::LOOP_DRY_ROUNDS_CONTEXT_KEY => 2]));
    }

    public function testEvaluateFailsClosedOnHandBuiltLoopJunk(): void
    {
        $context = [StageCondition::LOOP_DRY_ROUNDS_CONTEXT_KEY => 9, StageCondition::BUDGET_REMAINING_CONTEXT_KEY => 0];
        $this->assertFalse(StageCondition::evaluate(['op' => 'dry', 'value' => 0], $context));
        $this->assertFalse(StageCondition::evaluate(['op' => 'dry', 'value' => '2'], $context));
        $this->assertFalse(StageCondition::evaluate(['op' => 'budgetBelow'], $context));
        $this->assertFalse(StageCondition::evaluate(['op' => 'wet', 'value' => 1], $context));
    }

    private function refusal(array $clause, bool $allowLoopLeaves = false): string
    {
        try {
            StageCondition::parse($clause, $allowLoopLeaves);
        } catch (\InvalidArgumentException $error) {
            return $error->getMessage();
        }

        $this->fail('parse() must refuse ' . json_encode($clause));
    }
}

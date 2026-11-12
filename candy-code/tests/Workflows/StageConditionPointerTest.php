<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tests\Support\RefusesStageConditionTrait;
use SugarCraft\Code\Workflows\StageCondition;
use SugarCraft\Code\Workflows\WorkflowEngine;

/**
 * P1-5: the `pointer:` leaf key (judge a leaf of the stage's TYPED row) and
 * the `empty: true|false` op. A pointer that resolves nowhere — or a stage
 * with no typed row at all — is unsatisfied (the fail-closed law).
 */
final class StageConditionPointerTest extends TestCase
{
    use RefusesStageConditionTrait;

    private function context(): array
    {
        return [
            // The text row exists too; a pointer leaf must NOT read it.
            'review.output' => '{"verdict":"PASS"}',
            WorkflowEngine::TYPED_RESULTS_CONTEXT_KEY => [
                'review' => json_encode([
                    'verdict' => 'NEEDS_FIX',
                    'score' => 7,
                    'ratio' => 0.5,
                    'ok' => false,
                    'notes' => null,
                    'findings' => [['id' => 'f1', 'path' => 'src/a.php']],
                    'fresh' => [],
                    'summary' => '',
                    'meta' => ['tag' => 'é'],
                ]),
            ],
            'plain.output' => '',
            'wordy.output' => "  \n",
        ];
    }

    private function holds(array $clause, ?array $context = null): bool
    {
        return StageCondition::evaluate(StageCondition::parse($clause), $context ?? $this->context());
    }

    // -------------------------------------------------------------------------
    // parse()
    // -------------------------------------------------------------------------

    public function testParseNormalizesAPointerLeaf(): void
    {
        $this->assertSame(
            ['stage' => 'review', 'pointer' => '/verdict', 'op' => 'equals', 'value' => 'NEEDS_FIX'],
            StageCondition::parse(['stage' => 'review', 'pointer' => '/verdict', 'equals' => 'NEEDS_FIX']),
        );
        $this->assertSame(
            ['stage' => 'review', 'pointer' => 'findings', 'op' => 'empty', 'value' => false],
            StageCondition::parse(['pointer' => 'findings', 'empty' => false, 'stage' => 'review']),
            'key order in the author map does not matter; the bare pointer form rides verbatim'
        );
    }

    public function testParseNormalizesATextEmptyLeafWithoutAPointerSlot(): void
    {
        $this->assertSame(
            ['stage' => 'plain', 'op' => 'empty', 'value' => true],
            StageCondition::parse(['stage' => 'plain', 'empty' => true]),
        );
    }

    public function testParseRefusesABadPointer(): void
    {
        $this->assertStringContainsString(
            'when "pointer" for stage "review" must be a non-empty string (an RFC 6901 pointer such as /verdict), got an empty string',
            $this->refusal(['stage' => 'review', 'pointer' => '', 'equals' => 'x']),
        );
        $this->assertStringContainsString(
            'must be a non-empty string (an RFC 6901 pointer such as /verdict), got int',
            $this->refusal(['stage' => 'review', 'pointer' => 3, 'equals' => 'x']),
        );
        $this->assertStringContainsString(
            'got null',
            $this->refusal(['stage' => 'review', 'pointer' => null, 'equals' => 'x']),
        );
        $this->assertStringContainsString(
            'is not a valid RFC 6901 pointer: "/a~2"',
            $this->refusal(['stage' => 'review', 'pointer' => '/a~2', 'equals' => 'x']),
        );
    }

    public function testParseRefusesANonBoolEmptyValue(): void
    {
        $this->assertStringContainsString(
            'when "empty" value for stage "plain" must be true or false, got string',
            $this->refusal(['stage' => 'plain', 'empty' => 'true']),
        );
        $this->assertStringContainsString(
            'must be true or false, got int',
            $this->refusal(['stage' => 'plain', 'empty' => 1]),
        );
    }

    public function testParseStillRefusesANonStringComparisonBehindAPointer(): void
    {
        // No coercion: a YAML `equals: true` is a bool and is refused — quote it.
        $this->assertStringContainsString(
            'when "equals" value for stage "review" must be a string, got bool',
            $this->refusal(['stage' => 'review', 'pointer' => '/ok', 'equals' => true]),
        );
    }

    public function testParseRefusesEmptyBesideAnotherOp(): void
    {
        $message = $this->refusal(['stage' => 'review', 'pointer' => '/fresh', 'empty' => true, 'equals' => '[]']);
        $this->assertStringContainsString('carries more than one op', $message);
        $this->assertStringContainsString('one of equals, contains, matches, empty per condition', $message);
    }

    public function testParseRefusesAPointerWithoutAStage(): void
    {
        $this->assertStringContainsString(
            'needs a non-empty "stage"',
            $this->refusal(['pointer' => '/verdict', 'equals' => 'x']),
        );
    }

    public function testParseNamesPointerAmongTheLeafSpellings(): void
    {
        $this->assertStringContainsString(
            'unknown key "path"; supported spellings: stage, pointer, equals',
            $this->refusal(['stage' => 'review', 'path' => '/verdict', 'equals' => 'x']),
        );
    }

    // -------------------------------------------------------------------------
    // evaluate(): pointer leaves
    // -------------------------------------------------------------------------

    public function testPointerLeafReadsTheTypedRowNotTheText(): void
    {
        $this->assertTrue($this->holds(['stage' => 'review', 'pointer' => '/verdict', 'equals' => 'NEEDS_FIX']));
        $this->assertFalse($this->holds(['stage' => 'review', 'pointer' => '/verdict', 'equals' => 'PASS']));
        $this->assertTrue($this->holds(['stage' => 'review', 'pointer' => 'verdict', 'contains' => 'FIX']), 'bare pointer form');
        $this->assertTrue($this->holds(['stage' => 'review', 'pointer' => '/findings/0/id', 'matches' => '/^f\d$/']));
    }

    public function testScalarLeavesCompareByTheirStringForm(): void
    {
        $this->assertTrue($this->holds(['stage' => 'review', 'pointer' => '/score', 'equals' => '7']));
        $this->assertTrue($this->holds(['stage' => 'review', 'pointer' => '/ratio', 'equals' => '0.5']));
        $this->assertTrue($this->holds(['stage' => 'review', 'pointer' => '/ok', 'equals' => 'false']));
        $this->assertTrue($this->holds(['stage' => 'review', 'pointer' => '/notes', 'equals' => 'null']), 'a found JSON null compares as null');
    }

    public function testStructuredLeavesCompareAsCompactJson(): void
    {
        $this->assertTrue($this->holds([
            'stage' => 'review', 'pointer' => '/findings', 'equals' => '[{"id":"f1","path":"src/a.php"}]',
        ]));
        $this->assertTrue($this->holds(['stage' => 'review', 'pointer' => '/findings', 'contains' => '"src/a.php"']), 'slashes unescaped');
        $this->assertTrue($this->holds(['stage' => 'review', 'pointer' => '/meta', 'equals' => '{"tag":"é"}']), 'unicode unescaped');
        $this->assertTrue($this->holds(['stage' => 'review', 'pointer' => '/fresh', 'equals' => '[]']));
    }

    public function testPointerMissesAreUnsatisfied(): void
    {
        $this->assertFalse($this->holds(['stage' => 'review', 'pointer' => '/ghost', 'equals' => 'x']));
        $this->assertFalse($this->holds(['stage' => 'review', 'pointer' => '/ghost', 'empty' => true]));
        $this->assertFalse($this->holds(['stage' => 'review', 'pointer' => '/ghost', 'empty' => false]));
        $this->assertFalse($this->holds(['stage' => 'review', 'pointer' => '/findings/5', 'contains' => '']));
    }

    public function testAStageWithoutATypedRowIsUnsatisfied(): void
    {
        // plain has a text row but no schema'd typed row: a pointer reaches nothing.
        $this->assertFalse($this->holds(['stage' => 'plain', 'pointer' => '/x', 'empty' => true]));
        $this->assertFalse($this->holds(['stage' => 'ghost', 'pointer' => '/verdict', 'contains' => '']));
        $this->assertFalse($this->holds(['stage' => 'review', 'pointer' => '/verdict', 'contains' => ''], ['review.output' => '{"verdict":"x"}']));
        $this->assertFalse($this->holds(
            ['stage' => 'review', 'pointer' => '/verdict', 'contains' => ''],
            [WorkflowEngine::TYPED_RESULTS_CONTEXT_KEY => ['review' => '{broken']],
        ));
        $this->assertFalse($this->holds(
            ['stage' => 'review', 'pointer' => '/verdict', 'contains' => ''],
            [WorkflowEngine::TYPED_RESULTS_CONTEXT_KEY => 'not a map'],
        ));
    }

    // -------------------------------------------------------------------------
    // evaluate(): empty
    // -------------------------------------------------------------------------

    public function testEmptyOverPointedArraysAndStrings(): void
    {
        $this->assertTrue($this->holds(['stage' => 'review', 'pointer' => '/fresh', 'empty' => true]));
        $this->assertFalse($this->holds(['stage' => 'review', 'pointer' => '/fresh', 'empty' => false]));
        $this->assertTrue($this->holds(['stage' => 'review', 'pointer' => '/findings', 'empty' => false]));
        $this->assertFalse($this->holds(['stage' => 'review', 'pointer' => '/findings', 'empty' => true]));
        $this->assertTrue($this->holds(['stage' => 'review', 'pointer' => '/summary', 'empty' => true]));
        $this->assertTrue($this->holds(['stage' => 'review', 'pointer' => '/verdict', 'empty' => false]));
    }

    public function testEmptyRefusesToJudgeNumbersBoolsAndNull(): void
    {
        // No coercion: 0, false and null are neither empty nor non-empty.
        foreach (['/score', '/ok', '/notes'] as $pointer) {
            $this->assertFalse($this->holds(['stage' => 'review', 'pointer' => $pointer, 'empty' => true]), $pointer);
            $this->assertFalse($this->holds(['stage' => 'review', 'pointer' => $pointer, 'empty' => false]), $pointer);
        }
    }

    public function testEmptyOverStageTextIsExactNotTrimmed(): void
    {
        $this->assertTrue($this->holds(['stage' => 'plain', 'empty' => true]));
        $this->assertFalse($this->holds(['stage' => 'plain', 'empty' => false]));
        $this->assertFalse($this->holds(['stage' => 'wordy', 'empty' => true]), 'whitespace is content');
        $this->assertTrue($this->holds(['stage' => 'wordy', 'empty' => false]));
        $this->assertFalse($this->holds(['stage' => 'ghost', 'empty' => true]), 'a stage that never ran is not "empty"');
        $this->assertFalse($this->holds(['stage' => 'ghost', 'empty' => false]));
    }

    public function testPointerLeavesComposeInCombinators(): void
    {
        $this->assertTrue($this->holds(['all' => [
            ['stage' => 'review', 'pointer' => '/verdict', 'equals' => 'NEEDS_FIX'],
            ['any' => [
                ['stage' => 'review', 'pointer' => '/fresh', 'empty' => false],
                ['stage' => 'review', 'pointer' => '/findings', 'empty' => false],
            ]],
        ]]));
    }

    public function testEvaluateFailsClosedOnHandBuiltPointerJunk(): void
    {
        $context = $this->context();
        $this->assertFalse(StageCondition::evaluate(['stage' => 'review', 'pointer' => 3, 'op' => 'equals', 'value' => 'x'], $context));
        $this->assertFalse(StageCondition::evaluate(['stage' => 'review', 'pointer' => '', 'op' => 'equals', 'value' => 'x'], $context));
        $this->assertFalse(StageCondition::evaluate(['stage' => 'review', 'pointer' => '/fresh', 'op' => 'empty', 'value' => 'yes'], $context));
        $this->assertFalse(StageCondition::evaluate(['stage' => 'plain', 'op' => 'empty', 'value' => 1], $context));
        $this->assertFalse(StageCondition::evaluate(['stage' => 'review', 'pointer' => '/verdict', 'op' => 'equals', 'value' => 7], $context));
    }
}

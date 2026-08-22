<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\Read;

/**
 * `TruncatesOutput::DEFAULT_MAX_OUTPUT_BYTES` is one half of a relationship
 * whose other half lives two namespaces away, and until now only the far half
 * knew about it.
 *
 * WHY IT MATTERS WHERE THE NOTE IS. Grep and Glob spend a
 * {@see SkillPathNudge::CALLER_BUDGET_DIVISOR} share of their output cap on the
 * path-scoped skill nudge, INSIDE the cap. Below a certain cap that share can
 * no longer hold a whole nudge and the model gets a clipped one.
 * {@see SkillPathNudge::smallestUnclippedCallerCap()} is that cap and exists
 * precisely to be cited — but someone lowering a tool's output cap arrives at
 * the constant, not at the nudge, and the constant said nothing. The guard that
 * enforces the margin lives in `SkillPathScopingWiringTest`, which is a file
 * that reader has no reason to open either.
 *
 * WHAT THIS FILE PINS, and why it is not a duplicate of that guard. That guard
 * asserts the RELATIONSHIP — the tightest shipped budget clears the ceiling by
 * the decided margin — and reds when the code drifts. This one asserts the
 * PROSE: every figure the constant's doc-block now quotes is re-derived here
 * and required to appear there. A figure in a comment is not a measurement, and
 * five rounds of this tree have produced a comment whose numbers had moved
 * underneath it. Change any of the four inputs and this reds with the number to
 * write instead.
 *
 * DELIBERATELY NOT ASSERTED HERE: that the margin is >= 2.0. That is
 * `SkillPathScopingWiringTest`'s claim, it is enforced there over the whole
 * shipped roster rather than over the two caps named in the comment, and
 * copying it would give the tree two guards that disagree the day a third tool
 * joins the roster. What is asserted is only that the doc-block's stated
 * DECISION (2.0x) is the constant that guard actually enforces — so the comment
 * cannot go on quoting a threshold nobody kept.
 *
 * @internal
 */
final class TruncatesOutputNudgeMarginDocTest extends TestCase
{
    private function docBlock(): string
    {
        $constant = new ReflectionClassConstant(Grep::class, 'DEFAULT_MAX_OUTPUT_BYTES');
        $doc = $constant->getDocComment();
        self::assertIsString(
            $doc,
            'TruncatesOutput::DEFAULT_MAX_OUTPUT_BYTES has lost its doc-block, and with it the only '
            . 'pointer from a tool output cap to the nudge ceiling that cap constrains',
        );

        return (string) preg_replace('/\s+/', ' ', $doc);
    }

    private function constantOf(string $class, string $name): int
    {
        $c = new ReflectionClassConstant($class, $name);

        return (int) $c->getValue();
    }

    /**
     * The four figures E87 decided, re-derived and required to be the ones the
     * comment quotes.
     */
    public function testTheCitedFiguresAreTheOnesTheCodeProduces(): void
    {
        $ceiling = SkillPathNudge::maxBytes();
        $divisor = SkillPathNudge::CALLER_BUDGET_DIVISOR;
        $smallest = SkillPathNudge::smallestUnclippedCallerCap();
        $grepCap = $this->constantOf(Grep::class, 'DEFAULT_MAX_OUTPUT_BYTES');
        $readCap = $this->constantOf(Read::class, 'DEFAULT_MAX_BYTES');

        self::assertSame(
            $ceiling * $divisor,
            $smallest,
            'smallestUnclippedCallerCap() is no longer ceiling x divisor; the doc-block derives it that way',
        );

        $doc = $this->docBlock();

        foreach ([
            'the nudge ceiling' => 'ceiling ' . number_format($ceiling) . ' bytes',
            'the divisor' => 'divisor ' . $divisor,
            'the smallest unclipped caller cap' => 'is ' . number_format($smallest),
            "this constant's own value" => "this constant's " . number_format($grepCap),
        ] as $what => $needle) {
            self::assertStringContainsString(
                $needle,
                $doc,
                sprintf(
                    'DEFAULT_MAX_OUTPUT_BYTES\'s doc-block no longer quotes %s as "%s". A figure in a '
                    . 'comment is not a measurement; re-derive it and rewrite the sentence.',
                    $what,
                    $needle,
                ),
            );
        }

        self::assertStringContainsString(
            sprintf('margin of %.2fx', $grepCap / $smallest),
            $doc,
            'the Grep/Glob margin quoted in DEFAULT_MAX_OUTPUT_BYTES\'s doc-block is not the one the '
            . 'constants produce (' . sprintf('%.2fx', $grepCap / $smallest) . ')',
        );
        self::assertStringContainsString(
            sprintf('is %.2fx', $readCap / $smallest),
            $doc,
            'the Read margin quoted in DEFAULT_MAX_OUTPUT_BYTES\'s doc-block is not the one the '
            . 'constants produce (' . sprintf('%.2fx', $readCap / $smallest) . ')',
        );
    }

    /**
     * The actionable floor the comment states must be the decided margin
     * applied to the derived cap — the number a reader lowering this constant
     * will actually stop at.
     */
    public function testTheActionableFloorIsTheDecidedMarginTimesTheDerivedCap(): void
    {
        $required = $this->requiredMarginFromTheGuard();
        $floor = (int) (SkillPathNudge::smallestUnclippedCallerCap() * $required);

        self::assertStringContainsString(
            'actionable floor for this constant is ' . number_format($floor),
            $this->docBlock(),
            sprintf(
                'DEFAULT_MAX_OUTPUT_BYTES\'s doc-block states an actionable floor that is not %.1fx the '
                . 'smallest unclipped caller cap. It should read %s.',
                $required,
                number_format($floor),
            ),
        );
    }

    /**
     * The doc-block must point at the far half by NAME, both the method and the
     * guard, or the loop it exists to close is open again.
     */
    public function testTheDocBlockNamesTheMethodAndTheGuardThatEnforceIt(): void
    {
        $doc = $this->docBlock();

        foreach ([
            'SkillPathNudge::smallestUnclippedCallerCap()',
            'SkillPathNudge::maxBytes()',
            'SkillPathNudge::CALLER_BUDGET_DIVISOR',
            'SkillPathScopingWiringTest::testTheShippedCapsClearTheCeilingByTheDecidedMargin()',
            'testEveryShippedNudgeBudgetClearsTheTrackerCeiling()',
        ] as $symbol) {
            self::assertStringContainsString(
                $symbol,
                $doc,
                "DEFAULT_MAX_OUTPUT_BYTES's doc-block no longer names {$symbol}. It is cited by SYMBOL "
                . 'rather than by line number precisely so it survives the next edit; if the symbol was '
                . 'renamed, follow it rather than dropping the pointer.',
            );
        }

        // The two tools that take this default and are NOT in the relationship
        // must stay named, or the margin reads as a property of the constant
        // rather than of the tools that spend a nudge share of it.
        foreach (['Bash', 'LspTool'] as $bystander) {
            self::assertStringContainsString(
                $bystander,
                $doc,
                "DEFAULT_MAX_OUTPUT_BYTES's doc-block no longer names {$bystander} as a user of this "
                . 'default that spends no nudge budget',
            );
        }
    }

    /**
     * The decided margin the comment quotes must be the one the guard enforces.
     *
     * Read off the guard's own constant rather than restated, so the comment
     * cannot go on quoting a threshold that was changed there.
     */
    private function requiredMarginFromTheGuard(): float
    {
        $guard = \SugarCraft\Crush\Tests\Integration\SkillPathScopingWiringTest::class;
        self::assertTrue(
            class_exists($guard),
            'the guard DEFAULT_MAX_OUTPUT_BYTES points at no longer exists',
        );

        $reflection = new ReflectionClass($guard);
        self::assertTrue(
            $reflection->hasConstant('REQUIRED_CEILING_MARGIN'),
            'SkillPathScopingWiringTest no longer declares REQUIRED_CEILING_MARGIN, which is the '
            . 'threshold DEFAULT_MAX_OUTPUT_BYTES\'s doc-block quotes',
        );

        $margin = (float) (new ReflectionClassConstant($guard, 'REQUIRED_CEILING_MARGIN'))->getValue();

        self::assertStringContainsString(
            sprintf('keep at least %.1fx', $margin),
            $this->docBlock(),
            sprintf(
                'DEFAULT_MAX_OUTPUT_BYTES\'s doc-block quotes a decided margin that is not the %.1fx '
                . 'SkillPathScopingWiringTest::REQUIRED_CEILING_MARGIN enforces',
                $margin,
            ),
        );

        return $margin;
    }
}

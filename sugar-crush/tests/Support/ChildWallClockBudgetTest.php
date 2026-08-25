<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A CHILD BOUND AT THE TEST'S OWN CEILING IS A BOUND THAT NEVER REPORTS.
 *
 * This suite launches `bin/sugarcrush` as a real child in a dozen places, each
 * wrapped in `timeout -s KILL N` so a wedged child cannot stall the run. The
 * test then asserts on the child's exit status — 137 when the budget killed it,
 * something else when it did not — and that assertion is the whole point of the
 * wrapper.
 *
 * BUT THE PARENT HAS A BUDGET TOO. `phpunit.xml` sets `enforceTimeLimit` with a
 * `defaultTimeLimit`, enforced by `pcntl_alarm()`, and with `failOnRisky` an
 * abort is a red run. When the child's budget EQUALS the parent's, the two
 * alarms are racing over the same instant and the parent's wins in practice:
 * the child was started some milliseconds into the test, so it is still the
 * parent that reaches its limit first. What the reader then gets is "This test
 * was aborted after N seconds" — a message that names no child, sheds every
 * assertion below the `exec()`, and looks identical whatever the child was
 * doing.
 *
 * TWELVE SITES IN SIX FILES SAT AT EXACTLY THE CEILING, which is how this got
 * written: the number in the command and the number in `phpunit.xml` were the
 * same 60, and nothing connected them. `tests/Cli/BootstrapSkillSkipsTest.php`
 * had already reasoned it out in prose — its failure message warns that "at or
 * past the per-test limit in phpunit.xml, PHPUnit's own alarm wins the race
 * instead" — and had picked 20 seconds for exactly that reason. The prose was
 * right and unenforced; twelve later call sites were written without it.
 *
 * WHAT THIS ASSERTS, and it is a RELATION rather than a number: every literal
 * `timeout -s KILL N` in `tests/` has `N` strictly under the `defaultTimeLimit`
 * that `phpunit.xml` actually declares, with headroom. Both sides are READ from
 * the tree, so editing either one is caught: raising a child budget reds here,
 * and so does lowering `defaultTimeLimit` under an existing child budget. No
 * count is asserted (rule 18) — the census re-derives itself.
 *
 * WHAT IT DOES NOT COVER. A budget passed through `sprintf()` as a `%d`
 * placeholder rather than as a digit is not EVALUATED: resolving it means
 * following an argument list, and a scan that guesses is worse than one that
 * says what it cannot see. Those sites are at 20 by inspection, MEASURED at
 * the time of writing, and {@see testTheParametrisedFormIsSeenAndReported()}
 * makes them VISIBLE rather than silently absent — a scan that quietly ignored
 * them would be reporting a clean tree over a roster it had narrowed itself.
 *
 * NO COUNT OF THEM IS WRITTEN HERE, and the earlier draft of this paragraph is
 * why. WHAT IT SAID: "which two files use". WHAT IS TRUE NOW: it was five
 * sites in four files when that sentence was written, so the number was wrong
 * in the commit that shipped it — and it was a cardinality over `tests/`,
 * which the next lane to add a launch helper invalidates anyway (rule 18).
 * WHY THE SENTENCE STILL EARNS ITS PLACE: the SHAPE is the coverage statement,
 * and a reader who does not know this form exists will read the empty verdict
 * below as covering it.
 *
 * AND THIS FILE DOES NOT SPELL EITHER FORM (rule 26, and rule 40 under it).
 * The census walks its own directory, so a wrapper-and-number written out in a
 * paragraph here is scraped as a real child budget and a wrapper-and-`%d` is
 * scraped as a real parametrised one — which is exactly how the liveness arm
 * below came to be satisfied by the sentence describing it. Every occurrence
 * in this file is assembled at run time, and
 * {@see testThisFileIsNotItsOwnEvidence()} pins that.
 *
 * MEASURED ON PHP 8.3.6, PHPUnit 10.5.64. `timeout(1)` is coreutils and is not
 * a PHP behaviour, so the stamp is provenance for the surrounding claims.
 *
 * @internal
 */
final class ChildWallClockBudgetTest extends TestCase
{
    // THE WALK AND THE READ ARE BORROWED RATHER THAN GROWN AGAIN. The first
    // draft had its own `realpath(__DIR__ . '/../..')`, which
    // `DuplicatedTestHelperDriftTest` reported as a one-token copy of the same
    // helper in `SymbolCitationDriftTest` — a private helper has no other
    // reader, so a copy fixed in one place stays green in both.
    use RefusesAnUnreadableSourceTrait;
    use TestFileWalkTrait;

    /**
     * How much of the parent's budget must remain unspent.
     *
     * A child bound one second under the parent's is still a race — the parent
     * started first and is always ahead. This is the margin that makes the
     * child's alarm the one that reports, and it is generous because the cost
     * of being wrong in the other direction is only a louder failure.
     */
    private const REQUIRED_HEADROOM_SECONDS = 10;

    /**
     * The per-test limit `phpunit.xml` actually declares, read rather than
     * remembered.
     *
     * A GUARD THAT CANNOT READ ITS OWN REFERENCE MUST GO RED (rule 14): a
     * missing attribute here would otherwise make every comparison below
     * vacuous.
     */
    private function defaultTimeLimit(): int
    {
        $xml = self::readOrFail(\dirname(__DIR__, 2) . '/phpunit.xml');

        self::assertSame(
            1,
            preg_match('/\bdefaultTimeLimit="(\d+)"/', $xml, $m),
            'phpunit.xml declares no defaultTimeLimit, so nothing here is comparing against '
            . 'anything; this guard must be re-derived rather than left passing',
        );

        self::assertSame(
            1,
            preg_match('/\benforceTimeLimit="true"/', $xml),
            'enforceTimeLimit is off, so the parent alarm this guard reasons about does not '
            . 'fire; the reasoning has to be rewritten rather than the assertion relaxed',
        );

        return (int) $m[1];
    }

    /**
     * Every literal child budget in `tests/`, and every parametrised one.
     *
     * @return array{literal: list<array{0: string, 1: int, 2: int}>, parametrised: list<string>}
     */
    private function childBudgets(): array
    {
        $literal = [];
        $parametrised = [];

        foreach (self::everyTestFile() as $relative => $path) {
            $source = self::readOrFail($path);
            $label = 'tests/' . $relative;

            // THE ALPHABET IS BOTH FORMS AND THE UNPARSEABLE ONE IS REPORTED,
            // not dropped. `%d` is how the two files that got this right spell
            // it, and a scan that matched only digits would report those as
            // absent — the same clean-looking zero rule 14 is about.
            preg_match_all('/timeout -s KILL (\d+|%d)/', $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as $hit) {
                $line = substr_count(substr($source, 0, (int) $hit[1]), "\n") + 1;
                if ($hit[0] === '%d') {
                    $parametrised[] = $label . ':' . $line;

                    continue;
                }
                $literal[] = [$label, $line, (int) $hit[0]];
            }
        }
        sort($literal);
        sort($parametrised);

        return ['literal' => $literal, 'parametrised' => $parametrised];
    }

    /**
     * The number every child budget in the tree has to come in at or under.
     *
     * EXTRACTED BECAUSE THE EXPRESSION AT THE CALL SITE WAS THE ONE THING
     * NOTHING WATCHED. It used to be spelled inline in the guard below, and
     * widening it there by a hundred seconds — the change that switches the
     * whole check off — passed the ENTIRE SUITE, byte-identical: the fixture
     * beside it drove {@see tooLooseIn()} with literal ceilings, so the derived
     * number was never the subject of any assertion. That is rule 2 one level
     * out from where it was first found: the mutation was relevant, the
     * assertion's window was in the wrong place.
     *
     * {@see testTheComparisonRejectsBudgetsWhoseAnswerIsKnown()} now drives
     * rows derived from THIS method and states the invariant it has to satisfy
     * as a relation, so a widened ceiling has nowhere left to hide.
     */
    private function ceiling(): int
    {
        return $this->defaultTimeLimit() - self::REQUIRED_HEADROOM_SECONDS;
    }

    /**
     * The rows in `$literal` whose budget is over `$ceiling`.
     *
     * EXTRACTED BECAUSE A MUTATION OF IT SURVIVED. Written inline in the guard
     * below, widening the comparison by a hundred seconds — which switches the
     * check off — passed the whole file: every budget in the tree is 20 against
     * a ceiling of 50, so nothing sits near the boundary and no real row can
     * tell a working comparison from a disabled one. That is rule 25 exactly:
     * a fixture whose expected value is what a DEAD instrument returns proves
     * nothing. {@see testTheComparisonRejectsBudgetsWhoseAnswerIsKnown()} drives
     * this with rows either side of the boundary.
     *
     * @param list<array{0: string, 1: int, 2: int}> $literal
     *
     * @return list<string>
     */
    private function tooLooseIn(array $literal, int $ceiling): array
    {
        $tooLoose = [];
        foreach ($literal as [$label, $line, $seconds]) {
            if ($seconds > $ceiling) {
                // ASSEMBLED for the same reason the fixture's expectations are:
                // a literal here is a match for this census's own scan of this
                // file.
                $tooLoose[] = $label . ':' . $line . ' — ' . 'timeout -s ' . 'KILL ' . $seconds;
            }
        }

        return $tooLoose;
    }

    /**
     * The comparison answers rows whose verdict is already known.
     *
     * FOUR ROWS STRADDLING THE BOUNDARY, because the interesting failures are
     * off-by-one in either direction: at the ceiling is fine, one over is not,
     * and a row far under must never be reported.
     */
    public function testTheComparisonRejectsBudgetsWhoseAnswerIsKnown(): void
    {
        $rows = [
            ['fixture/Far.php', 1, 5],
            ['fixture/At.php', 2, 50],
            ['fixture/OneOver.php', 3, 51],
            ['fixture/AtTheLimit.php', 4, 60],
        ];

        // THE EXPECTED STRINGS ARE ASSEMBLED, NEVER SPELLED (rule 26). This file
        // is inside its own roster: a literal command-and-number written here
        // is scraped by the census as a real child budget, and the first draft
        // of this fixture reported ITSELF as two offenders.
        $shape = 'timeout -s ' . 'KILL ';
        $this->assertSame(
            [
                'fixture/OneOver.php:3 — ' . $shape . '51',
                'fixture/AtTheLimit.php:4 — ' . $shape . '60',
            ],
            $this->tooLooseIn($rows, 50),
            'the comparison does not separate a budget over the ceiling from one at or under '
            . 'it, so the empty verdict over the real tree is satisfied by a disabled check',
        );

        $this->assertSame(
            [],
            $this->tooLooseIn($rows, 60),
            'a ceiling every row satisfies still produced findings, so the comparison reports '
            . 'rows for reasons of its own',
        );

        // AND THE SAME TWO ROWS AGAIN, DERIVED FROM THE CEILING THE GUARD
        // ACTUALLY USES. The four rows above are literals, so they cannot tell
        // a correct `ceiling()` from one widened by a hundred seconds — the
        // mutation that survived the whole suite. These two straddle whatever
        // `ceiling()` answers, so the boundary moves with it and the pair below
        // is what stops it moving anywhere it likes.
        $ceiling = $this->ceiling();
        $this->assertSame(
            ['fixture/OverTheDerivedCeiling.php:6 — ' . $shape . ($ceiling + 1)],
            $this->tooLooseIn(
                [
                    ['fixture/AtTheDerivedCeiling.php', 5, $ceiling],
                    ['fixture/OverTheDerivedCeiling.php', 6, $ceiling + 1],
                ],
                $ceiling,
            ),
            'the comparison does not separate a budget one second over the DERIVED ceiling '
            . 'from one exactly at it',
        );

        // THE INVARIANT THE DERIVED NUMBER HAS TO SATISFY, STATED AS A RELATION
        // AND NOT AS A SECOND SPELLING OF THE SAME ARITHMETIC. A ceiling widened
        // by any amount leaves the parent less headroom than the constant
        // declares, and that is the property, not the subtraction.
        $limit = $this->defaultTimeLimit();
        $this->assertGreaterThanOrEqual(
            self::REQUIRED_HEADROOM_SECONDS,
            $limit - $ceiling,
            'the ceiling leaves the parent alarm less room than REQUIRED_HEADROOM_SECONDS '
            . 'declares, so a child budget this guard accepts can still win the race the '
            . 'headroom exists to lose',
        );
        $this->assertLessThan(
            $limit,
            $ceiling,
            'the ceiling is at or above the per-test limit it is derived from, so every child '
            . 'budget passes and this guard asserts nothing',
        );
    }

    /**
     * This file is not the evidence for its own liveness arms.
     *
     * RULE 40, AND IT WAS BOUGHT ONCE ALREADY. The census walks `tests/`, which
     * includes this file, and the paragraph explaining the parametrised form
     * used to SPELL that form — so `assertNotSame([], $parametrised)` below was
     * satisfied by the sentence describing the arm, and a mutation restricting
     * the parametrised scan to this one file survived the entire suite. An
     * exemption keyed on prose is bought with a sentence, and the fix's own
     * comment is what buys it.
     *
     * The resolution is structural rather than textual: every occurrence of
     * either form in this file is assembled at run time, and this asserts the
     * census sees nothing here at all. Spell either form in a comment and this
     * reds, in the file where that matters most.
     */
    public function testThisFileIsNotItsOwnEvidence(): void
    {
        $budgets = $this->childBudgets();
        $self = 'tests/Support/' . basename(__FILE__);

        $mine = array_merge(
            array_values(array_filter(
                array_map(static fn (array $row): string => $row[0] . ':' . $row[1], $budgets['literal']),
                static fn (string $row): bool => str_starts_with($row, $self . ':'),
            )),
            array_values(array_filter(
                $budgets['parametrised'],
                static fn (string $row): bool => str_starts_with($row, $self . ':'),
            )),
        );

        $this->assertSame(
            [],
            $mine,
            'this file is inside its own census and is now contributing rows to it, so the '
            . 'liveness arms below are satisfied by this file\'s own text rather than by the '
            . 'tree. Assemble the occurrence from pieces instead of spelling it',
        );

        // AND THE KNOWN-POSITIVE IN THE SAME TEST (rule 15): an empty list here
        // is also what a dead scanner returns, so the scanner has to be shown
        // finding something somewhere.
        $this->assertNotSame([], $budgets['literal'], 'the census found no literal budget anywhere');
        $this->assertNotSame([], $budgets['parametrised'], 'the census found no parametrised budget anywhere');
    }

    /**
     * No child budget reaches the parent's own ceiling.
     */
    public function testEveryChildWallClockBudgetLeavesTheParentAlarmRoomToLose(): void
    {
        $limit = $this->defaultTimeLimit();
        $ceiling = $this->ceiling();

        $this->assertSame(
            [],
            $this->tooLooseIn($this->childBudgets()['literal'], $ceiling),
            sprintf(
                "this child's wall-clock budget leaves the parent's own alarm no room to lose. "
                . 'phpunit.xml declares defaultTimeLimit="%d" with enforceTimeLimit and '
                . 'failOnRisky, so a child bound at or near that number means PHPUnit aborts '
                . 'the TEST first: the run reads "aborted after %d seconds", names no child, '
                . 'and sheds every assertion about the exit status the wrapper exists to '
                . 'produce. Bring the child budget to %d or under, or argue for a larger '
                . 'defaultTimeLimit — not for a tighter margin here.',
                $limit,
                $limit,
                $ceiling,
            ),
        );
    }

    /**
     * The census is not vacuously empty, in either of its two forms.
     *
     * RULE 15. `assertSame([], $tooLoose)` above passes just as well when the
     * regex matches nothing at all — which is precisely what would happen if
     * someone reworded the launch helper. The literal population is the control;
     * the parametrised list is named separately so the form this scan CANNOT
     * evaluate is visible rather than quietly missing (rule 14).
     */
    public function testTheParametrisedFormIsSeenAndReported(): void
    {
        $budgets = $this->childBudgets();

        $this->assertGreaterThanOrEqual(
            8,
            \count($budgets['literal']),
            'the child-budget census found almost no literal budgets, so its verdict that none '
            . 'of them is too loose is worthless',
        );

        $this->assertNotSame(
            [],
            $budgets['parametrised'],
            'no `timeout -s ' . 'KILL %d` site is being reported. Either every file that passes '
            . 'its budget through sprintf() has been rewritten — in which case this arm should '
            . 'go — or the scan has stopped seeing that form, and it is exactly the form whose '
            . 'value this guard cannot check. NOTE the needle above is ASSEMBLED: spelling it '
            . 'here makes this file its own evidence, which is how this arm passed while the '
            . 'scan was blind to every other file '
            . '(see testThisFileIsNotItsOwnEvidence())',
        );

        // AND THE COMPARISON ITSELF, over an answer already known. A ceiling
        // computed from a limit this test reads must actually reject a number
        // above it; without this, a mis-signed comparison passes everything.
        $limit = $this->defaultTimeLimit();
        $this->assertGreaterThan(
            0,
            $limit - self::REQUIRED_HEADROOM_SECONDS,
            'the headroom is at or above the whole per-test limit, so the ceiling is not a '
            . 'number any child budget could satisfy',
        );
        $this->assertTrue(
            $limit > $limit - self::REQUIRED_HEADROOM_SECONDS,
            'the ceiling is not below the limit it is derived from',
        );
    }
}

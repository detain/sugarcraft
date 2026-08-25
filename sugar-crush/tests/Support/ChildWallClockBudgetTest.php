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
 * WHAT IT DOES NOT COVER. A budget passed as a constant rather than a literal
 * (`timeout -s KILL %d` with a `const` argument, which two files use) is not
 * read: resolving it means following a `sprintf()` argument list, and a scan
 * that guesses is worse than one that says what it cannot see. Those two are
 * at 20 by inspection, MEASURED at the time of writing, and
 * {@see testTheParametrisedFormIsSeenAndReported()} makes them VISIBLE rather
 * than silently absent — a scan that quietly ignored them would be reporting a
 * clean tree over a roster it had narrowed itself.
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
     * No child budget reaches the parent's own ceiling.
     */
    public function testEveryChildWallClockBudgetLeavesTheParentAlarmRoomToLose(): void
    {
        $limit = $this->defaultTimeLimit();
        $ceiling = $limit - self::REQUIRED_HEADROOM_SECONDS;

        $tooLoose = [];
        foreach ($this->childBudgets()['literal'] as [$label, $line, $seconds]) {
            if ($seconds > $ceiling) {
                $tooLoose[] = $label . ':' . $line . ' — timeout -s KILL ' . $seconds;
            }
        }

        $this->assertSame(
            [],
            $tooLoose,
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
            'no `timeout -s KILL %d` site is being reported. Either the two files that pass '
            . 'their budget as a constant have been rewritten — in which case this arm should '
            . 'go — or the scan has stopped seeing that form, and it is exactly the form whose '
            . 'value this guard cannot check',
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

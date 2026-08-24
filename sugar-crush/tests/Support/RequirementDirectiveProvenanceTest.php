<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use PHPUnit\Metadata\Metadata;
use PHPUnit\Metadata\Parser\Registry;

/**
 * A DOC-COMMENT THAT NAMES A PHPUNIT REQUIREMENT DIRECTIVE *IS* THAT DIRECTIVE,
 * AND THE SUITE HAS ALREADY BEEN BITTEN BY IT.
 *
 * WHERE THIS CAME FROM. A round removed a requirement directive from a test and
 * wrote a paragraph explaining why it was removed. The paragraph named the
 * directive. PHPUnit's metadata parser read the name out of the replacement
 * comment exactly as it had read the original, and the test skipped — inside
 * the change whose entire purpose was that it should never skip again. The run
 * reported one skipped test for a file that calls no skip-marking method
 * anywhere. Nothing in the tree could have caught that; the suite still exited
 * zero and the count that moved was a count nobody watches.
 *
 * WHAT THE GUARD ACTUALLY ASSERTS, and it is deliberately not a text scan.
 * Grepping for the sigil would be a different instrument from the one that
 * produced the defect, and would answer a different question — the question is
 * not "does this text look like a directive", it is "does PHPUnit READ one
 * here". So the walk below asks PHPUnit's own metadata parser, the same
 * component that decides whether a test is skipped, and reports any test it
 * answers for. A directive PHPUnit stops recognising stops being a hazard on
 * the same day, and one it starts recognising is covered without an edit here.
 *
 * WHY THE ANSWER IS NOT "ONLY THE ONES THAT WOULD SKIP TODAY". This host has
 * ONLY PHP 8.3.6 and CI runs 8.3 AND 8.4, so a requirement satisfied here can
 * skip there — a version constraint accidentally left in prose is invisible to
 * a guard that only reports what is unsatisfied locally. Every requirement is
 * reported, satisfied or not, and the roster below is what makes a deliberate
 * one legal.
 *
 * AND HOW ITS PROSE IS WRITTEN, which is the same trap one level down. No
 * paragraph in this file spells a directive in its executable form; where the
 * literal text is needed it is built by concatenation, or it lives in a fixture
 * that this suite does not collect. A guard that disabled itself by describing
 * what it guards would be the defect wearing the fix's clothes.
 *
 * WHAT THE POPULATION REACHES THAT IS NOT IN IT. The walk visits only the files
 * the suite collects, and within each it asks about every public method the
 * class DECLARES OR IMPORTS FROM A TRAIT — not every method it exposes, since
 * one INHERITED from a parent is filtered out by the declaring-class check in
 * {@see requirementsOf()}. A trait method survives that check because reflection
 * answers with the USING class for it, so a directive quoted in a shared trait's
 * doc-comment - which is where an explanatory paragraph is most likely to end
 * up, and whose own file this walk never opens - is reported against every test
 * class that uses it. Louder than necessary, and the right direction to err in.
 * An inherited one is the gap: nothing in this tree populates it today (no
 * collected class extends an in-tree base), and closing it means walking the
 * parents rather than trusting the filter.
 *
 * @see \SugarCraft\Crush\Tests\Support\Fixtures\AnnotationSkipProvenance\ProseQuotingADirectiveFixture
 * @see \SugarCraft\Crush\Tests\Support\Fixtures\AnnotationSkipProvenance\ProseWithoutTheSigilFixture
 */
final class RequirementDirectiveProvenanceTest extends TestCase
{
    /**
     * Tests that carry a requirement PHPUnit reads, each with the reason.
     *
     * NOT AN EXEMPTION LIST. Both directions are checked: a row whose test no
     * longer carries a requirement fails and must be deleted, and a test that
     * acquires one without a row fails. EMPTY IS THE CORRECT STATE TODAY —
     * every conditional skip in this suite is a runtime decision made by a
     * skip-marking call in the test body, where the condition is visible and
     * the reason is written by the author.
     *
     * @var array<string,string> `<relative file>::<method>` (or `::*` for a
     *                           class-level one) => why it is legitimate
     */
    private const DELIBERATE_REQUIREMENTS = [];

    /** The suffix `phpunit.xml`'s test suite collects. */
    private const COLLECTED_SUFFIX = 'Test.php';

    /**
     * THE WALL CLOCK FOR THE ONE REAL CHILD THIS FILE SPAWNS.
     *
     * The child below is a whole second `phpunit`. An unbounded one that hangs
     * stalls this test until PHPUnit's own per-test alarm aborts it, which
     * sheds the assertions and replaces a specific red with "aborted after N
     * seconds" — the failure mode this suite has spent a round arguing about,
     * reproduced here in the same round by a file that added a new spawn
     * without a bound. So it is bounded, and the bound is named.
     *
     * Measured on this host, PHP 8.3.6 / PHPUnit 10.5.64: the child run costs
     * 0.079s, so this is ~250x the thing it bounds. It stays well under
     * `phpunit.xml`'s per-test limit for the reason
     * {@see \SugarCraft\Crush\Tests\Cli\BootstrapSkillSkipsTest} sets out
     * and pins: a budget at or above that limit loses the race to PHPUnit's
     * alarm, and the alarm's verdict is the generic one.
     */
    private const CHILD_WALL_CLOCK_BUDGET_SECONDS = 20;

    /** What a shell reports when `timeout -s KILL` kills the child: 128 + SIGKILL. */
    private const KILLED_BY_THE_BUDGET = 137;

    /** The suffix the fixtures below carry, chosen so the suite does NOT collect them. */
    private const FIXTURE_SUFFIX = 'Fixture.php';

    private const FIXTURE_DIR = 'tests/Support/Fixtures/AnnotationSkipProvenance';

    private const SKIPPING_FIXTURE = self::FIXTURE_DIR . '/ProseQuotingADirectiveFixture.php';

    private const RUNNING_FIXTURE = self::FIXTURE_DIR . '/ProseWithoutTheSigilFixture.php';

    private const METHOD_SKIPPING_FIXTURE = self::FIXTURE_DIR . '/MethodProseQuotingADirectiveFixture.php';

    /** Every fixture this guard owns, so no arm can quietly cover a subset. */
    private const FIXTURES = [
        self::SKIPPING_FIXTURE,
        self::METHOD_SKIPPING_FIXTURE,
        self::RUNNING_FIXTURE,
    ];

    /**
     * NO TEST IN THIS TREE OWES ITS SKIPPABILITY TO A COMMENT.
     *
     * The population is every file the suite collects, resolved to a class
     * through the same PSR-4 rule Composer uses. A file this walk cannot
     * resolve is REPORTED, not skipped: an unresolvable file is one this guard
     * is not covering, which is indistinguishable from a clean one unless it
     * says so.
     */
    public function testNoCollectedTestCarriesARequirementDirective(): void
    {
        $predicates = self::requirementPredicates();

        // RULE 15: AN ASSERTION OF AN ABSENCE IS NOT EVIDENCE UNLESS SOMETHING
        // IN THE SAME TEST PROVES THE INSTRUMENT STILL WORKS — and this walk
        // has FOUR components, not one. WHAT THIS COMMENT SAID: "the derivation
        // below is the whole instrument". WHAT IS TRUE NOW: the derivation is
        // the first of four. The scanner is requirementsOf(), the population is
        // collectedTestFiles(), the split is partitionByResolvability(), and
        // each was separately mutable to nothing while this test stayed green —
        // measured, filtered to this method: the scanner stubbed out left it
        // GREEN at 1 test / 6 assertions, and the population sliced to a single
        // file left the whole guard file green and byte-identical. Every one of
        // the four is now controlled below, HERE, because the sibling tests
        // that also cover some of them are separately deletable units.
        self::assertNotSame([], $predicates, 'no requirement predicate could be derived from '
            . Metadata::class . ', so the walk below asks nothing of every file it visits and '
            . 'would report a tree full of directives as clean');
        self::assertContains('isRequiresPhp', $predicates, 'the version predicate — the one the '
            . 'observed defect used — is not among the derived ones, so the derivation is '
            . 'answering about something other than requirements');

        // COMPONENT 2: the scanner, through the same call the walk makes.
        self::assertSame(
            ['*' => 'isRequiresPhp'],
            self::requirementsOf(self::classFor(self::SKIPPING_FIXTURE), $predicates),
            'the scanner this walk uses reported nothing for the fixture whose class doc-comment '
            . 'carries a directive, so it is dead and every file it visits comes back clean',
        );

        // COMPONENT 3: the population.
        $files = self::collectedTestFiles();
        self::assertTheCollectedPopulationIsTheWholeTree($files);

        [$resolved, $unresolvable] = self::partitionByResolvability(
            array_keys($files),
            static fn (string $class): bool => class_exists($class),
        );

        // RULE: GO RED ON WHAT YOU CANNOT PARSE.
        self::assertSame([], $unresolvable, \sprintf(
            "%d collected test file(s) do not resolve to a loadable class under the PSR-4 rule "
            . "this walk uses, so nothing was asked about them. That is a hole in the walk, not "
            . "a clean file.\n  %s",
            \count($unresolvable),
            \implode("\n  ", $unresolvable),
        ));

        // COMPONENT 4: the split. With nothing unresolvable, the resolved half
        // has to be the WHOLE population — a split that dropped files silently
        // would leave both halves consistent and the census asking about less
        // than it claims.
        self::assertSame(array_keys($files), array_keys($resolved), 'the resolvability split '
            . 'handed on fewer files than it was given while reporting none of them as '
            . 'unresolvable, so the walk below asks about a subset of the tree');

        $found = [];
        foreach ($resolved as $relative => $class) {
            foreach (self::requirementsOf($class, $predicates) as $key => $what) {
                $found[$relative . '::' . $key] = $what;
            }
        }

        [$unrostered, $stale] = self::rosterVerdicts($found, self::DELIBERATE_REQUIREMENTS);

        self::assertSame([], $unrostered, self::unrosteredMessage($unrostered));

        self::assertSame([], $stale, \sprintf(
            "%d row(s) describe a requirement that is no longer there. Delete them — a row that "
            . "outlives the thing it permits is a standing permission nobody re-argued.\n  %s",
            \count($stale),
            \implode("\n  ", $stale),
        ));
    }

    /**
     * THE KNOWN-POSITIVE FOR THE WALK ABOVE, THROUGH THE SAME PARSER CALL.
     *
     * The walk asserts an absence over a population that currently produces
     * none, so on its own it is exactly as green with the parser deleted as
     * with it working. These two fixtures are the only inputs that tell those
     * two states apart. They differ in ONE thing — whether the paragraph
     * carries the sigil that makes a comment executable — and they must come
     * back with different answers.
     */
    public function testTheParserAnswersForAQuotedDirectiveAndNotForOneSpelledOut(): void
    {
        $predicates = self::requirementPredicates();

        self::assertSame(
            ['*' => 'isRequiresPhp'],
            self::requirementsOf(self::classFor(self::SKIPPING_FIXTURE), $predicates),
            'the CLASS-level walk reported nothing for a fixture whose class doc-comment carries '
            . 'a directive, so it is a dead instrument and every file it visits comes back clean',
        );

        // THE SECOND GRAMMATICAL SHAPE, AND IT IS A SEPARATE PARSER CALL. With
        // only the class-level row above, disabling the method walk entirely
        // was GREEN — measured. A control table whose every row wears one shape
        // cannot tell you about the shape it omits, and a directive on a METHOD
        // is the commoner of the two.
        self::assertSame(
            ['testTheBodyNeverRuns' => 'isRequiresPhp'],
            self::requirementsOf(self::classFor(self::METHOD_SKIPPING_FIXTURE), $predicates),
            'the METHOD-level walk did not report exactly the one method carrying a directive. '
            . 'Nothing means the method walk is dead; the neighbour method appearing too means '
            . 'it attributes a directive to methods that do not carry one',
        );

        self::assertSame(
            [],
            self::requirementsOf(self::classFor(self::RUNNING_FIXTURE), $predicates),
            'the parser reported a requirement for a fixture whose paragraph names one only in '
            . 'words, so the walk above reports every explanatory paragraph in the tree and the '
            . 'remedy it recommends does not work',
        );
    }

    /**
     * AND THE SAME PAIR THROUGH PHPUNIT'S OWN RESULT OUTPUT, in a child run.
     *
     * The parser is the mechanism; a SKIP is the consequence, and the two are
     * worth separating because only the consequence is what a reader ever sees.
     * This runs the two fixtures under a real `phpunit` and reads the JUnit log
     * it writes: the quoted-directive fixture must come back SKIPPED and its
     * sibling must not. Neither file contains a skip-marking call — asserted
     * below — so a skip here can only have come out of a comment.
     */
    public function testPhpunitItselfReportsTheQuotedDirectiveFixtureAsSkipped(): void
    {
        $log = sys_get_temp_dir() . '/sc_requirement_provenance_' . getmypid() . '_' . bin2hex(random_bytes(8)) . '.xml';
        $root = \dirname(__DIR__, 2);

        $status = 0;
        $output = [];
        exec(\sprintf(
            'timeout -s KILL %d %s %s --no-configuration --bootstrap %s --test-suffix %s '
            . '--log-junit %s %s 2>&1',
            self::CHILD_WALL_CLOCK_BUDGET_SECONDS,
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($root . '/vendor/bin/phpunit'),
            escapeshellarg($root . '/vendor/autoload.php'),
            escapeshellarg(self::FIXTURE_SUFFIX),
            escapeshellarg($log),
            escapeshellarg($root . '/' . self::FIXTURE_DIR),
        ), $output, $status);

        try {
            self::assertNotSame(self::KILLED_BY_THE_BUDGET, $status, \sprintf(
                "the child phpunit exited %d, which is what a shell reports for a process "
                . "killed by SIGKILL — here almost certainly its own %d-second budget, though "
                . "any other SIGKILL reports the same number. Nothing below this line is a "
                . "statement about what PHPUnit reads out of a comment.\n%s",
                self::KILLED_BY_THE_BUDGET,
                self::CHILD_WALL_CLOCK_BUDGET_SECONDS,
                implode("\n", $output),
            ));

            self::assertFileExists($log, "the child phpunit wrote no JUnit log, so this arm read "
                . "nothing at all:\n" . implode("\n", $output));

            $skipped = self::skippedTestsIn((string) file_get_contents($log));
        } finally {
            @unlink($log);
        }

        self::assertSame(
            [
                self::METHOD_SKIPPING_FIXTURE . '::testTheBodyNeverRuns' => true,
                self::SKIPPING_FIXTURE . '::testTheBodyNeverRuns' => true,
            ],
            $skipped,
            "PHPUnit did not report exactly the quoted-directive fixture as skipped. If it "
            . "reported NOTHING, a comment no longer becomes metadata and this whole guard has "
            . "lost its subject. If it reported the OTHER fixture too, spelling a directive out "
            . "in words is no longer a safe way to describe one, and every paragraph in this "
            . "tree that does so is now a skip.\n" . implode("\n", $output),
        );
    }

    /**
     * NEITHER FIXTURE MAY BE COLLECTED BY THIS SUITE.
     *
     * The skipping one would take the suite's skip count off its floor of one
     * — which is a number this project reads as evidence that the local
     * dependency closure is intact, so moving it costs more than a stray skip.
     */
    public function testNoFixtureIsCollectedByThisSuite(): void
    {
        foreach (self::FIXTURES as $fixture) {
            self::assertStringEndsWith(self::FIXTURE_SUFFIX, $fixture, $fixture . ' does not carry '
                . 'the fixture suffix');
            self::assertStringEndsNotWith(self::COLLECTED_SUFFIX, $fixture, $fixture . ' ends in '
                . 'the suffix this suite collects, so it runs as a real test — and the skipping '
                . 'one then moves the suite\'s skip count');
            self::assertFileExists(\dirname(__DIR__, 2) . '/' . $fixture);
        }
    }

    /**
     * THE SKIP IN THE ARM ABOVE MUST BE ATTRIBUTABLE TO THE COMMENT AND TO
     * NOTHING ELSE.
     *
     * If a fixture ever grew a skip-marking call, the child run would still
     * report a skip and this whole file would stay green while proving nothing
     * — the positive control would have acquired its own reason to be positive.
     * That is the shape where a fixture's expected value is also what a dead
     * instrument returns, and it is worth one assertion to close.
     */
    public function testNoFixtureContainsASkipMarkingCall(): void
    {
        // Built by concatenation so this file does not itself carry the token
        // a later census may sweep for.
        $marker = 'markTest' . 'Skipped';

        foreach (self::FIXTURES as $fixture) {
            $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/' . $fixture);

            self::assertStringNotContainsString($marker, $source, $fixture . ' calls a '
                . 'skip-marking method, so a skip reported for it says nothing about whether a '
                . 'comment can produce one');
            self::assertStringNotContainsString('markTest' . 'Incomplete', $source, $fixture
                . ' marks itself incomplete, which PHPUnit reports alongside skips');
        }
    }

    /**
     * What the roster says about a scan: the requirements that owe a row, and
     * the rows that have outlived the requirement they permit.
     *
     * EXTRACTED BECAUSE THE ARMS IT REPLACES WERE UNPINNED, and that is a
     * measurement rather than a suspicion. Emptying the found set immediately
     * before the roster diff left this file GREEN — 6 tests, 25 assertions, OK,
     * run filtered to this file, so the verdict is a claim about the guards in
     * it. The reason is the one E322 gives: the census asserts an ABSENCE over
     * a population that produces none, so an arm reporting nothing and an arm
     * that is right are the same green.
     *
     * @param  array<string,string> $found  key => predicate name
     * @param  array<string,string> $roster key => reason
     * @return array{list<string>, list<string>}
     */
    private static function rosterVerdicts(array $found, array $roster): array
    {
        $unrostered = [];
        foreach ($found as $key => $kind) {
            if (!isset($roster[$key])) {
                $unrostered[$key] = $kind;
            }
        }

        $stale = [];
        foreach ($roster as $key => $reason) {
            if (!\array_key_exists($key, $found)) {
                $stale[] = $key;
            }
        }

        return [$unrostered, $stale];
    }

    /**
     * KNOWN-ANSWER TABLE FOR {@see rosterVerdicts()}, in both polarities.
     *
     * The rows that must report nothing are as load-bearing as the rows that
     * must report: without the first an arm that reports everything passes,
     * without the second an arm that reports nothing passes. Keys are written
     * in BOTH shapes the walk produces — `::*` for a class-level requirement
     * and `::<method>` for a method-level one — because a classifier keyed on
     * the wrong half of that string would answer correctly for one and not the
     * other.
     *
     * @param array<string,string> $found
     * @param array<string,string> $roster
     * @param array<string,string> $unrostered
     * @param list<string>         $stale
     *
     * @dataProvider rosterCases
     */
    public function testTheRosterArmAnswersCasesWhoseAnswerIsKnown(
        string $why,
        array $found,
        array $roster,
        array $unrostered,
        array $stale,
    ): void {
        self::assertSame([$unrostered, $stale], self::rosterVerdicts($found, $roster), $why);
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string,string>, 2: array<string,string>, 3: array<string,string>, 4: list<string>}>
     */
    public static function rosterCases(): iterable
    {
        $why = 'a fixture reason, not a claim about the tree';

        yield 'a method-level requirement with no row is reported' => [
            'the arm stopped reporting the one thing this guard exists to report',
            ['a/ATest.php::testX' => 'isRequiresPhp'], [],
            ['a/ATest.php::testX' => 'isRequiresPhp'], [],
        ];
        yield 'a class-level requirement with no row is reported' => [
            'the class-level key shape is not reported, so a directive in a class doc-comment '
                . 'is invisible to the roster arm',
            ['a/ATest.php::*' => 'isRequiresPhp'], [],
            ['a/ATest.php::*' => 'isRequiresPhp'], [],
        ];
        yield 'a requirement WITH a row is spared' => [
            'the roster does nothing, so every deliberate requirement is reported as an offender',
            ['a/ATest.php::testX' => 'isRequiresPhp'], ['a/ATest.php::testX' => $why], [], [],
        ];
        yield 'a row keyed on the same FILE does not spare another method in it' => [
            'the roster lookup is keyed on the file rather than on the test, so one row spares '
                . 'every requirement in a file',
            ['a/ATest.php::testX' => 'isRequiresPhp'], ['a/ATest.php::testY' => $why],
            ['a/ATest.php::testX' => 'isRequiresPhp'], ['a/ATest.php::testY'],
        ];
        yield 'a class-level row does not spare a method-level requirement in the same file' => [
            'the two key shapes collapse into one, so a rostered class-level directive licenses '
                . 'every method in the file',
            ['a/ATest.php::testX' => 'isRequiresPhp'], ['a/ATest.php::*' => $why],
            ['a/ATest.php::testX' => 'isRequiresPhp'], ['a/ATest.php::*'],
        ];
        yield 'a row whose requirement is gone is stale' => [
            'a row that outlived its requirement was not reported, so the roster can never be '
                . 'cleaned and grows into a permanent exemption list',
            [], ['a/ATest.php::testX' => $why], [], ['a/ATest.php::testX'],
        ];
        yield 'an empty scan against an empty roster reports nothing' => [
            'the arm invented an offender out of nothing',
            [], [], [], [],
        ];
    }

    /**
     * The collected files split into the ones that resolve to a loadable class
     * and the ones that do not.
     *
     * EXTRACTED FOR THE SAME REASON AS {@see rosterVerdicts()}: deleting the
     * unresolvable append was GREEN, because every file in this tree resolves.
     * An arm that reports what a clean population never produces cannot be
     * pinned by that population — only by a table. `$exists` is a parameter so
     * the table can supply a population that does not resolve without putting
     * a broken file in the tree.
     *
     * @param  list<string>                 $relatives
     * @param  callable(string): bool       $exists
     * @return array{array<string,string>, list<string>}
     */
    private static function partitionByResolvability(array $relatives, callable $exists): array
    {
        $resolved = [];
        $unresolvable = [];

        foreach ($relatives as $relative) {
            $class = self::classFor($relative);

            if (!$exists($class)) {
                $unresolvable[] = $relative . ' (expected class ' . $class . ')';

                continue;
            }

            $resolved[$relative] = $class;
        }

        return [$resolved, $unresolvable];
    }

    /**
     * KNOWN-ANSWER TABLE FOR {@see partitionByResolvability()}.
     *
     * RULE: GO RED ON WHAT YOU CANNOT PARSE. A collected file that does not
     * resolve to a class is a file this guard asked nothing about, which is
     * indistinguishable from a clean one unless the walk says so — and saying
     * so is the branch a tree where everything resolves can never exercise.
     *
     * @dataProvider resolvabilityCases
     *
     * @param list<string>         $relatives
     * @param list<string>         $unresolvable
     * @param array<string,string> $resolved
     */
    public function testTheResolvabilitySplitAnswersCasesWhoseAnswerIsKnown(
        string $why,
        array $relatives,
        array $missing,
        array $resolved,
        array $unresolvable,
    ): void {
        [$actualResolved, $actualUnresolvable] = self::partitionByResolvability(
            $relatives,
            static fn (string $class): bool => !\in_array($class, $missing, true),
        );

        self::assertSame([$resolved, $unresolvable], [$actualResolved, $actualUnresolvable], $why);
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>, 2: list<string>, 3: array<string,string>, 4: list<string>}>
     */
    public static function resolvabilityCases(): iterable
    {
        yield 'a file that resolves is handed on with its class' => [
            'a resolvable file was dropped, so the walk asks nothing about most of the tree',
            ['tests/Support/ATest.php'], [],
            ['tests/Support/ATest.php' => 'SugarCraft\\Crush\\Tests\\Support\\ATest'], [],
        ];
        yield 'a file that does not resolve is REPORTED, not skipped' => [
            'an unresolvable file was silently dropped, which reads exactly like a clean one',
            ['tests/Support/GhostTest.php'], ['SugarCraft\\Crush\\Tests\\Support\\GhostTest'],
            [], ['tests/Support/GhostTest.php (expected class SugarCraft\\Crush\\Tests\\Support\\GhostTest)'],
        ];
        yield 'the two are separated within one population' => [
            'one unresolvable file took its resolvable neighbours down with it, or vice versa',
            ['tests/A/OneTest.php', 'tests/B/GhostTest.php', 'tests/C/TwoTest.php'],
            ['SugarCraft\\Crush\\Tests\\B\\GhostTest'],
            [
                'tests/A/OneTest.php' => 'SugarCraft\\Crush\\Tests\\A\\OneTest',
                'tests/C/TwoTest.php' => 'SugarCraft\\Crush\\Tests\\C\\TwoTest',
            ],
            ['tests/B/GhostTest.php (expected class SugarCraft\\Crush\\Tests\\B\\GhostTest)'],
        ];
        yield 'an empty population produces two empty halves' => [
            'the split invented a file out of nothing',
            [], [], [], [],
        ];
    }

    /**
     * The names of every `isRequires*` predicate PHPUnit's own metadata class
     * declares, DERIVED rather than listed.
     *
     * A list written here would cover the kinds that exist at the moment it was
     * typed and silently miss the next one — and the count itself is a fact
     * about a vendored dependency, so writing it down here would rot on a
     * PHPUnit bump for no gain. Reflecting the class means a requirement kind
     * PHPUnit adds is covered by this guard on the day the dependency is
     * bumped, with no edit and no round in between.
     *
     * @return list<string>
     */
    private static function requirementPredicates(): array
    {
        $names = [];

        foreach ((new \ReflectionClass(Metadata::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->name, 'isRequires') && $method->getNumberOfParameters() === 0) {
                $names[] = $method->name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Every requirement PHPUnit's parser reads for a class and its test
     * methods, keyed by `<method>` or by `*` for a class-level one.
     *
     * @param  list<string>         $predicates
     * @return array<string,string>
     */
    private static function requirementsOf(string $class, array $predicates): array
    {
        $reflected = new \ReflectionClass($class);
        $found = [];

        foreach (Registry::parser()->forClass($class) as $metadata) {
            foreach (self::kindsOf($metadata, $predicates) as $kind) {
                $found['*'] = $kind;
            }
        }

        foreach ($reflected->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            foreach (Registry::parser()->forMethod($class, $method->name) as $metadata) {
                foreach (self::kindsOf($metadata, $predicates) as $kind) {
                    $found[$method->name] = $kind;
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $predicates
     * @return list<string>
     */
    private static function kindsOf(Metadata $metadata, array $predicates): array
    {
        $kinds = [];

        foreach ($predicates as $predicate) {
            if ($metadata->{$predicate}()) {
                $kinds[] = $predicate;
            }
        }

        return $kinds;
    }

    /**
     * The tests PHPUnit reported skipped in a JUnit log, as sorted
     * `<relative file>::<method>` keys.
     *
     * THE KEY IS THE PAIR, NOT THE FILE. Keyed on the file alone, two skipped
     * tests in one fixture collapsed into one entry and the exact-match arm
     * that reads this stayed green — an instrument that cannot represent its
     * input has a hole shaped like the next defect, and silently dropping half
     * of it is the worst version of that.
     *
     * SORTED because the order is the child PHPUnit's file iteration order and
     * `assertSame()` on an associative array is order-sensitive. Alphabetical
     * today; a false red waiting on an ordering change otherwise.
     *
     * @return array<string,true>
     */
    private static function skippedTestsIn(string $junit): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($junit);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        self::assertInstanceOf(\SimpleXMLElement::class, $document, 'the child run\'s JUnit log '
            . 'did not parse as XML, so this arm has no result output to read');

        $skipped = [];
        $root = \dirname(__DIR__, 2) . '/';

        foreach ($document->xpath('//testcase[skipped]') ?: [] as $case) {
            $file = (string) $case['file'];
            $relative = str_starts_with($file, $root) ? substr($file, \strlen($root)) : $file;
            $skipped[$relative . '::' . (string) $case['name']] = true;
        }

        ksort($skipped);

        return $skipped;
    }

    /**
     * Every file the suite collects, `<relative to the package root>` =>
     * absolute.
     *
     * @return array<string,string>
     */
    private static function collectedTestFiles(): array
    {
        $root = \dirname(__DIR__, 2);
        $files = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/tests', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($walk as $entry) {
            /** @var \SplFileInfo $entry */
            if (!$entry->isFile() || !str_ends_with($entry->getFilename(), self::COLLECTED_SUFFIX)) {
                continue;
            }

            $files[substr($entry->getPathname(), \strlen($root) + 1)] = $entry->getPathname();
        }

        ksort($files);

        return $files;
    }

    /**
     * THE POPULATION IS THE WHOLE TREE, AND "NOT EMPTY" DOES NOT SAY THAT.
     *
     * WHAT THE ARM THIS REPLACES SAID: `assertNotSame([], collectedTestFiles())`
     * — at least one file was found. WHAT IS TRUE NOW: a census that visits ONE
     * file out of several hundred satisfies that and then reports, with total
     * confidence, that no test in the tree carries a directive. Measured:
     * slicing {@see collectedTestFiles()} to its first entry left the whole
     * guard file GREEN at 17 tests / 42 assertions, byte-identical to the
     * unmutated run, and green at `tests/Support` scope too. That is rule 15
     * one level out — the assertion of an absence survived an instrument that
     * was 99.7% dead.
     *
     * WHY THIS EARNS ITS PLACE. The map is cross-checked against a SECOND
     * enumeration written from a different primitive — `scandir()` and an
     * explicit stack rather than `RecursiveIteratorIterator` — so a walk that
     * loses files disagrees with one that does not. Two DEAD walks would agree
     * on the empty set, which is what the anchor is for: this file has to be in
     * the map, and it is derived from `__FILE__` so a rename follows it.
     *
     * NO CARDINALITY IS WRITTEN DOWN. A count taken in one lane's worktree is
     * wrong at master the moment a sibling merges a test file; the two walks
     * and the anchor are all derived at run time.
     *
     * @param array<string,string> $files
     */
    private static function assertTheCollectedPopulationIsTheWholeTree(array $files): void
    {
        $root = \dirname(__DIR__, 2);
        $self = substr(__FILE__, \strlen($root) + 1);

        self::assertArrayHasKey($self, $files, 'the population does not contain the file this '
            . 'assertion is written in, so it is not a walk of the tree — and an empty or '
            . 'truncated population reports every test in the tree as clean');

        self::assertSame(
            self::collectedTestFilesByASecondRoute(),
            array_keys($files),
            'the two independent enumerations of the collected tests disagree. Whichever is '
            . 'short, the census that uses it asks about less than the tree it claims to cover, '
            . 'and reports the files it never opened as carrying nothing',
        );

        $directories = [];
        foreach (array_keys($files) as $relative) {
            $directories[\dirname($relative)] = true;
        }

        self::assertGreaterThan(1, \count($directories), 'every collected file the walk found '
            . 'lives in one directory, so the recursion is not recursing');
    }

    /**
     * The same population as {@see collectedTestFiles()}, enumerated from a
     * different primitive so the two can disagree.
     *
     * Deliberately NOT a refactor of the other walk: a control that shares the
     * mechanism it controls fails in the same direction at the same moment.
     * There are no symlinks under `tests/` (checked when this was written), so
     * the recursion difference between an explicit stack and
     * `RecursiveIteratorIterator`'s default of not following them cannot make
     * the two disagree for a reason that is not a defect.
     *
     * @return list<string>
     */
    private static function collectedTestFilesByASecondRoute(): array
    {
        $root = \dirname(__DIR__, 2);
        $found = [];
        $stack = [$root . '/tests'];

        while ($stack !== []) {
            $directory = array_pop($stack);

            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $directory . '/' . $entry;

                if (is_link($path)) {
                    continue;
                }

                if (is_dir($path)) {
                    $stack[] = $path;

                    continue;
                }

                if (str_ends_with($entry, self::COLLECTED_SUFFIX)) {
                    $found[] = substr($path, \strlen($root) + 1);
                }
            }
        }

        sort($found);

        return $found;
    }

    /** `tests/Support/X.php` => the PSR-4 class Composer maps it to. */
    private static function classFor(string $relative): string
    {
        return 'SugarCraft\\Crush\\Tests\\'
            . str_replace('/', '\\', substr($relative, \strlen('tests/'), -\strlen('.php')));
    }

    /**
     * The failure text, EXTRACTED SO SOMETHING CAN RUN IT WITH A NON-EMPTY
     * LIST — a green suite only ever evaluates it over the empty one.
     *
     * @param array<string,string> $unrostered
     */
    private static function unrosteredMessage(array $unrostered): string
    {
        return \sprintf(
            "%d test(s) carry a requirement PHPUnit reads out of a comment or an attribute. If "
            . "that was deliberate, add a row to DELIBERATE_REQUIREMENTS with the reason. If it "
            . "was not — and the usual way it is not is a paragraph EXPLAINING a requirement, "
            . "which becomes one the moment it is spelled in the executable form — rewrite the "
            . "sentence to name the directive in words instead. A test skipped this way calls "
            . "no skip-marking method, so nothing in the file records that it can skip at all.\n  %s",
            \count($unrostered),
            \implode("\n  ", array_map(
                static fn (string $key, string $kind): string => $key . ' (' . $kind . ')',
                array_keys($unrostered),
                $unrostered,
            )),
        );
    }

    /**
     * THE FAILURE TEXT OVER A REAL LIST, which a green run never does for it.
     */
    public function testTheFailureTextNamesEveryOffenderItWasHandedAndCountsThem(): void
    {
        $message = self::unrosteredMessage([
            'tests/A/OneTest.php::testA' => 'isRequiresPhp',
            'tests/B/TwoTest.php::*' => 'isRequiresPhpExtension',
        ]);

        self::assertStringContainsString('2 test(s)', $message, 'the count is not the population\'s');
        self::assertStringContainsString('tests/A/OneTest.php::testA (isRequiresPhp)', $message,
            'the first offender is not named with its kind');
        self::assertStringContainsString('tests/B/TwoTest.php::* (isRequiresPhpExtension)', $message,
            'the second offender is not named with its kind');
        self::assertStringContainsString('DELIBERATE_REQUIREMENTS', $message,
            'the reader is not told where a deliberate one goes');
    }
}

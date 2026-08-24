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
 * the suite collects, but it asks about every public method those classes
 * expose, and a method imported from a TRAIT reports the USING class as its
 * declaring class. So a directive quoted in a shared trait's doc-comment -
 * which is where an explanatory paragraph is most likely to end up, and whose
 * own file this walk never opens - is reported against every test class that
 * uses it. Louder than necessary, and the right direction to err in.
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

    /** The suffix the fixtures below carry, chosen so the suite does NOT collect them. */
    private const FIXTURE_SUFFIX = 'Fixture.php';

    private const FIXTURE_DIR = 'tests/Support/Fixtures/AnnotationSkipProvenance';

    private const SKIPPING_FIXTURE = self::FIXTURE_DIR . '/ProseQuotingADirectiveFixture.php';

    private const RUNNING_FIXTURE = self::FIXTURE_DIR . '/ProseWithoutTheSigilFixture.php';

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

        // RULE 15: an assertion of an absence is not evidence unless something
        // proves the instrument still works. The derivation below is the whole
        // instrument, and a derivation that returns nothing reports every file
        // as clean.
        self::assertNotSame([], $predicates, 'no requirement predicate could be derived from '
            . Metadata::class . ', so the walk below asks nothing of every file it visits and '
            . 'would report a tree full of directives as clean');
        self::assertContains('isRequiresPhp', $predicates, 'the version predicate — the one the '
            . 'observed defect used — is not among the derived ones, so the derivation is '
            . 'answering about something other than requirements');

        $found = [];
        $unresolvable = [];

        foreach (self::collectedTestFiles() as $relative => $absolute) {
            $class = self::classFor($relative);

            if (!class_exists($class)) {
                $unresolvable[] = $relative . ' (expected class ' . $class . ')';

                continue;
            }

            foreach (self::requirementsOf($class, $predicates) as $key => $what) {
                $found[$relative . '::' . $key] = $what;
            }
        }

        // RULE: GO RED ON WHAT YOU CANNOT PARSE.
        self::assertSame([], $unresolvable, \sprintf(
            "%d collected test file(s) do not resolve to a loadable class under the PSR-4 rule "
            . "this walk uses, so nothing was asked about them. That is a hole in the walk, not "
            . "a clean file.\n  %s",
            \count($unresolvable),
            \implode("\n  ", $unresolvable),
        ));

        self::assertNotSame([], self::collectedTestFiles(), 'the walk found no test file at all, '
            . 'so this census is a statement about a directory listing that is not running');

        $unrostered = array_diff_key($found, self::DELIBERATE_REQUIREMENTS);
        self::assertSame([], $unrostered, self::unrosteredMessage($unrostered));

        $stale = array_diff_key(self::DELIBERATE_REQUIREMENTS, $found);
        self::assertSame([], $stale, \sprintf(
            "%d row(s) describe a requirement that is no longer there. Delete them — a row that "
            . "outlives the thing it permits is a standing permission nobody re-argued.\n  %s",
            \count($stale),
            \implode("\n  ", array_keys($stale)),
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

        $skipping = self::requirementsOf(self::classFor(self::SKIPPING_FIXTURE), $predicates);
        self::assertNotSame([], $skipping, 'the parser reported NO requirement for a fixture '
            . 'whose doc-comment carries one, so the walk above is looking through a dead '
            . 'instrument and every file it visits comes back clean');

        $running = self::requirementsOf(self::classFor(self::RUNNING_FIXTURE), $predicates);
        self::assertSame([], $running, 'the parser reported a requirement for a fixture whose '
            . 'paragraph names one only in words, so the walk above reports every explanatory '
            . 'paragraph in the tree and the remedy it recommends does not work');
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
            '%s %s --no-configuration --bootstrap %s --test-suffix %s --log-junit %s %s 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($root . '/vendor/bin/phpunit'),
            escapeshellarg($root . '/vendor/autoload.php'),
            escapeshellarg(self::FIXTURE_SUFFIX),
            escapeshellarg($log),
            escapeshellarg($root . '/' . self::FIXTURE_DIR),
        ), $output, $status);

        try {
            self::assertFileExists($log, "the child phpunit wrote no JUnit log, so this arm read "
                . "nothing at all:\n" . implode("\n", $output));

            $skipped = self::skippedTestsIn((string) file_get_contents($log));
        } finally {
            @unlink($log);
        }

        self::assertSame(
            [self::SKIPPING_FIXTURE => 'testTheBodyNeverRuns'],
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
    public function testNeitherFixtureIsCollectedByThisSuite(): void
    {
        foreach ([self::SKIPPING_FIXTURE, self::RUNNING_FIXTURE] as $fixture) {
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
    public function testNeitherFixtureContainsASkipMarkingCall(): void
    {
        // Built by concatenation so this file does not itself carry the token
        // a later census may sweep for.
        $marker = 'markTest' . 'Skipped';

        foreach ([self::SKIPPING_FIXTURE, self::RUNNING_FIXTURE] as $fixture) {
            $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/' . $fixture);

            self::assertStringNotContainsString($marker, $source, $fixture . ' calls a '
                . 'skip-marking method, so a skip reported for it says nothing about whether a '
                . 'comment can produce one');
            self::assertStringNotContainsString('markTest' . 'Incomplete', $source, $fixture
                . ' marks itself incomplete, which PHPUnit reports alongside skips');
        }
    }

    /**
     * The names of every `isRequires*` predicate PHPUnit's own metadata class
     * declares, DERIVED rather than listed.
     *
     * A list written here would cover the eight kinds that exist today and
     * silently miss the ninth. Reflecting the class means a requirement kind
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
     * The tests PHPUnit reported skipped in a JUnit log, keyed by the file it
     * attributes each one to.
     *
     * @return array<string,string>
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
            $skipped[str_starts_with($file, $root) ? substr($file, \strlen($root)) : $file] = (string) $case['name'];
        }

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

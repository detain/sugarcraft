<?php

/**
 * Regenerate tests/Config/Support/suite-figure.json from a REAL full-run JUnit
 * log (E669 maintained-artifact convention).
 *
 *   cd sugar-crush
 *   php vendor/bin/phpunit -c phpunit.xml --log-junit /tmp/junit.xml
 *   php tests/Config/Support/refresh-suite-figure.php /tmp/junit.xml
 *   # then update the README.md headline from the printed figures
 *
 * The root <testsuite> element carries the totals PHPUnit itself reported;
 * nothing here estimates or re-counts — a figure without a generator is the
 * defect this closes.
 *
 * THE WHOLE-SUITE GATE — three checks on the log, each refusing loud before
 * anything is written. WHAT THIS FILE SAID, AND THE SENTENCE WAS THE DEFECT:
 * "Refuses a log that is not the whole suite", next to a check for exactly one
 * top-level <testsuite> — but a filtered run writes exactly one top-level
 * <testsuite> too, so the shape check refused nothing a filtered run would
 * trip. WHAT THE GATE IS NOW, derived from what JUnit actually carries:
 * - the leaf <testcase> census must add up to the reported totals: a log that
 *   contradicts itself (truncated, hand-edited) is not evidence;
 * - the reported total must reach REFRESH_FLOOR: shape cannot tell a filtered
 *   run from a full one, but size can;
 * - the run must be green: a red log's figures describe a broken suite, and
 *   the README headlines a passing one.
 * WHAT THE GATE STILL DOES NOT CLAIM: a filter wide enough to clear the floor
 * passes here. The backstop for that is downstream — ReadmeSuiteFigureDriftTest
 * cross-checks the artifact's tests figure against the live `--list-tests`
 * enumeration on every run of anything, so a lying artifact reddens the suite
 * that consumed it.
 */

declare(strict_types=1);

/**
 * Mirrors {@see \SugarCraft\Crush\Tests\Config\ReadmeSuiteFigureDriftTest::LIVE_ENUMERATION_FLOOR}
 * — the same sanity floor the live pin applies at read time, so a log too
 * small to be the suite is refused at write time instead of after the
 * headline has already been copied out of it.
 */
const REFRESH_FLOOR = 10_000;

if ($argc !== 2) {
    fwrite(STDERR, "usage: php tests/Config/Support/refresh-suite-figure.php <junit.xml>\n");
    exit(2);
}

$log = $argv[1];

if (!is_file($log)) {
    fwrite(STDERR, "no such JUnit log: {$log}\n");
    exit(2);
}

$root = simplexml_load_file($log);

if ($root === false || $root->getName() !== 'testsuites') {
    fwrite(STDERR, "expected a <testsuites> JUnit log root, got "
        . ($root === false ? 'an unparseable file' : '<' . $root->getName() . '>') . "\n");
    exit(2);
}

$suites = $root->testsuite;

if (count($suites) !== 1) {
    fwrite(STDERR, 'expected exactly one top-level <testsuite> (PHPUnit writes one); found '
        . count($suites) . " — this is not a plain PHPUnit JUnit log\n");
    exit(2);
}

$t = $suites[0];
$reportedTests = (int) $t['tests'];
$leafTests = count($root->xpath('//testcase'));

if ($leafTests !== $reportedTests) {
    fwrite(STDERR, "the log contradicts itself: its totals report {$reportedTests} tests but it carries {$leafTests} <testcase> elements — truncated or hand-edited, refusing to write\n");
    exit(2);
}

if ($reportedTests < REFRESH_FLOOR) {
    fwrite(STDERR, "the log reports only {$reportedTests} tests, below the suite floor of " . REFRESH_FLOOR . " — this reads as a filtered run, and its number would pin a lie\n");
    exit(2);
}

$reportedFailures = (int) $t['failures'] + (int) $t['errors'];

if ($reportedFailures > 0) {
    fwrite(STDERR, "the log is not green: {$reportedFailures} failures/errors — refresh the headline from a passing full run\n");
    exit(2);
}

$figures = [
    'tests' => $reportedTests,
    'assertions' => (int) $t['assertions'],
    'failures' => $reportedFailures,
    'skipped' => (int) $t['skipped'],
];

$payload = $figures + [
    'measured_at' => (new DateTimeImmutable('now'))->format('Y-m-d'),
    'php' => PHP_VERSION,
    'command' => 'php vendor/bin/phpunit -c phpunit.xml --log-junit <file> (cwd=sugar-crush, linked siblings, monorepo sandbox root)',
    'regenerate' => 'php tests/Config/Support/refresh-suite-figure.php <junit.xml> (cwd=sugar-crush) — then update the README headline from the same run',
];

$path = __DIR__ . '/suite-figure.json';
file_put_contents(
    $path,
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
);

printf(
    "wrote %s\n%d tests / %d assertions, %d failures, %d skipped — update the README headline from this run\n",
    $path,
    $figures['tests'],
    $figures['assertions'],
    $figures['failures'],
    $figures['skipped'],
);

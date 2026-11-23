<?php

/**
 * Regenerate tests/Config/Support/suite-figure.json from a REAL full-run JUnit
 * log (E669 maintained-artifact convention).
 *
 *   bash scripts/paratest.sh --out /tmp/pt-refresh    # from the monorepo root
 *   cd candy-code
 *   php tests/Config/Support/refresh-suite-figure.php /tmp/pt-refresh/junit.xml
 *   # then update the README.md headline from the printed figures
 *
 * An optional second argument writes the figure to that path instead of the
 * committed artifact, so the regenerator itself can be exercised on a fixture.
 *
 * ParaTest merges its workers' logs into one <dir>/junit.xml with the same
 * single top-level <testsuite> a serial `--log-junit` run writes, so either
 * log passes the gate below; the ParaTest run is the one CI's test cell uses.
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
 *
 * CI'S SHAPE, NOT THE HOST'S. The figure pins what CI counts. A test that
 * self-skips on the measuring host but runs on CI would otherwise land in the
 * artifact as a skip and without its assertions, and the README headline (which
 * quotes CI's counts) would drift from it. {@see CI_SHAPE_HOST_SKIPS} lists
 * each such host-gated test with the assertions it makes when it runs; every
 * one of them the log reports as skipped is folded back (skipped - 1,
 * assertions + n) and named in a `host_adjustment` key. The adjustment is read
 * from the log, not probed on the host, so the same log always writes the same
 * figure. Before this, the fold was a hand-restore after every regeneration.
 */

declare(strict_types=1);

/**
 * Mirrors {@see \SugarCraft\Code\Tests\Config\ReadmeSuiteFigureDriftTest::LIVE_ENUMERATION_FLOOR}
 * — the same sanity floor the live pin applies at read time, so a log too
 * small to be the suite is refused at write time instead of after the
 * headline has already been copied out of it.
 */
const REFRESH_FLOOR = 10_000;

/**
 * Host-gated tests CI runs, keyed `<class>::<method>`, valued with the
 * assertions each makes when it runs. `SuiteRootRunRefusalTest`'s two TMPDIR=/
 * child arms skip wherever `SuiteSkipRoster::rootTmpdirChildStarts()` is false
 * (swoole 6.2.0 aborts php started with TMPDIR=/) and run on CI. The counts are
 * pinned against the method bodies by
 * ReadmeSuiteFigureDriftTest::testRefreshScriptPinsCiShapeForHostGatedSkips.
 */
const CI_SHAPE_HOST_SKIPS = [
    'SugarCraft\\Code\\Tests\\SuiteRootRunRefusalTest::testAChildBootstrapWithTmpdirRootRefusesTheLaunch' => 4,
    'SugarCraft\\Code\\Tests\\SuiteRootRunRefusalTest::testAChildBootstrapWithTheHatchStillRefusesTheTempRoot' => 3,
];

if ($argc !== 2 && $argc !== 3) {
    fwrite(STDERR, "usage: php tests/Config/Support/refresh-suite-figure.php <junit.xml> [<output.json>]\n");
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

$hostFigures = $figures;
$folded = [];

foreach ($root->xpath('//testcase[skipped]') as $case) {
    $key = (string) $case['class'] . '::' . (string) $case['name'];

    if (isset(CI_SHAPE_HOST_SKIPS[$key])) {
        $folded[] = CI_SHAPE_HOST_SKIPS[$key];
        $figures['skipped']--;
        $figures['assertions'] += CI_SHAPE_HOST_SKIPS[$key];
    }
}

$payload = $figures + [
    'measured_at' => (new DateTimeImmutable('now'))->format('Y-m-d'),
    'php' => PHP_VERSION,
    'command' => 'bash scripts/paratest.sh --out <dir> (cwd=monorepo root, junit at <dir>/junit.xml, linked siblings, monorepo sandbox root)',
    'regenerate' => 'php tests/Config/Support/refresh-suite-figure.php <junit.xml> (cwd=candy-code) — then update the README headline from the same run',
];

if ($folded !== []) {
    $swoole = extension_loaded('swoole') ? ' with swoole ' . phpversion('swoole') . ' loaded' : '';
    $payload['host_adjustment'] = sprintf(
        "measured on a host%s, where SuiteSkipRoster::rootTmpdirChildStarts() is false and SuiteRootRunRefusalTest's %s TMPDIR=/ child arm%s skip (%s skipped, %s assertions); pinned in CI's shape, where they run: skipped %d - %d, assertions + %d (they assert %s)",
        $swoole,
        count($folded) === 2 ? 'two' : (string) count($folded),
        count($folded) === 1 ? '' : 's',
        number_format($hostFigures['skipped']),
        number_format($hostFigures['assertions']),
        $hostFigures['skipped'],
        count($folded),
        array_sum($folded),
        implode(' + ', $folded),
    );
}

$path = $argv[2] ?? __DIR__ . '/suite-figure.json';
file_put_contents(
    $path,
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
);

if ($folded !== []) {
    printf(
        "folded %d host-gated skip(s) into CI's shape: the log itself reports %d assertions, %d skipped\n",
        count($folded),
        $hostFigures['assertions'],
        $hostFigures['skipped'],
    );
}

printf(
    "wrote %s\n%d tests / %d assertions, %d failures, %d skipped — update the README headline from this run\n",
    $path,
    $figures['tests'],
    $figures['assertions'],
    $figures['failures'],
    $figures['skipped'],
);

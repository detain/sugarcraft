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
 * defect this closes. Refuses a log that is not the whole suite: the suite
 * number of a filtered run would pin a lie.
 */

declare(strict_types=1);

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
    fwrite(STDERR, 'expected exactly one <testsuite> (a full run writes one); found '
        . count($suites) . " — this looks like a filtered run and cannot pin the headline\n");
    exit(2);
}

$t = $suites[0];
$figures = [
    'tests' => (int) $t['tests'],
    'assertions' => (int) $t['assertions'],
    'failures' => (int) $t['failures'] + (int) $t['errors'],
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

<?php

declare(strict_types=1);

/**
 * Conservation check for a ParaTest run: the totals in its junit log against
 * a pinned suite figure.
 *
 * usage: php scripts/paratest-conservation.php <junit.xml> <suite-figure.json>
 * exit:  0 PASS, 1 FAIL (a total differs), 2 input it cannot read
 *
 * The rule is the one scripts/parallel-tests.sh --against-json applies to its
 * shard logs: tests, assertions, failures and skipped must equal the figure,
 * and errors must be 0. Skipped is counted as <testcase><skipped/> elements,
 * as that script counts it.
 *
 * WHAT IT CATCHES UNDER PARATEST. Every test file runs (a file missing from
 * the durations table is queued first, as if slow), so this no longer guards
 * the table the way the shard runner's check did. It catches a run that lost
 * tests (a worker that died, a file the generated config left out) and a suite
 * whose shape changed without the figure being refreshed. The figure itself is
 * re-derived live by ReadmeSuiteFigureDriftTest, so it cannot quietly age.
 *
 * A missing or truncated junit is exit 2, never a pass: a run killed by its
 * timeout leaves no log, and a stale one is removed by paratest.sh before the
 * run starts.
 */

if ($argc !== 3) {
    \fwrite(\STDERR, "usage: php scripts/paratest-conservation.php <junit.xml> <suite-figure.json>\n");
    exit(2);
}
[, $junitPath, $figurePath] = $argv;

$fail = static function (string $message): never {
    \fwrite(\STDERR, "paratest-conservation: {$message}\n");
    exit(2);
};

$figureText = \is_file($figurePath) ? \file_get_contents($figurePath) : false;
if ($figureText === false) {
    $fail("no suite figure at {$figurePath}");
}
try {
    $figure = \json_decode($figureText, true, 512, \JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    $fail("unreadable suite figure {$figurePath}: {$e->getMessage()}");
}
foreach (['tests', 'assertions', 'failures', 'skipped'] as $key) {
    if (!\is_array($figure) || !isset($figure[$key]) || !\is_int($figure[$key])) {
        $fail("suite figure {$figurePath} has no integer '{$key}'");
    }
}

if (!\is_file($junitPath) || \filesize($junitPath) === 0) {
    $fail("no junit log at {$junitPath} (the run did not finish, or never started)");
}
\libxml_use_internal_errors(true);
$doc = new \DOMDocument();
if (!$doc->load($junitPath)) {
    $first = \libxml_get_errors()[0] ?? null;
    $fail("unparseable junit log {$junitPath}" . ($first !== null ? ': ' . \trim($first->message) : ''));
}
$root = (new \DOMXPath($doc))->query('/testsuites/testsuite')->item(0);
if (!$root instanceof \DOMElement) {
    $fail("junit log {$junitPath} has no top-level <testsuite>");
}

$run = [
    'tests' => (int) $root->getAttribute('tests'),
    'assertions' => (int) $root->getAttribute('assertions'),
    'errors' => (int) $root->getAttribute('errors'),
    'failures' => (int) $root->getAttribute('failures'),
    'skipped' => (new \DOMXPath($doc))->query('//testcase/skipped')->length,
];

\printf(
    "junit:  tests=%d assertions=%d errors=%d failures=%d skipped=%d time=%.1fs\n",
    $run['tests'],
    $run['assertions'],
    $run['errors'],
    $run['failures'],
    $run['skipped'],
    (float) $root->getAttribute('time'),
);
\printf(
    "figure: tests=%d assertions=%d errors=0 failures=%d skipped=%d  (%s)\n",
    $figure['tests'],
    $figure['assertions'],
    $figure['failures'],
    $figure['skipped'],
    $figurePath,
);
\printf("delta:  tests=%+d assertions=%+d\n", $run['tests'] - $figure['tests'], $run['assertions'] - $figure['assertions']);

$ok = $run['tests'] === $figure['tests']
    && $run['assertions'] === $figure['assertions']
    && $run['failures'] === $figure['failures']
    && $run['skipped'] === $figure['skipped']
    && $run['errors'] === 0;
\printf("CONSERVATION: %s\n", $ok ? 'PASS' : 'FAIL');
exit($ok ? 0 : 1);

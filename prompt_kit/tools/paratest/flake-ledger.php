<?php

declare(strict_types=1);

/*
 * Cross-run flake ledger. Every run's junit is appended as one row per non-passing
 * test plus one run row (so passes are implied by presence in a conserved run).
 *
 *   php flake-ledger.php add <ledger-dir> <junit.xml> <run-id> [key=value ...]   e.g. k=32 seed=7 order=random batch=0
 *   php flake-ledger.php report <ledger-dir>
 *
 * report: per test, runs failed / runs seen, the run metadata it failed under, and a
 * class: ALWAYS (red in every run = tree red), FLAKY (red in some), and for flaky ones
 * which axis moves it (the k/seed/batch/order values of failing vs passing runs).
 */

[$self, $cmd, $dir] = array_pad($argv, 3, null);
if (!in_array($cmd, ['add', 'report'], true) || $dir === null) {
    fwrite(STDERR, "usage: php $self add <ledger-dir> <junit.xml> <run-id> [k=v ...] | report <ledger-dir>\n");
    exit(2);
}
@mkdir($dir, 0777, true);
$runsFile = "$dir/runs.tsv";
$failsFile = "$dir/fails.tsv";

if ($cmd === 'add') {
    [, , , $junit, $runId] = array_pad($argv, 5, null);
    $meta = [];
    foreach (array_slice($argv, 5) as $kv) {
        [$k, $v] = explode('=', $kv, 2) + [1 => ''];
        $meta[$k] = $v;
    }
    $x = @simplexml_load_file((string) $junit);
    if ($x === false) {
        // a crashed run (WorkerCrashed, OOM) writes no junit: record the run as crashed, not as all-green
        file_put_contents($runsFile, implode("\t", [$runId, date('c'), 'CRASHED', 0, http_build_query($meta)]) . "\n", FILE_APPEND);
        fwrite(STDERR, "no junit: run $runId recorded as CRASHED\n");
        exit(0);
    }
    $tests = 0;
    $rows = '';
    foreach ($x->xpath('//testcase') as $tc) {
        $tests++;
        $kind = isset($tc->error) ? 'error' : (isset($tc->failure) ? 'failure' : null);
        if ($kind === null) {
            continue;
        }
        $node = $kind === 'error' ? $tc->error : $tc->failure;
        $msg = substr(preg_replace('/\s+/', ' ', trim((string) ($node['message'] ?? $node))), 0, 160);
        $rows .= implode("\t", [$runId, $tc['class'] . '::' . $tc['name'], $kind, (float) $tc['time'], $msg]) . "\n";
    }
    file_put_contents($failsFile, $rows, FILE_APPEND);
    file_put_contents($runsFile, implode("\t", [$runId, date('c'), 'OK', $tests, http_build_query($meta)]) . "\n", FILE_APPEND);
    fprintf(STDERR, "run %s: %d tests, %d non-passing recorded\n", $runId, $tests, substr_count($rows, "\n"));
    exit(0);
}

$runs = [];
foreach (@file($runsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    [$id, , $state, $n, $q] = explode("\t", $l) + [4 => ''];
    parse_str($q, $m);
    $runs[$id] = ['state' => $state, 'tests' => (int) $n, 'meta' => $m];
}
$ok = array_filter($runs, static fn ($r) => $r['state'] === 'OK');
$by = [];
foreach (@file($failsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    [$id, $test, $kind, $t, $msg] = explode("\t", $l) + [4 => ''];
    $by[$test][$id] = [$kind, (float) $t, $msg];
}
printf("%d runs (%d completed, %d crashed/no junit)\n", count($runs), count($ok), count($runs) - count($ok));
foreach ($runs as $id => $r) {
    printf("  %-14s %-8s %6d tests  %s\n", $id, $r['state'], $r['tests'], http_build_query($r['meta'], '', ' '));
}
uksort($by, static fn ($a, $b) => count($by[$b]) <=> count($by[$a]) ?: strcmp($a, $b));
echo "\nclass   fail/runs  test  | failed under -> passed under\n";
foreach ($by as $test => $failedIn) {
    $n = count($failedIn);
    $class = $n >= count($ok) ? 'ALWAYS' : 'FLAKY ';
    $axes = '';
    if ($class === 'FLAKY ') {
        $failMeta = $passMeta = [];
        foreach ($ok as $id => $r) {
            foreach ($r['meta'] as $k => $v) {
                isset($failedIn[$id]) ? $failMeta[$k][$v] = true : $passMeta[$k][$v] = true;
            }
        }
        foreach ($failMeta as $k => $vals) {
            $axes .= " $k=" . implode(',', array_keys($vals)) . '->' . implode(',', array_keys($passMeta[$k] ?? []));
        }
    }
    $first = reset($failedIn);
    printf("%s  %2d/%-2d   %s |%s\n        %s: %s\n", $class, $n, count($ok), $test, $axes, $first[0], $first[2]);
}

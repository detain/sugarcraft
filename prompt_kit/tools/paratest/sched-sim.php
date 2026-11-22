<?php

declare(strict_types=1);

// usage: php sched-sim.php <junit.xml> <K>
// Per-file durations from a paratest/phpunit junit; simulate paratest's greedy
// one-file-at-a-time dispatch in alphabetical order vs LPT (longest first).
[$_, $junit, $K] = $argv + [null, null, 32];
$K = (int) $K;
$x = simplexml_load_file($junit);
$files = []; $tests = [];
foreach ($x->xpath('//testsuite[@file]') as $ts) {
    // class-level suites only: parent has no @file
    $p = $ts->xpath('..')[0];
    if (isset($p['file'])) continue;
    $f = (string) $ts['file'];
    $files[$f] = ($files[$f] ?? 0) + (float) $ts['time'];
}
foreach ($x->xpath('//testcase') as $tc) { $tests[] = (float) $tc['time']; }
$n = count($files); $sum = array_sum($files); rsort($tests);
$alpha = $files; ksort($alpha, SORT_STRING);
function simulate(array $durs, int $K): array {
    $free = array_fill(0, $K, 0.0); $startOfLast = [];
    foreach ($durs as $f => $d) {
        $i = array_keys($free, min($free))[0];
        $startOfLast[$f] = $free[$i];
        $free[$i] += $d;
    }
    return [max($free), min($free), $startOfLast];
}
[$mkA, $minA, $startA] = simulate($alpha, $K);
$lpt = $files; arsort($lpt);
[$mkL, $minL] = simulate($lpt, $K);
printf("%s K=%d files=%d tests=%d sum=%.1fs mean/K=%.1fs maxfile=%.1fs maxtest=%.1fs\n", basename($junit), $K, $n, count($tests), $sum, $sum / $K, max($files), $tests[0]);
printf("  alphabetical greedy (paratest default): makespan %.1fs (first worker idle at %.1fs)\n", $mkA, $minA);
printf("  LPT greedy (longest file first):        makespan %.1fs (first worker idle at %.1fs)\n", $mkL, $minL);
printf("  lower bound per-file  max(sum/K,maxfile)= %.1fs ; per-method max(sum/K,maxtest)= %.1fs\n", max($sum / $K, max($files)), max($sum / $K, $tests[0]));
$rank = array_flip(array_keys($alpha));
$top = $files; arsort($top);
echo "  top files: dur | alpha-position% | start in alpha sim | finish\n";
$i = 0;
foreach ($top as $f => $d) {
    if ($i++ >= 15) break;
    printf("   %7.1fs  %5.1f%%  start %6.1fs  end %6.1fs  %s\n", $d, 100 * $rank[$f] / $n, $startA[$f], $startA[$f] + $d, preg_replace('#^.*/tests/#', 'tests/', $f));
}

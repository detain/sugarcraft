<?php

declare(strict_types=1);

// What-if: LPT makespan when named files are split into per-test units
// (or shrunk), using a junit's measured durations.  usage: php split-whatif.php junit.xml (scenarios are candy-code specific; edit $scen)
$x = simplexml_load_file($argv[1]);
$files = []; $per = [];
foreach ($x->xpath('//testcase') as $tc) {
    $f = preg_replace('#^.*/tests/#', 'tests/', (string) $tc['file']);
    $files[$f] = ($files[$f] ?? 0) + (float) $tc['time'];
    $per[$f][] = (float) $tc['time'];
}
function lpt(array $units, int $K): float { rsort($units); $free = array_fill(0, $K, 0.0);
    foreach ($units as $d) { $i = array_keys($free, min($free))[0]; $free[$i] += $d; } return max($free); }
function alpha(array $files, int $K): float { ksort($files); $free = array_fill(0, $K, 0.0);
    foreach ($files as $d) { $i = array_keys($free, min($free))[0]; $free[$i] += $d; } return max($free); }
$scen = [
  'today: alphabetical (paratest default)' => fn() => null,
  'LPT order only' => fn() => array_values($files),
  'LPT + KeyHelpTest split into per-test units' => function () use ($files, $per) { $u = $files; unset($u['tests/Renderer/KeyHelpTest.php']); return array_merge(array_values($u), $per['tests/Renderer/KeyHelpTest.php']); },
  'LPT + KeyHelp split + BuiltInToolTest at 10s' => function () use ($files, $per) { $u = $files; unset($u['tests/Renderer/KeyHelpTest.php']); $u['tests/Tools/BuiltInToolTest.php'] = 10; return array_merge(array_values($u), $per['tests/Renderer/KeyHelpTest.php']); },
  'LPT + every file >=40s split per test' => function () use ($files, $per) { $u = []; foreach ($files as $f => $d) { if ($d >= 40) { array_push($u, ...$per[$f]); } else { $u[] = $d; } } return $u; },
];
printf("%-48s", 'scenario (makespan s, excl. ~35-45s startup)'); foreach ([8, 16, 24, 32, 48, 64] as $K) printf("%8s", "K=$K"); echo "\n";
foreach ($scen as $name => $fn) {
    printf("%-48s", $name);
    foreach ([8, 16, 24, 32, 48, 64] as $K) { $u = $fn(); printf("%8.1f", $u === null ? alpha($files, $K) : lpt($u, $K)); }
    echo "\n";
}
printf("sum=%.0fs\n", array_sum($files));

<?php

declare(strict_types=1);

// Per-test duration across sweep runs -> contention-sensitivity watch list.
// usage: php contention-sensitivity.php <junit.xml> <junit.xml> ...   (one per run; labels = file names)
$paths = array_slice($argv, 1);
$ks = array_map(static fn ($p) => basename($p, '.xml'), $paths);
$t = []; $fail = [];
foreach ($paths as $i => $path) {
    $k = $ks[$i];
    $x = simplexml_load_file($path);
    foreach ($x->xpath('//testcase') as $tc) {
        $id = preg_replace('#^.*\\\\#', '', (string) $tc['class']) . '::' . $tc['name'];
        $t[$id][$k] = (float) $tc['time'];
        if (isset($tc->failure) || isset($tc->error)) $fail[$id][] = $k;
    }
}
$rows = [];
foreach ($t as $id => $byK) {
    if (count($byK) < count($ks)) continue;
    $min = min($byK); $max = max($byK);
    if ($min < 0.3) continue;
    $rows[] = [$max / $min, $min, $max, $id, $byK];
}
usort($rows, fn($a, $b) => $b[0] <=> $a[0]);
printf("tests with min>=0.3s: %d ; ratio>=3x: %d ; ratio>=5x: %d\n", count($rows), count(array_filter($rows, fn($r) => $r[0] >= 3)), count(array_filter($rows, fn($r) => $r[0] >= 5)));
echo "ratio  min    max   per-K (" . implode(',', $ks) . ")  test  [failed at K]\n";
foreach (array_slice($rows, 0, 25) as [$r, $min, $max, $id, $byK]) {
    printf("%5.1fx %5.2f %6.2f  %s  %s %s\n", $r, $min, $max, implode('/', array_map(fn($v) => sprintf('%.1f', $v), $byK)), substr($id, 0, 95), isset($fail[$id]) ? '[' . implode(',', $fail[$id]) . ']' : '');
}
echo "--- known flakes' profiles:\n";
foreach ($fail as $id => $kk) { if (count($kk) < count($ks)) { $b = $t[$id]; printf("  %s  %s [failed %s]\n", implode('/', array_map(fn($v) => sprintf('%.1f', $v), $b)), substr($id, 0, 100), implode(',', $kk)); } }

<?php

declare(strict_types=1);

/*
 * Find the test file that pollutes a victim (order-dependent failure) by bisecting
 * the files that ran before it in the same worker. Each probe is ONE plain phpunit
 * process running [subset..., victim] in that order: the same shape as a paratest
 * worker, without paratest's parent suite-load cost.
 *
 * usage: php bisect-polluter.php <lib-dir> <suspects.txt> <victim-file> <work-dir> [--filter=<victim test regex>] [--env=K=V ...]
 *   suspects.txt: one test file per line, in execution order (worker-seq.php output).
 *   Paths may be relative to the lib dir. Assumes one polluter; if the victim also
 *   fails with no suspects it says so (not order-dependent: look at load/timing instead).
 */

$args = array_slice($argv, 1);
$opts = ['filter' => null, 'env' => []];
$pos = [];
foreach ($args as $a) {
    if (str_starts_with($a, '--filter=')) {
        $opts['filter'] = substr($a, 9);
    } elseif (str_starts_with($a, '--env=')) {
        [$k, $v] = explode('=', substr($a, 6), 2);
        $opts['env'][$k] = $v;
    } else {
        $pos[] = $a;
    }
}
[$lib, $suspectsFile, $victim, $work] = array_pad($pos, 4, null);
if ($work === null) {
    fwrite(STDERR, "usage: php bisect-polluter.php <lib-dir> <suspects.txt> <victim-file> <work-dir> [--filter=regex] [--env=K=V]\n");
    exit(2);
}
$lib = rtrim((string) realpath($lib), '/');
@mkdir($work, 0777, true);
$abs = static fn (string $p): string => str_starts_with($p, '/') ? $p : "$lib/$p";
$suspects = array_map($abs, file($suspectsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$victim = $abs($victim);
$base = file_get_contents("$lib/phpunit.xml");
$probe = 0;

$fails = static function (array $set) use (&$probe, $lib, $base, $victim, $work, $opts, $abs): bool {
    $probe++;
    $list = '';
    foreach ([...$set, $victim] as $f) {
        $list .= '<file>' . htmlspecialchars($f, ENT_XML1) . "</file>\n";
    }
    $xml = preg_replace('#<testsuites>.*</testsuites>#s', "<testsuites><testsuite name=\"bisect\">\n$list</testsuite></testsuites>", $base);
    $xml = preg_replace_callback('/(bootstrap|cacheDirectory)="([^"]+)"/', static fn ($m) => $m[1] . '="' . $abs($m[2]) . '"', $xml);
    $xml = preg_replace('/\s+xsi:noNamespaceSchemaLocation="[^"]*"/', '', $xml);
    // <source>/<coverage> paths are relative to the config's own dir, which is now $work
    $xml = preg_replace_callback('#<(directory|file)([^>]*)>([^<]+)</\1>#', static fn ($m) => "<{$m[1]}{$m[2]}>" . $abs(trim($m[3])) . "</{$m[1]}>", $xml);
    $cfg = "$work/probe-$probe.xml";
    file_put_contents($cfg, $xml);
    $junit = "$work/probe-$probe.junit.xml";
    $cmd = [PHP_BINARY, "$lib/vendor/bin/phpunit", '-c', $cfg, '--colors=never', '--log-junit', $junit];
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$work/probe-$probe.out", 'w'], 2 => ['redirect', 1]], $pipes, $lib, $opts['env'] + getenv());
    $rc = proc_close($p);
    $x = @simplexml_load_file($junit);
    $bad = false;
    if ($x !== false) {
        foreach ($x->xpath('//testcase[failure or error]') as $tc) {
            $id = $tc['class'] . '::' . $tc['name'];
            if ((string) $tc['file'] === $victim && ($opts['filter'] === null || preg_match($opts['filter'], $id))) {
                $bad = true;
            }
        }
    } else {
        $bad = $rc !== 0; // crashed before writing junit: count as a reproduction
    }
    fprintf(STDERR, "probe %d: %d suspects + victim -> %s (rc=%d)\n", $probe, count($set), $bad ? 'VICTIM FAILS' : 'victim passes', $rc);
    return $bad;
};

if ($fails([])) {
    echo "victim fails ALONE: not order-dependent (check load/timing, or it is a tree red)\n";
    exit(1);
}
if (!$fails($suspects)) {
    echo "victim passes after all suspects: no reproduction in one process (try the same env/K, or it needs concurrency/load)\n";
    exit(1);
}
while (count($suspects) > 1) {
    $half = intdiv(count($suspects), 2);
    $first = array_slice($suspects, 0, $half);
    $second = array_slice($suspects, $half);
    // keep order: polluter must still run before the victim
    $suspects = $fails($second) ? $second : ($fails($first) ? $first : $suspects);
    if ($suspects !== $first && $suspects !== $second) {
        echo "neither half alone reproduces: the failure needs files from both halves (multiple polluters / accumulation)\n";
        echo implode("\n", $suspects), "\n";
        exit(1);
    }
}
echo "POLLUTER: {$suspects[0]}\n";

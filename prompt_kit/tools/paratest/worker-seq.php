<?php

declare(strict_types=1);

/*
 * Reconstruct what each ParaTest worker process ran, from a `paratest --verbose` log
 * ("Starting process N: ..." / "Process N executing: <file>"; progress dots may prefix those lines), and list the files a
 * victim shared a process with BEFORE it ran: the order-dependence / pollution suspects.
 *
 * usage: php worker-seq.php <verbose.out>                 -> per-process summary
 *        php worker-seq.php <verbose.out> <victim-substr> -> suspects, one path per line (feed to bisect-polluter.php)
 */

[$self, $log, $victim] = array_pad($argv, 3, null);
if ($log === null) {
    fwrite(STDERR, "usage: php $self <verbose.out> [victim-file-substring]\n");
    exit(2);
}
$segments = []; // "token#generation" => list of files, in execution order
$gen = [];
foreach (file($log, FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match('/Starting process (\d+):/', $line, $m)) {
        $gen[$m[1]] = ($gen[$m[1]] ?? 0) + 1;
        $segments[$m[1] . '#' . $gen[$m[1]]] = [];
    } elseif (preg_match('/Process (\d+) executing: (\S+)/', $line, $m)) {
        $segments[$m[1] . '#' . ($gen[$m[1]] ?? 1)][] = trim($m[2]);
    }
}
if ($victim === null) {
    foreach ($segments as $id => $files) {
        printf("process %-6s %4d files  first=%s  last=%s\n", $id, count($files), $files[0] ?? '-', end($files) ?: '-');
    }
    exit(0);
}
foreach ($segments as $id => $files) {
    foreach ($files as $i => $f) {
        if (str_contains($f, $victim)) {
            fprintf(STDERR, "victim %s ran in process %s as file %d of %d; %d suspects ran before it\n", $f, $id, $i + 1, count($files), $i);
            echo implode("\n", array_slice($files, 0, $i)), $i > 0 ? "\n" : '';
            exit(0);
        }
    }
}
fwrite(STDERR, "victim not found in $log\n");
exit(1);

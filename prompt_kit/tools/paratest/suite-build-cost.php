<?php

declare(strict_types=1);

// Per-file suite-BUILD cost (class load + every data provider), i.e. what the
// paratest parent pays once per run and a worker pays per dispatch unit.
// usage (from the lib dir, with the lib's env prefix): php suite-build-cost.php <lib-abs-path> <out.tsv>
[$_, $lib, $out] = $argv;
require $lib . '/vendor/autoload.php';
(new \PHPUnit\TextUI\Configuration\Builder())->build(['phpunit', '-c', $lib . '/phpunit.xml']);
if (is_file($lib . '/tests/bootstrap.php')) { require $lib . '/tests/bootstrap.php'; }
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib . '/tests', FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($it as $f) { if (str_ends_with($f->getFilename(), 'Test.php')) $files[] = $f->getPathname(); }
sort($files);
$rows = []; $t0 = hrtime(true);
foreach ($files as $f) {
    $s = hrtime(true);
    $suite = \PHPUnit\Framework\TestSuite::empty('x');
    try { $suite->addTestFile($f); $n = count($suite); } catch (\Throwable $e) { $n = -1; static $shown = 0; if ($shown++ < 2) fwrite(STDERR, get_class($e) . ": " . $e->getMessage() . "\n"); }
    $rows[] = sprintf("%.4f\t%d\t%s", (hrtime(true) - $s) / 1e9, $n, substr($f, strlen($lib) + 1));
}
$total = (hrtime(true) - $t0) / 1e9;
usort($rows, fn($a, $b) => (float) $b <=> (float) $a);
file_put_contents($out, implode("\n", $rows) . "\n");
fprintf(STDERR, "files=%d total_build=%.1fs peak_mem=%dMB\n", count($files), $total, memory_get_peak_usage(true) >> 20);

<?php

declare(strict_types=1);

/**
 * candy-kv-073 memory-shape pin child: seed N rows, export through the
 * SAME run() door the binary uses, then print peak usage. A re-growth of
 * the whole-string accumulation shows up as a payload-proportional jump
 * (measured ~2.0x on the JSON batch shape, ~8x on symfony YAML) far above
 * the flat ceiling the streaming writer earns.
 *
 * Usage: php export-peak.php <rows> <dataDir> <outFile>
 * Prints: PEAK <bytes> SIZE <bytes>
 */

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!\is_file($autoload)) {
    fwrite(STDERR, "export-peak: cannot find the library autoload\n");
    exit(3);
}
require $autoload;

$rows = (int) ($argv[1] ?? 0);
$dataDir = (string) ($argv[2] ?? '');
$outFile = (string) ($argv[3] ?? '');
if ($rows <= 0 || $dataDir === '' || $outFile === '') {
    fwrite(STDERR, "export-peak: rows, dataDir, outFile are required\n");
    exit(3);
}

$store = new SugarCraft\Kv\Store($dataDir, 'big');
$value = str_repeat('payload-bytes-0123456789 ', 50); // ~1.45 KB per row
// One transaction for the seed: per-row commits are ~2.3 ms each here and
// the SUBJECT of this measurement is the export walk, not fsync.
$store->transaction('big', static function () use ($store, $value, $rows): void {
    for ($i = 0; $i < $rows; $i++) {
        $store->set(sprintf('key%06d', $i), $value . $i);
    }
});

$sink = fopen($outFile, 'wb');
if ($sink === false) {
    fwrite(STDERR, "export-peak: cannot open outFile\n");
    exit(3);
}
$command = new SugarCraft\Kv\Cli\ExportCommand($store, $sink, STDERR);
$exit = $command->run('json');
fclose($sink);
if ($exit !== 0) {
    fwrite(STDERR, "export-peak: run() answered {$exit}\n");
    exit(4);
}

printf("PEAK %d SIZE %d\n", memory_get_peak_usage(true), filesize($outFile));
exit(0);

<?php
declare(strict_types=1);
require '/home/sites/cc-cmp-C-TOOL-7.b/candy-code/vendor/autoload.php';

use SugarCraft\Code\Tools\BuiltIn\Ls;

$root = realpath(sys_get_temp_dir()) . '/c7b-measure-' . uniqid('', true);
mkdir($root);
$huge = $root . '/wide';
mkdir($huge);
$count = 20000;
for ($i = 0; $i < $count; $i++) {
    fclose(fopen($huge . '/entry-' . str_pad((string)(($i * 7919) % $count), 6, '0', STR_PAD_LEFT), 'x'));
}
$shown = 50;

function held(callable $run): array {
    $run(); // warm
    $b = memory_get_usage(false);
    memory_reset_peak_usage();
    $out = $run();
    $peak = memory_get_peak_usage(false) - $b;
    return [$peak, strlen($out)];
}

$runCapped = fn (): string => (new Ls(root: $root, maxEntries: $shown))
    ->execute(['id' => 'm', 'path' => $huge, 'description' => 'm'])->content();
$runFull = fn (): string => (new Ls(root: $root, maxEntries: $count + 1))
    ->execute(['id' => 'm', 'path' => $huge, 'description' => 'm'])->content();

[$pcap, $lcap] = held($runCapped);
[$pfull, $lfull] = held($runFull);
printf("heap-capped peak-held=%d bytes (len=%d)\n", $pcap, $lcap);
printf("heap-full   peak-held=%d bytes (len=%d)\n", $pfull, $lfull);
$first = array_slice(explode("\n", $runCapped()), 0, 3);
echo implode("\n", $first), "\n";

// cleanup
foreach (scandir($huge) as $e) { if ($e !== '.' && $e !== '..') unlink($huge . '/' . $e); }
rmdir($huge); rmdir($root);

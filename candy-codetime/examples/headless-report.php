<?php

declare(strict_types=1);

/**
 * A headless report over the file store, with no terminal needed:
 *   php examples/headless-report.php < /dev/null
 *
 * It seeds a throwaway data directory with two days of heartbeats, reads the
 * window back through Store::loadRange(), and folds it with Stats::compute().
 * This is the same path the `summary` command takes, so it shows what a
 * script can do with the library without the CLI or the dashboard.
 */
require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\CodeTime\Heartbeat;
use SugarCraft\CodeTime\Stats;
use SugarCraft\CodeTime\Store;

// A random suffix keeps the report on data this run seeded: a fixed path in the
// shared temp directory could hold another user's beats, which would be reported
// as this example's output.
$dir = sys_get_temp_dir() . '/candy-codetime-headless-report-' . bin2hex(random_bytes(8));
// Owner-private from the first byte, the same rule the CLI applies to its data dir.
Store::ensurePrivateDir($dir, 'demo data directory');
$store = new Store($dir);

$today = (new DateTimeImmutable('today'))->setTime(9, 0);
$beats = [
    [0, 'sugarcraft', 'php', 'src/App.php', 1800],
    [0, 'sugarcraft', 'markdown', 'README.md', 300],
    [1, 'candy-tetris', 'php', 'src/Board.php', 2400],
];
foreach ($beats as [$daysAgo, $project, $language, $file, $duration]) {
    $store->append(new Heartbeat(
        time: $today->modify("-{$daysAgo} days")->getTimestamp(),
        project: $project,
        language: $language,
        file: $file,
        duration: $duration,
    ));
}

$from = $today->modify('-1 day');
$stats = Stats::compute($store->loadRange($from, $today), $from, $today);

printf("total: %s\n", Stats::formatHours($stats->totalSeconds()));
echo "by project:\n";
foreach ($stats->perProject() as $project => $seconds) {
    printf("  %-14s %s\n", $project, Stats::formatHours($seconds));
}
echo "by language:\n";
foreach ($stats->perLanguage() as $language => $seconds) {
    printf("  %-14s %s\n", $language, Stats::formatHours($seconds));
}

exec('rm -rf ' . escapeshellarg($dir));

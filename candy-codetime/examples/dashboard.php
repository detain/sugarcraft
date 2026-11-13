<?php

declare(strict_types=1);

/**
 * Run candy-codetime against a tmp data dir seeded with a week of fake
 * heartbeats so the dashboard renders something interesting:
 *   php examples/dashboard.php
 */
require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\CodeTime\Dashboard;
use SugarCraft\CodeTime\Heartbeat;
use SugarCraft\CodeTime\Store;
use SugarCraft\Core\Util\TtyDetect;

// The same headless refusal the dashboard command answers with (finding
// 022): without a terminal on both descriptors the alt-screen dump hangs
// waiting for keys that can never arrive, so the example refuses like the
// binary does instead of painting escape codes into a pipe.
if (!TtyDetect::isAtty(STDIN) || !TtyDetect::isAtty(STDOUT)) {
    fwrite(STDERR, \SugarCraft\CodeTime\Lang::t('cli.dashboard_needs_tty') . "\n");
    exit(1);
}

$dir = sys_get_temp_dir() . '/candy-codetime-demo';
// The same privacy law the CLI stores under: owner-private 0700 created
// under a narrowed umask, tightening a loose leftover from an older run,
// throwing rather than seeding demo paths into the open.
Store::ensurePrivateDir($dir, 'demo data directory');
$store = new Store($dir);

// Seed seven days × a couple of beats per day, deterministic-ish. The seed
// is idempotent: a rerun on a store that already has this week's rows keeps
// them as-is instead of piling duplicates onto the demo data.
$today    = (new DateTimeImmutable('today'))->setTime(9, 0);
if ($store->loadRange($today->modify('-6 days'), $today) === []) {
    $projects = ['sugarcraft' => 1.0, 'candy-tetris' => 0.5, 'super-candy' => 0.3];
    $languages = ['php' => 0.8, 'shell' => 0.15, 'markdown' => 0.05];
    mt_srand(20260503);
    for ($d = 6; $d >= 0; $d--) {
        $day = $today->modify("-{$d} days");
        foreach ($projects as $project => $weight) {
            foreach ($languages as $language => $lw) {
                $duration = (int) (1800 * $weight * $lw * (1 + mt_rand(0, 100) / 200));
                if ($duration < 1) continue;
                $store->append(new Heartbeat(
                    time: $day->getTimestamp() + mt_rand(0, 3600 * 6),
                    project: $project,
                    language: $language,
                    file: "src/example.{$language}",
                    duration: $duration,
                ));
            }
        }
    }
}

(new Program(Dashboard::start($store), new ProgramOptions(useAltScreen: true)))->run();

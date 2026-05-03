<?php

declare(strict_types=1);

/**
 * Run sugar-tick against a tmp data dir seeded with a week of fake
 * heartbeats so the dashboard renders something interesting:
 *   php examples/dashboard.php
 */
require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;
use CandyCore\Tick\Dashboard;
use CandyCore\Tick\Heartbeat;
use CandyCore\Tick\Store;

$dir = sys_get_temp_dir() . '/sugar-tick-demo';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
$store = new Store($dir);

// Seed seven days × a couple of beats per day, deterministic-ish.
$today    = (new DateTimeImmutable('today'))->setTime(9, 0);
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

(new Program(Dashboard::start($store), new ProgramOptions(useAltScreen: true)))->run();

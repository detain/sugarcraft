<?php

declare(strict_types=1);

/**
 * Launch candy-top on its deterministic fake sources — the VHS demo
 * (.vhs/top.tape) entry point, so the recording never shows the
 * recording host's real processes.
 *
 *   php examples/top.php
 *
 * The config path points at a file that never exists, so a user's
 * ~/.config/candy-top/config.conf cannot restyle the demo.
 */

$argv = [
    $argv[0] ?? 'top.php',
    '--fake',
    '--config',
    sys_get_temp_dir() . '/candy-top-demo-' . getmypid() . '/config.conf',
];
$argc = count($argv);

require __DIR__ . '/../bin/candy-top';

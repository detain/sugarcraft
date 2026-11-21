<?php

declare(strict_types=1);

/**
 * Reads a serve state directory the way a client does, without a running server.
 *
 * Usage: php examples/read-state.php
 *
 * The script builds a private state directory in the system temp area (mode 0700,
 * as the host would), writes a discovery record naming this process and a token
 * file, then reads both back through the package. Nothing outside that temp
 * directory is touched, and the directory is removed at the end.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\ServeProtocol\Auth\TokenFile;
use SugarCraft\ServeProtocol\Discovery\LiveRecord;
use SugarCraft\ServeProtocol\Discovery\StateDirReader;

$dir = sys_get_temp_dir() . '/sp-example-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
chmod($dir, 0700);

try {
    // The discovery record names this process, so LiveRecord reports it as running.
    file_put_contents($dir . '/server.json', json_encode([
        'pid' => getmypid(),
        'url' => 'ws://127.0.0.1:7420/ws',
    ], JSON_THROW_ON_ERROR));
    file_put_contents($dir . '/token', bin2hex(random_bytes(32)) . "\n");

    $state = StateDirReader::existing($dir);
    echo 'state dir: ', $state ?? 'absent', PHP_EOL;

    $token = $state === null ? null : TokenFile::read($state);
    echo 'token: ', $token === null ? 'none' : substr($token, 0, 8) . '… (64 hex chars)', PHP_EOL;

    $url = $state === null ? null : LiveRecord::url($state);
    echo 'live url: ', $url ?? 'none (no running server)', PHP_EOL;

    echo 'absent dir: ', var_export(StateDirReader::existing($dir . '-missing'), true), PHP_EOL;
} finally {
    foreach (['server.json', 'token'] as $file) {
        @unlink($dir . '/' . $file);
    }
    @rmdir($dir);
}

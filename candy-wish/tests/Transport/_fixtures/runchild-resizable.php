<?php

declare(strict_types=1);

/**
 * Test fixture — same as runchild.php but installs a custom size
 * provider that reads `<cols> <rows>` from a tempfile on every
 * SIGWINCH. The test mutates the tempfile + signals SIGWINCH to
 * drive resize events without setting up a host PTY.
 *
 * Usage:
 *   php runchild-resizable.php <size-file> <cols> <rows> <cmd> [args...]
 */

require __DIR__ . '/../../../vendor/autoload.php';

use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\InProcessTransport;

$sizeFile = $argv[1] ?? '';
$cols = (int) ($argv[2] ?? 80);
$rows = (int) ($argv[3] ?? 24);
$cmd = \array_slice($argv, 4);
if ($sizeFile === '' || $cmd === []) {
    \fwrite(\STDERR, "usage: runchild-resizable.php <size-file> <cols> <rows> <cmd> [args...]\n");
    exit(2);
}

$session = new Session(
    user: 'fixture', clientHost: '127.0.0.1', clientPort: 0, serverHost: '127.0.0.1',
    serverPort: 22, term: 'xterm-256color', cols: $cols, rows: $rows,
    tty: null, command: null, lang: 'C.UTF-8',
);

$provider = static function () use ($sizeFile, $cols, $rows): array {
    $raw = @\file_get_contents($sizeFile);
    if (!\is_string($raw) || \trim($raw) === '') {
        return ['cols' => $cols, 'rows' => $rows];
    }
    $parts = \preg_split('/\s+/', \trim($raw)) ?: [];
    $newCols = isset($parts[0]) ? (int) $parts[0] : $cols;
    $newRows = isset($parts[1]) ? (int) $parts[1] : $rows;
    return ['cols' => $newCols, 'rows' => $newRows];
};

$transport = (new InProcessTransport())->withSizeProvider($provider);

try {
    $exit = $transport->runChild($session, $cmd);
    exit($exit);
} catch (\Throwable $e) {
    \fwrite(\STDERR, "runchild-resizable fixture: " . $e::class . ": " . $e->getMessage() . "\n");
    exit(2);
}

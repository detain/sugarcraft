<?php

declare(strict_types=1);

/**
 * Child-process probe for RawModeTest, run by the phpunit parent inside a
 * real pty via `script -q -e -c`. The parent asserts on the JSON this
 * prints to stdout.
 *
 * Why a child: this host's /dev/pts pairing semantics (master inode vs
 * slave index) cannot be relied on to build a slave stream in-process,
 * and `script` is the house idiom (see closed_descriptor_zero_probe.php)
 * for handing a genuine terminal discipline to the code under test.
 *
 * Modes:
 *  - roundtrip: preset a non-sane state (-ixon), snapshot, enable,
 *    snapshot, disable, snapshot. The exact-restore contract lives in
 *    the byte identity of before/after plus the raw flag in mid.
 *  - hostile: PATH stripped of stty; enable() must throw, not no-op.
 */

namespace SugarCraft\Core\Tests\Util;

require __DIR__ . '/../../vendor/autoload.php';

use SugarCraft\Core\Util\RawMode;

$mode = $argv[1] ?? '';

/** @return array{0: string, 1: int} */
$capture = static function (string $args): array {
    $out = [];
    $status = -1;
    \exec('stty -F /dev/tty ' . \escapeshellarg($args) . ' 2>/dev/null', $out, $status);
    return [\trim(\implode("\n", $out)), $status];
};

if ($mode === 'roundtrip') {
    $preset = $capture('-ixon');
    $before = $capture('-g');
    $verify = $capture('-g');
    try {
        RawMode::enable(STDIN);
        $mid = $capture('-g');
        RawMode::disable(STDIN);
        $after = $capture('-g');
        echo \json_encode([
            'preset_status' => $preset[1],
            'verify' => $verify[0],
            'before' => $before[0],
            'before_status' => $before[1],
            'mid' => $mid[0],
            'mid_status' => $mid[1],
            'after' => $after[0],
            'after_status' => $after[1],
        ]), "\n";
        exit(0);
    } catch (\Throwable $t) {
        echo \json_encode(['error' => $t::class, 'message' => $t->getMessage()]), "\n";
        exit(2);
    }
}

if ($mode === 'hostile') {
    $dir = \sys_get_temp_dir() . '/raw-mode-empty-path-' . \getmypid();
    if (!\is_dir($dir)) {
        \mkdir($dir, 0700, true);
    }
    \putenv('PATH=' . $dir);
    try {
        RawMode::enable(STDIN);
        echo \json_encode(['noThrow' => true]), "\n";
        exit(3);
    } catch (\Throwable $t) {
        // Restore PATH before reporting so the shutdown backstop (if any
        // snapshot sneaked in) is not the thing under test.
        \putenv('PATH=' . \getenv('ORIG_PATH'));
        \rmdir($dir);
        echo \json_encode(['threw' => $t::class, 'message' => $t->getMessage()]), "\n";
        exit(0);
    }
}

echo \json_encode(['error' => 'unknown-mode', 'message' => $mode]), "\n";
exit(4);

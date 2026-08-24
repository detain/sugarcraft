<?php

declare(strict_types=1);

/**
 * Child-process probe for the {@see \SugarCraft\Core\Util\Tty\PosixBackend::restoreLast()}
 * ROUND TRIP.
 *
 * Driven by {@see \SugarCraft\Core\Tests\Util\Tty\PosixBackendRestoreLastTest};
 * never collected by PHPUnit, which only picks up `*Test.php`.
 *
 * ## Why a child, and why the terminal is read with `stty`
 *
 * `restoreLast()` takes no arguments and returns nothing: it reads descriptor
 * 0 and it writes to whatever device that is. Both halves are invisible from
 * inside a PHPUnit process, which cannot rearrange its own descriptor 0
 * without taking the rest of the run with it. So the arrangement is made by
 * the caller and observed here.
 *
 * The terminal is read back with `stty -F <slave path> -a` rather than through
 * candy-pty. Two reasons: candy-pty treats `struct termios` as opaque on
 * purpose, so there is no field to read; and a reading taken through the same
 * binding that applied the change would not be an independent observation of
 * it.
 *
 * Usage: `php restore_last_round_trip_probe.php <mode> <slave-path> <result-file>`
 */

use SugarCraft\Core\Util\Tty\PosixBackend;
use SugarCraft\Pty\TermiosFactory;

require \dirname(__DIR__, 3) . '/vendor/autoload.php';

$mode       = $argv[1] ?? '';
$slavePath  = $argv[2] ?? '';
$resultFile = $argv[3] ?? '';

/** Is the device at $path in raw mode? */
$isRaw = static function (string $path): ?bool {
    // GNU coreutils takes -F, BSD/macOS takes -f. The wrong one prints
    // nothing, which would read as "not raw" whatever the device is doing --
    // so an empty reading is reported as null and never as false.
    $flag = \PHP_OS_FAMILY === 'Darwin' ? '-f' : '-F';
    $out  = trim((string) shell_exec('stty ' . $flag . ' ' . escapeshellarg($path) . ' -a 2>/dev/null'));
    if ($out === '') {
        return null;
    }

    return str_contains($out, '-icanon') && str_contains($out, '-echo');
};

$snapshot = static function (): bool {
    return (new ReflectionClass(PosixBackend::class))->getProperty('rescueSnapshot')->getValue() !== null;
};

$out = [
    'mode'     => $mode,
    'isatty_0' => \function_exists('posix_isatty') ? posix_isatty(0) : null,
];

if ($mode === 'round-trip') {
    $out['state_initial'] = $isRaw($slavePath);

    // FIRST call: snapshot descriptor 0's termios as it stands.
    PosixBackend::restoreLast();
    $out['snapshot_after_first'] = $snapshot();

    // Take the terminal somewhere else, through candy-pty directly so that
    // the change under observation is not made by the code being observed.
    TermiosFactory::open(0)->makeRaw()->apply();
    $out['state_after_raw'] = $isRaw($slavePath);

    // SECOND call: re-apply the snapshot. This is the half nothing pinned.
    PosixBackend::restoreLast();
    $out['state_after_restore']   = $isRaw($slavePath);
    $out['snapshot_after_second'] = $snapshot();
} elseif ($mode === 'no-tty-twice') {
    PosixBackend::restoreLast();
    $out['snapshot_after_first'] = $snapshot();

    // The second call is the point: with no snapshot from the first, it must
    // take the first-call branch again rather than applying a null.
    PosixBackend::restoreLast();
    $out['snapshot_after_second'] = $snapshot();
} else {
    fwrite(\STDERR, 'unknown probe mode: ' . $mode . "\n");
    exit(2);
}

// Control: proves the body ran to the end. A missing or wrong marker means
// no other row above is evidence of anything.
$out['control'] = 'PROBE-RAN';

file_put_contents($resultFile, json_encode($out));
exit(0);

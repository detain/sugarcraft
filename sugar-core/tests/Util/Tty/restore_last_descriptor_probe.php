<?php

declare(strict_types=1);

/**
 * Child-process probe for {@see \SugarCraft\Core\Util\Tty\PosixBackend::restoreLast()}.
 *
 * Driven by {@see \SugarCraft\Core\Tests\Util\Tty\PosixBackendRestoreLastDescriptorTest};
 * never collected by PHPUnit itself, because the suite only picks up files
 * whose name ends in `Test.php`.
 *
 * ## Why a child at all
 *
 * `restoreLast()` takes no arguments: WHICH descriptor it snapshots is baked
 * into its body, and the only way to observe the choice is to arrange for
 * descriptors 0 and 1 to differ and see which one it reached. A PHPUnit
 * process cannot rearrange its own standard descriptors without taking the
 * rest of the run with it, so the arrangement happens here — in a process
 * whose caller decided what its descriptors 0 and 1 are, and whose whole job
 * is to report and die.
 *
 * ## The two arrangements, and why there are two
 *
 * `tty-on-0`  descriptor 0 is a terminal device, descriptor 1 is a pipe.
 *             A snapshot MUST be taken.
 * `tty-on-1`  descriptor 0 is a pipe, descriptor 1 is a terminal device.
 *             A snapshot MUST NOT be taken.
 *
 * The second is the sharp one — it is the arrangement in which reading
 * descriptor 1 succeeds and reading descriptor 0 does not, so a body that
 * asks about the wrong descriptor produces a snapshot where the right one
 * produces none. The first exists because "no snapshot" is also what a
 * gutted `restoreLast()` produces, and an expectation of `null` that a
 * deleted method body would satisfy is not evidence. Neither arrangement is
 * evidence alone; together, no constant answer satisfies both.
 *
 * Results are written to <result-file> as JSON, because in `tty-on-1` mode
 * standard output is a terminal device the harness is not reading.
 *
 * Usage: `php restore_last_descriptor_probe.php <mode> <result-file>`
 */

use SugarCraft\Core\Util\Tty\PosixBackend;

$autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
require $autoload;

$mode       = $argv[1] ?? '';
$resultFile = $argv[2] ?? '';

$out = [
    // The arrangement, reported rather than assumed, so the harness can
    // refuse to draw a conclusion from a child whose descriptors were not
    // what it asked for.
    'isatty_0' => \function_exists('posix_isatty') ? posix_isatty(0) : null,
    'isatty_1' => \function_exists('posix_isatty') ? posix_isatty(1) : null,
    'mode'     => $mode,

    // Control: proves the probe body ran to the end. A missing or wrong
    // marker means no other row is evidence.
    'control'  => 'PROBE-RAN',
];

// The observation. `restoreLast()` swallows its own failure by design, so
// the snapshot itself — a private static — is the only place its choice of
// descriptor is visible.
$snapshotBefore = (new ReflectionClass(PosixBackend::class))->getProperty('rescueSnapshot');
$out['snapshot_before'] = $snapshotBefore->getValue() !== null;

PosixBackend::restoreLast();

$out['snapshot_after'] = $snapshotBefore->getValue() !== null;

// Second control, and the one that makes `snapshot_after === false` mean
// something: read BOTH descriptors directly and record which of them a
// termios snapshot can actually be taken from. If this disagrees with the
// arrangement above, the fixture is broken and not the code.
foreach ([0, 1] as $fd) {
    try {
        \SugarCraft\Pty\TermiosFactory::open($fd)->current();
        $out['tcgetattr_' . $fd] = true;
    } catch (\Throwable $e) {
        $out['tcgetattr_' . $fd] = false;
    }
}

file_put_contents($resultFile, json_encode($out));
exit(0);

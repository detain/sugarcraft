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

use SugarCraft\Core\Tests\Util\Tty\SttyReading;
use SugarCraft\Core\Util\Tty\PosixBackend;
use SugarCraft\Pty\TermiosFactory;

require \dirname(__DIR__, 3) . '/vendor/autoload.php';

$mode       = $argv[1] ?? '';
$slavePath  = $argv[2] ?? '';
$resultFile = $argv[3] ?? '';

/**
 * Is the device at $path in raw mode?
 *
 * The flag matching is {@see SttyReading}'s, not this file's. It used to be a
 * private copy of a substring test that is TRUE on a cooked terminal -- the
 * negated ECHO spelling occurs inside the negated ECHONL and ECHOPRT tokens
 * -- so the ECHO half of the round trip below asserted nothing. MEASURED
 * (mutation MA2_PROBE), whole candy-core suite: deleting that half outright
 * SURVIVED, 827 tests / 7512 assertions / rc 0.
 *
 * An unreadable device still answers null and never false: every flag query
 * against an empty reading says "not set", i.e. "not raw", which must not be
 * reportable as an observation of a cooked terminal.
 */
$isRaw = static function (string $path): ?bool {
    $reading = SttyReading::of($path);
    if ($reading === '') {
        return null;
    }

    return SttyReading::isRaw($reading);
};

$snapshot = static function (): bool {
    return (new ReflectionClass(PosixBackend::class))->getProperty('rescueSnapshot')->getValue() !== null;
};

$out = [
    'mode'     => $mode,
    'isatty_0' => \function_exists('posix_isatty') ? posix_isatty(0) : null,

    // THE CHILD'S OWN INSTRUMENT CONTROL. Every state row below is a claim
    // about what the matcher did not see; a matcher that matched nothing
    // would answer "not raw" forever and the restore half of the round trip
    // would pass for free. Computed from a synthetic reading that carries the
    // lookalike trap, so it is a fact about the matcher and not about this
    // host's stty vocabulary. The parent asserts on it.
    'matcher_discriminates' => SttyReading::isRaw(SttyReading::cookedFixture()) === false
        && SttyReading::isOn(SttyReading::cookedFixture(), 'echo')
        && str_contains(SttyReading::cookedFixture(), '-echo'),
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

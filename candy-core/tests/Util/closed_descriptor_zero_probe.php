<?php

declare(strict_types=1);

/**
 * Child-process probe for the closed-descriptor-0 family.
 *
 * Driven by {@see \SugarCraft\Core\Tests\Util\ClosedDescriptorZeroFamilyTest};
 * never collected by PHPUnit itself, because the suite only picks up files
 * whose name ends in `Test.php`.
 *
 * Why a child at all: the point of the family is what happens when descriptor
 * 0 has been CLOSED, and a suite cannot close its own standard input without
 * taking the rest of the run down with it. So the closing happens here, in a
 * process whose whole job is to die afterwards.
 *
 * Usage: `php closed_descriptor_zero_probe.php <mode> <result-file>`, with
 * three modes:
 *
 *   `closed`  close descriptors 0, 1 and 2, then probe
 *   `live`    leave them alone (the caller gives descriptor 0 /dev/null)
 *   `tty`     leave them alone (the caller gives descriptor 0 a REAL
 *             terminal device, so every family row answers `true`)
 *
 * The child does not know which of `live` and `tty` it is in beyond the
 * argument; the difference is entirely in what the caller put on descriptor
 * 0, which is why `tty` is a positive control rather than a second copy of
 * the same negative one.
 *
 * Results are written to <result-file> as JSON rather than to standard
 * output, precisely because `closed` mode closes standard output too.
 *
 * Each probe is recorded as one of:
 *   {"ok": <json-encodable value>}      the callable returned
 *   {"throw": "<Class>: <message>"}     the callable threw
 *
 * Two of the probes are CONTROLS and are not part of the family:
 *
 *   - `control_ok` returns a marker string. If it is missing or wrong, the
 *     harness did not run the probes and no other row is evidence.
 *   - `control_unguarded_isatty` is the exact unguarded shape the family
 *     fixes, called deliberately. In `closed` mode it MUST be reported as a
 *     throw. That single row proves three things at once: the harness can
 *     detect a throw, the harness reports it rather than swallowing it, and
 *     descriptor 0 in this child really is closed — so a green family row is
 *     "it did not throw" rather than "nothing was exercised".
 *
 * And the `tty` mode is the other half of that argument. Every family row in
 * `closed` mode expects `false`, and `false` is also what a gutted probe
 * returns — a row asserting it is not evidence on its own. In `tty` mode the
 * same rows must answer `true`, which a constant cannot fake without being
 * wrong in the other two modes.
 */

use SugarCraft\Core\ExecRequest;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Util\Tty\EnvDetect;
use SugarCraft\Core\Util\TtyDetect;
use React\EventLoop\StreamSelectLoop;

require __DIR__ . '/../../vendor/autoload.php';

/** Inert model: records nothing, commands nothing, never quits. */
final class ClosedDescriptorZeroProbeModel implements Model
{
    use \SugarCraft\Core\SubscriptionCapable;

    public function init(): ?\Closure
    {
        return null;
    }

    /** @return array{0:Model,1:?\Closure} */
    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return '';
    }
}

$mode       = $argv[1] ?? 'closed';
$resultFile = $argv[2] ?? '';
if ($resultFile === '') {
    exit(64);
}

// A live sink for everything the Program renders, so that closing the
// standard streams below cannot take the Renderer with it. It is a real
// file rather than php://memory because proc_open() needs a descriptor.
$sinkPath = $resultFile . '.sink';
$sink     = fopen($sinkPath, 'w+b');

if ($mode === 'closed') {
    // The whole point of the child. Order matters only in that the probes
    // below must not need any of the three afterwards.
    fclose(\STDIN);
    fclose(\STDOUT);
    fclose(\STDERR);
}

/** @var array<string, array{ok?: mixed, throw?: string}> $results */
$results = [];

/** @param callable():mixed $probe */
$run = static function (string $name, callable $probe) use (&$results): void {
    try {
        $results[$name] = ['ok' => $probe()];
    } catch (\Throwable $t) {
        $results[$name] = ['throw' => $t::class . ': ' . $t->getMessage()];
    }
};

// ── controls ────────────────────────────────────────────────────────────────

$run('control_ok', static fn (): string => 'PROBES-RAN');

$run('control_unguarded_isatty', static fn (): bool => \stream_isatty(\STDIN));

// ── family members ──────────────────────────────────────────────────────────

$run('tty_detect_is_atty', static fn (): bool => TtyDetect::isAtty(\STDIN));

$run('tty_detect_is_atty_null', static fn (): bool => TtyDetect::isAtty(null));

$run('env_detect_is_console_stdin', static fn (): bool => EnvDetect::isConsoleStdin());

$run('program_run_exec_captured', static function () use ($sink): array {
    $seen    = null;
    $program = new Program(
        new ClosedDescriptorZeroProbeModel(),
        new ProgramOptions(output: $sink, loop: new StreamSelectLoop()),
    );

    // No `input:` above on purpose: the constructor then seeds $this->input
    // from the standard-input constant, which in `closed` mode is exactly the
    // dead handle the guard used to fall back to.
    $request = new ExecRequest(
        'exit 7',
        true,
        static function (int $exit, string $out, string $err, ?\Throwable $error) use (&$seen): ?Msg {
            $seen = [$exit, $error?->getMessage()];

            return null;
        },
    );

    (new \ReflectionMethod(Program::class, 'runExec'))->invoke($program, $request);

    return $seen ?? ['onComplete never ran', null];
});

$run('program_run_exec_passthrough', static function () use ($sink): array {
    $seen    = null;
    $program = new Program(
        new ClosedDescriptorZeroProbeModel(),
        new ProgramOptions(output: $sink, loop: new StreamSelectLoop()),
    );

    // This probe is here for the child's ERROR slot, which the old code wrote
    // as a bare `2 => STDERR` with no guard at all — the one arm of the three
    // that had no fallback even in intent.
    //
    // The child's OUTPUT slot is deliberately NOT exercised here, and the
    // reason is a measurement rather than an oversight: reaching its last
    // resort needs `$this->output` to be dead, and a Program with a dead
    // output cannot get as far as proc_open(). MEASURED on this box, PHP
    // 8.3.6, by doing exactly that through reflection: `TypeError: fwrite():
    // supplied resource is not a valid stream resource` out of the terminal
    // teardown, before runExec() reaches the descriptor array. That arm is
    // pinned one level down instead, as a direct unit test of
    // `Program::childDescriptor()`.
    $request = new ExecRequest(
        'exit 9',
        false,
        static function (int $exit, string $out, string $err, ?\Throwable $error) use (&$seen): ?Msg {
            $seen = [$exit, $error?->getMessage()];

            return null;
        },
    );

    (new \ReflectionMethod(Program::class, 'runExec'))->invoke($program, $request);

    return $seen ?? ['onComplete never ran', null];
});

// Reported so the test can assert the child really was in the state it asked
// for, rather than trusting the mode argument it passed in.
$results['_state'] = [
    'mode'              => $mode,
    'stdin_is_resource' => \is_resource(\STDIN),
    'php_version'       => \PHP_VERSION,
];

file_put_contents($resultFile, json_encode($results, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

if (\is_resource($sink)) {
    fclose($sink);
}
@unlink($sinkPath);

// Never signal failure through the exit code: a nonzero exit would be
// indistinguishable from the child failing to start, and the test needs to
// tell those apart. Everything is in the result file.
exit(0);

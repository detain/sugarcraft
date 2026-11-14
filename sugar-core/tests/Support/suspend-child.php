<?php

declare(strict_types=1);

/**
 * Child-process rig for SuspendResumeTest (sc-10, finding sugar-core-014).
 *
 * Runs a REAL Program on piped streams and prints one `MSG:<class>` line to
 * STDOUT for every Msg the MODEL sees, while all render bytes go to STDERR.
 * The suspend family ends in a genuine SIGTSTP stop of the whole process, so
 * no in-process test could observe it without stopping PHPUnit itself: the
 * parent drives the Ctrl-Z dance from outside and counts markers.
 *
 * argv[1]: 'pass' = no WithFilter installed; 'filter' = a pass-through
 * WithFilter is installed (the stash must reach the model either way).
 *
 * argv[2] (optional): 'no-pcntl' = the parent already disabled every pcntl_*
 * function with -d disable_functions, so the build shape the suspend guard
 * sees is the no-pcntl one.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Job-control precondition for the stop family (CI F16, dated 2026-11-15).
 *
 * The stop under test is a GROUP stop: a SIGTSTP delivered with SIG_DFL
 * disposition halts the process only while its process group is NOT
 * orphaned — POSIX discards group-stop signals (TSTP/TTIN/TTOU) against
 * an orphaned group, and SIGSTOP is the sole exempt stop. GitHub runners
 * execute the suite inside a group whose spawning wrapper is gone: that
 * is precisely an orphan, and the leg reds there with the trace
 * `raise:12s=S kill-pid:9s=S kill-group:9s=S` — reproduced LOCALLY
 * byte-identical by running the suite under `setsid -f` (which orphans
 * phpunit's group before a single test starts). The alternative suspect
 * — the harness inheriting SIG_IGN for TSTP — was measured dead on
 * arrival: Program installs its own handler at run() and suspendProgram
 * re-arms SIG_DFL explicitly, so an inherited ignore is overwritten
 * before any raise; that emu stays green.
 *
 * A real Ctrl-Z never meets an orphan: the job-control shell setpgid's
 * every job, and the shell stays alive in the session OUTSIDE the job's
 * group — that is what makes the group stop legal. The rig restores
 * exactly that precondition for itself: setpgid(0, 0) moves THIS child
 * into a fresh group of one, whose member's parent (the test suite) is
 * alive and in the same session — the non-orphan shape the contract has
 * always assumed. The Program's suspend machinery stays the untouched
 * code under test; this is the terminal environment, not the mechanism.
 *
 * Linux + usable FFI only (the CI class this answers is Linux-runner;
 * PHP has no setpgid() binding, and libc's is three letters of cdef).
 * A child that cannot re-home reports WHY on the marker stream, so a CI
 * red names its own class instead of inviting another round of theory.
 */
$pgidReport = 'pgid=inherited-orphan-risk(ffi-path-unavailable)';
if (\PHP_OS_FAMILY === 'Linux' && \extension_loaded('ffi')) {
    try {
        $libc = \FFI::cdef('int setpgid(int pid, int pgid);', 'libc.so.6');
        $pgidReport = $libc->setpgid(0, 0) === 0
            ? 'pgid=rehomed-to-' . (\function_exists('posix_getpgid') ? (string) \posix_getpgid(0) : 'fresh')
            : 'pgid=SETPGID-FAILED';
    } catch (\Throwable $e) {
        $pgidReport = 'pgid=FFI-ERROR:' . $e->getMessage();
    }
}
\fwrite(STDOUT, 'RIG:' . $pgidReport . "\n");

final class SuspendChildModel implements Model
{
    use SubscriptionCapable;

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        \fwrite(STDOUT, 'MSG:' . $msg::class . "\n");
        if ($msg instanceof KeyMsg && $msg->rune === 'z') {
            return [$this, Cmd::suspend()];
        }
        if ($msg instanceof KeyMsg && $msg->rune === 'q') {
            // The parent closes the run explicitly — the model deliberately
            // survives every ResumeMsg so a duplicate is OBSERVABLE rather
            // than raced away by an immediate quit.
            return [$this, Cmd::quit()];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return 'suspend-child';
    }
}

$loop = new StreamSelectLoop();
$program = new Program(new SuspendChildModel(), new ProgramOptions(
    useAltScreen: false,
    catchInterrupts: true,   // installs the Program's SIGTSTP/SIGCONT handlers
    input: STDIN,
    output: STDERR,
    loop: $loop,
    filter: ($argv[1] ?? 'pass') === 'filter'
        ? static fn (Model $model, Msg $msg): Msg => $msg
        : null,
));
// Rig ceiling so a lost parent can never strand the child past the suite.
// Sized ABOVE the parent waits (30s stop-wait + 30s drain + settle) so host
// load cannot make the child stop ITSELF before the stop cycle is observed.
$loop->addTimer(120.0, static function () use ($loop): void {
    $loop->stop();
});
$program->run();
exit(0);

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Background;

use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Pty\Pty;

/**
 * The relay process that keeps an interactive background shell's pty master
 * alive past the tool call that started it.
 *
 * A plain background shell writes its child's stdio straight into files, so
 * `proc_open` can own the pipes and the wrapper trailer can record the exit.
 * An INTERACTIVE shell has no pipes to own — its stdio is a pseudo-terminal,
 * and a pty master only lives as long as some process holds it open. Every
 * candy-code tool call ends, and the process behind a web request or a TUI
 * turn cannot be the thing that survives to hold a master for an hour-long
 * shell session. So a third process holds it: {@see
 * \SugarCraft\Code\Background\BackgroundShellRegistry::startInteractive()}
 * spawns THIS class as a detached child, and this process opens the pty at
 * the requested size, spawns `bash -c COMMAND` on it with a controlling
 * terminal (sugar-pty's shim — `setsid` + `TIOCSCTTY`, so the shell leads its
 * own session and its process-group id is its pid), then pumps bytes between
 * the master and the file set the row addresses, for as long as the shell
 * lives or a bound says stop.
 *
 * THE PLAIN PATH'S MACHINERY IS DELIBERATELY ABSENT HERE. The recorder
 * trailer of `buildWrapper()` never runs — this process records the exit
 * itself, from its own wait on the child, so there is no `${EXIT%.exit}.out`
 * cat dance and no watchdog-pid env arm. And `ulimit -f` is NOT applied: it
 * fires SIGXFSZ AT THE RELAY (the process holding the file open), killing
 * the keeper instead of the shell and orphaning exactly what it was meant to
 * stop. The cap is instead measured here — every byte read from the master
 * counts, and at `OUTPUT_LIMIT_BLOCKS * 512` the relay kills the child's
 * group and records {@see BackgroundShellRegistry::OUTPUT_LIMIT_EXIT_CODE}
 * (153) itself, so `status()`'s exit-note door narrates the death EXACTLY as
 * the plain recorder path does. Same shape, enforced by the process that
 * survives.
 *
 * THE BOUNDS ARE PROCESS BOUNDS, NOT REQUEST TIMEOUTS (E646). Two of them:
 * the lifetime wall ({@see BackgroundShellRegistry::MAX_LIFETIME_SECONDS},
 * same budget the plain path's watchdog enforces, TERM then KILL on the
 * child's group) and the output cap above. There is no timer that kills user
 * work while the shell is below the wall and below the cap — an interactive
 * session may idle for an hour, and the correct answer to an idle shell is
 * silence, not a signal.
 *
 * THE FILE CONTRACT (all names via {@see BackgroundShellRegistry::pathFor()}
 * — the registry and this process derive them from the same two argv values,
 * one spelling for both sides):
 * - `<id>.cmd`  the staged command text. Read FIRST and unlinked IMMEDIATELY,
 *               before the shell is spawned: no command text ever rides argv
 *               or environ, and a relay that died between stage and unlink
 *               leaves the corpse to the launch sweep.
 * - `<id>.pid`  the pty CHILD's pid (the shell, not this relay), written the
 *               moment the spawn answers. The registry's kill ladder signals
 *               this group as a backstop for shells that ignore SIGHUP; the
 *               relay's own SIGTERM handler covers the fast path.
 * - `<id>.in`   a FIFO carrying keystrokes. Opened `r+` here, so the open
 *               never blocks on a writer and the read side never sees EOF
 *               when the last writer closes — the relay would otherwise spin
 *               readable-forever or mistake a closed writer for a dead shell.
 * - `<id>.out`  everything the master says, appended verbatim. The shell's
 *               stdout AND stderr are the same terminal, so the transcript is
 *               merged by construction; `read()` needs no second cursor.
 * - `<id>.err`  this RELAY's own stderr (display_errors, pty failures) — the
 *               plain path's sink name reused for the process that replaced
 *               the wrapper, so nothing about a shell's files changes shape.
 * - `<id>.exit` written LAST (flush-before-settle, the registry doctrine):
 *               after the transcript is flushed and closed, so a row that
 *               reads a settled exit can trust every byte it precedes.
 *
 * SIGNALS. SIGTERM to this process forwards TERM to the child's group, gives
 * it {@see BackgroundShellRegistry::TERM_GRACE_SECONDS}, then KILLs it — the
 * mirror of the registry's ladder, run by whoever is closest. If the relay is
 * SIGKILLed (unrecoverable), its master fd closes with it, the kernel's
 * master-side refcount hits zero, and `tty_hangup()` SIGHUPs the shell
 * session — a shell that traps or ignores SIGHUP is exactly the risk the
 * recorded `.pid` exists for, and the registry's interactive backstop rung
 * signals that group directly.
 *
 * EXIT-CODE PATHS: the child's own status from `wait()`; 153 on the cap; a
 * wall/TERM death records whatever the killed shell actually exited with
 * (143 after SIGTERM, 137 after SIGKILL, or a trap-and-return code — the
 * shell's choice, honestly recorded). `2` is THIS PROCESS refusing to run at
 * all (bad argv, unstageable command, unopenable sinks) with no exit file,
 * which the registry's `observe()` reads as a dead handle — the never-started
 * shape every other spawn shares.
 *
 * @return int processed by `exit(...)` in the launch `-r` snippet
 */
final class PtyRelay
{
    /** Master→transcript read chunk (sugar-pty's own default; one page of screen). */
    private const READ_CHUNK_BYTES = 8192;

    /**
     * The pump-loop tick: long enough that an idle shell costs twenty wakeups
     * a second and not one busier, short enough that a keystroke reaches the
     * master within a human-imperceptible beat and signals land bounded.
     */
    private const TICK_MICROSECONDS = 50000;

    /**
     * Final-drain discipline: two consecutive quiet reads and the session is
     * over — the twin of {@see \SugarCraft\Code\Support\PtyDriver}'s
     * quiet-drain, kept as a count because the child's exit is what actually
     * ends the loop and this only catches the bytes that raced it.
     */
    private const DRAIN_QUIET_READS = 2;

    /** Hard ceiling on the final drain, so a still-writing child cannot stretch it. */
    private const DRAIN_SECONDS = 1.0;

    /**
     * Run one relay session from the launch argv. Returns the process exit
     * code; the `-r` snippet the registry spawns turns it into the process
     * status. Never throws: every failure path answers 2 (refused-to-run) or
     * a recorded exit file, because a relay that fatalled mid-wait would
     * strand a row as running-until-the-24h-sweep exactly like a corpse.
     */
    public static function main(): int
    {
        $argv = $_SERVER['argv'] ?? [];
        $dir = (string) ($argv[1] ?? '');
        $id = (string) ($argv[2] ?? '');
        $cols = (int) ($argv[3] ?? 0);
        $rows = (int) ($argv[4] ?? 0);
        // The 5th argument is a TEST seam only: the byte cap to enforce. The
        // production launch (startInteractive) passes exactly four values and
        // gets the registry constant; a unit test cannot flood 64 MiB through
        // a pty inside a budget, so it names a small cap and pins the death
        // for real instead of skipping the arm. An unset/invalid value falls
        // back to the production cap — the seam can only narrow, never widen.
        $capBytes = isset($argv[5]) ? (int) $argv[5] : BackgroundShellRegistry::OUTPUT_LIMIT_BYTES;
        if ($capBytes <= 0) {
            $capBytes = BackgroundShellRegistry::OUTPUT_LIMIT_BYTES;
        }

        if (
            !\is_string($id) || \preg_match('/^sh-[0-9a-f]{8}$/', $id) !== 1
            || $dir === '' || !\is_dir($dir)
            || $cols <= 0 || $rows <= 0
        ) {
            \fwrite(\STDERR, "pty relay: refused — bad launch argv\n");

            return 2;
        }

        // Defensive, not the gate: startInteractive() refused on the same
        // checks before it spawned this process. A relay whose host lost
        // pcntl/posix/sugar-pty between refusal and exec has no honest move
        // except to say so and leave the never-started shape behind.
        if (
            !\extension_loaded('pcntl') || !\function_exists('posix_kill')
            || !\class_exists(Pty::class)
        ) {
            \fwrite(\STDERR, "pty relay: refused — pty mechanism unavailable in this process\n");

            return 2;
        }

        $paths = BackgroundShellRegistry::pathFor($dir, $id);

        // Read and UNLINK the staged command before anything else. The file
        // is 0600 in a 0700 launch dir, and it is the only place the command
        // text exists between the refusal-gated launch and now; holding it
        // open past this point would be a second copy of a secret the row
        // does not need.
        $command = @\file_get_contents($paths['cmd']);
        if ($command === false) {
            \fwrite(\STDERR, "pty relay: refused — the staged command file could not be read\n");

            return 2;
        }
        @\unlink($paths['cmd']);

        $childPid = 0;
        $termAt = null;
        $signalledBeforeSpawn = false;

        // Signals arrive while we are in the loop, in the spawn, or never —
        // the handler only records the moment and forwards what it can see;
        // the KILL rung is enforced by the loop's clock, not by a sleep
        // inside the handler (a handler that blocks cannot pump the master,
        // and the transcript belongs to the pump).
        if (!@\pcntl_signal(15, static function () use (&$childPid, &$termAt, &$signalledBeforeSpawn): void {
            if ($childPid > 0) {
                @\posix_kill(-$childPid, 15);
                $termAt = \microtime(true);

                return;
            }
            $signalledBeforeSpawn = true;
        })) {
            \fwrite(\STDERR, "pty relay: refused — SIGTERM could not be armed\n");

            return 2;
        }

        $pty = null;
        try {
            // Open at the requested size, spawn at it too: the winsize is on
            // the master BEFORE the shell's first prompt draws (sugar-pty's
            // pre-spawn resize; the post-spawn best-effort re-assert covers
            // the Darwin zeroing quirk). `bash` with the controlling terminal
            // wrapped means the shim's setsid'd child LEADS its own session —
            // kill(-pid) reaches the shell and every job it forks. A null env
            // inherits the relay's environment WHOLESALE: startInteractive()
            // scrubbed it already, so the shell runs fail-fast and
            // credential-free without a second scrub here.
            //
            // The shell is spelled ABSOLUTE because the controlling-terminal
            // shim runs `pcntl_exec()` raw — no PATH search — and sugar-pty
            // hands it the first argv element verbatim (`pty-shim.php:172`;
            // ProcessContainment's own interactive path spells `/bin/sh` for
            // the same reason). locateOnPath() is the house PATH probe; the
            // '/bin/bash' literal is the fail-closed fallback when PATH has
            // no bash but the canonical location does.
            $pty = Pty::open($cols, $rows);
            $shell = ProcessContainment::locateOnPath('bash');
            if ($shell === '') {
                $shell = '/bin/bash';
            }
            $child = $pty->spawn([$shell, '-c', $command], null, $cols, $rows, true);
            $childPid = $child->pid();
        } catch (\Throwable $failure) {
            if ($pty !== null) {
                try {
                    $pty->close();
                } catch (\Throwable) {
                    // The refusal below is the answer either way.
                }
            }
            \fwrite(\STDERR, 'pty relay: the pty or the shell could not be started: ' . $failure->getMessage() . "\n");

            return 2;
        }

        // A TERM that landed during the spawn must not be lost: forward now.
        if ($signalledBeforeSpawn) {
            @\posix_kill(-$childPid, 15);
            $termAt = \microtime(true);
        }

        // The registry's handle on the group we must not orphan. Write before
        // the first pump tick so there is no window where a stopped shell has
        // a recorded relay but no recorded child.
        @\file_put_contents($paths['ptyPid'], (string) $childPid);

        $out = @\fopen($paths['stdout'], 'ab');
        $fifo = @\fopen($paths['in'], 'r+');
        if ($out === false || $fifo === false) {
            @\posix_kill(-$childPid, 9);
            if ($out !== false) {
                @\fclose($out);
            }
            if ($fifo !== false) {
                @\fclose($fifo);
            }
            if ($pty !== null) {
                try {
                    $pty->close();
                } catch (\Throwable) {
                }
            }
            \fwrite(\STDERR, "pty relay: the transcript sinks could not be opened\n");

            return 2;
        }
        @\stream_set_blocking($fifo, false);

        $written = (int) (@\filesize($paths['stdout']) ?: 0);
        $startedAt = \microtime(true);
        $wallAt = $startedAt + BackgroundShellRegistry::MAX_LIFETIME_SECONDS;
        $capped = false;
        $wallFired = false;
        $killSent = false;
        $master = $pty->stream();

        while (true) {
            \pcntl_signal_dispatch();

            $read = [$master, $fifo];
            $write = null;
            $except = null;
            if (@\stream_select($read, $write, $except, 0, self::TICK_MICROSECONDS) === false) {
                // EINTR from the signal handler we just dispatched, or a gone
                // fd — the clocks below and the exited() check settle either.
                $read = [];
            }

            foreach ($read as $fd) {
                if ($fd === $master) {
                    $chunk = $pty->read(self::READ_CHUNK_BYTES, 0.0);
                    if ($chunk !== null && $chunk !== '') {
                        @\fwrite($out, $chunk);
                        $written += \strlen($chunk);
                        if (!$capped && $written >= $capBytes) {
                            // The cap is the plain path's SIGXFSZ with the
                            // judgement call moved inside: kill the GROUP,
                            // record 153, and let the same note door narrate.
                            $capped = true;
                            @\posix_kill(-$childPid, 9);
                        }
                    }
                } else {
                    $in = @\fread($fifo, self::READ_CHUNK_BYTES);
                    if (\is_string($in) && $in !== '' && !$child->exited()) {
                        try {
                            $pty->write($in);
                        } catch (\Throwable) {
                            // The master died under us (the shell beat us to
                            // exit); the loop notices via exited() below.
                        }
                    }
                }
            }

            if ($termAt !== null && !$killSent && \microtime(true) >= $termAt + BackgroundShellRegistry::TERM_GRACE_SECONDS) {
                @\posix_kill(-$childPid, 9);
                $killSent = true;
            }

            if (!$wallFired && !$capped && \microtime(true) >= $wallAt) {
                $wallFired = true;
                @\posix_kill(-$childPid, 15);
                $termAt ??= \microtime(true);
            }

            if ($child->exited()) {
                break;
            }
        }

        // The child's exit races its last frame: pull the master until it
        // answers quiet twice, time-capped, so `exit`'s own prompt tail rides
        // the transcript before the settle.
        $quiet = 0;
        $drainEnd = \microtime(true) + self::DRAIN_SECONDS;
        while ($quiet < self::DRAIN_QUIET_READS && \microtime(true) < $drainEnd) {
            $chunk = $pty->read(self::READ_CHUNK_BYTES, 0.05);
            if ($chunk === null || $chunk === '') {
                $quiet++;
                @\usleep(10000);

                continue;
            }
            @\fwrite($out, $chunk);
            $quiet = 0;
        }

        @\fflush($out);
        @\fclose($out);
        @\fclose($fifo);

        // Flush-before-settle: the exit file goes last, and the code is the
        // shell's own wait answer — except on the cap, which is OUR death
        // sentence (153, the plain path's SIGXFSZ number, narrated by the
        // registry's exitNote door for both shapes). `wait()` is safe here:
        // exited() just proved the child gone, the one condition sugar-pty's
        // unbounded poll documents itself for.
        $code = $capped
            ? BackgroundShellRegistry::OUTPUT_LIMIT_EXIT_CODE
            : $child->wait();
        @\file_put_contents($paths['exit'], (string) $code);

        try {
            $pty->close();
        } catch (\Throwable) {
            // Nothing left to guard: the transcript and exit are settled and
            // the kernel reclaims the master with this process.
        }

        return 0;
    }
}

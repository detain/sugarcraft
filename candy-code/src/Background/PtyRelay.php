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
     * The timeout handed to {@see \SugarCraft\Pty\Pty::read()} AFTER select
     * already reported the master readable. read()'s deadline arithmetic
     * answers null for a 0.0 timeout without looking (deadline == now is
     * "expired"), so the loop needs a hair of a real ceiling — the pending
     * chunk answers instantly, and the ceiling only bites if the data was
     * consumed from under us.
     */
    private const POST_SELECT_READ_SECONDS = 0.05;

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

        // THE FORK IS THE CONTROLLING-TERMINAL CONTRACT, not decoration.
        // The launch arrives here as a setsid-wrapped child (spawnSpec), so
        // this process LEADS a fresh session with no controlling terminal —
        // and a session leader that opens a tty slave without O_NOCTTY is
        // handed that terminal as its ctty BY THE KERNEL. sugar-pty's spawn
        // opens the slave here, in us, before forking the shim; the shim's
        // own setsid()+TIOCSCTTY(0) claim would then fail — arg zero never
        // steals a terminal already bound to another session (measured:
        // claim fails with errno EPERM for exactly this shape, while the
        // same spawn from a session MEMBER claims clean, which is why
        // PtyDriver/runCapturedInteractive work in-process). So the pty
        // opener must NOT be a session leader: fork, and let the child — a
        // member of this session and group — run the pump below. The parent
        // keeps the setsid lifecycle intact: it is the signal target the
        // wrapper forwards to, it reaps the pump, and it mirrors the pump's
        // exit status for proc_close. Group signals from the kill ladder
        // still reach BOTH members of this group; the pump's own handler
        // below stays load-bearing for those.
        if (!\function_exists('pcntl_fork')) {
            \fwrite(\STDERR, "pty relay: refused — pcntl_fork is unavailable, the pty could not be claimed\n");

            return 2;
        }
        $pump = @\pcntl_fork();
        if ($pump === -1) {
            \fwrite(\STDERR, "pty relay: refused — the pump could not be forked\n");

            return 2;
        }
        if ($pump > 0) {
            return self::supervise($pump);
        }

        $childPid = 0;
        $termAt = null;
        $signalledBeforeSpawn = false;
        // Pump-side twin of supervise()'s patrol: if the forwarding relay
        // (our parent) dies, single-pid TERMs stop reaching us and only the
        // group rungs and this clock remain. Watch for the reparent and take
        // over the ladder through the same termAt path the handler uses.
        $relayParent = \function_exists('posix_getppid') ? \posix_getppid() : 0;

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

            // Parent-patrol twin of supervise()'s: a KILL that took the
            // relay parent without the pump (or a fork-parent reaper race)
            // must still end the shell — TERM the child's group, and the
            // existing escalation ladder turns it into KILL after the
            // grace. The pump cannot receive the registry's signals once
            // its parent is gone; the ppid change is the only notice.
            if ($relayParent > 0 && \posix_getppid() !== $relayParent && $termAt === null && $childPid > 0) {
                @\posix_kill(-$childPid, 15);
                $termAt = \microtime(true);
            }

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
                    // The deadline semantics of read(): timeout 0.0 means
                    // "already expired" and answers null WITHOUT looking —
                    // select above already proved a chunk is pending, so a
                    // hair of real timeout just lets its fread run.
                    $chunk = $pty->read(self::READ_CHUNK_BYTES, self::POST_SELECT_READ_SECONDS);
                    if ($chunk !== null && $chunk !== '') {
                        @\fwrite($out, $chunk);
                        // Flush per chunk: a buffered transcript would stay
                        // invisible until settle — read() slices the FILE,
                        // sinkCapped() sizes the FILE, and a live session's
                        // whole point is seeing output WHILE the shell runs.
                        @\fflush($out);
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

    /**
     * The parent's whole job: stay alive as the session-and-group anchor the
     * kill ladder signals, forward one TERM to the pump, reap it, and mirror
     * its exit. It opens NO pty and NO sinks — those belong to the pump, the
     * session member whose slave-open must not auto-claim the terminal (see
     * main()). If the parent is killed outright the pump is reparented and
     * keeps pumping (its own handler and the registry's recorded-child
     * backstop still cover teardown); the arrangement optimises the normal
     * ladder path, it does not depend on the parent for anything load-bearing.
     */
    private static function supervise(int $pumpPid): int
    {
        if (!@\pcntl_signal(15, static function () use ($pumpPid): void {
            @\posix_kill($pumpPid, 15);
        })) {
            // No handler, no forwarding — the pump still answers to the
            // group rungs and the recorded-child backstop; say so loudly.
            \fwrite(\STDERR, "pty relay: SIGTERM forwarding could not be armed; the pump relies on group signals\n");
        }

        $status = 0;
        $reaped = false;
        // Parent patrol: this process's own parent is the setsid wrapper the
        // registry signals, and the KILL rung of a kill ladder is not
        // forwardable — if that wrapper dies, no future signal reaches us,
        // so the pump (and the shell behind it) would leak until the wall.
        // On becoming an orphan we run the wrapper's remaining job ourselves:
        // one more TERM, the grace, then KILL.
        $wrapperPid = \function_exists('posix_getppid') ? \posix_getppid() : 0;
        $orphanedAt = null;
        while (true) {
            $gone = @\pcntl_waitpid($pumpPid, $status, WNOHANG | WUNTRACED);
            if ($gone === $pumpPid) {
                $reaped = true;

                break;
            }
            if ($gone === -1) {
                // ECHILD (already reaped elsewhere) or a fatal wait error:
                // the pump is not ours to report on any more.
                break;
            }
            \pcntl_signal_dispatch();
            if ($wrapperPid > 0 && \posix_getppid() !== $wrapperPid) {
                if ($orphanedAt === null) {
                    $orphanedAt = \microtime(true);
                    @\posix_kill($pumpPid, 15);
                } elseif (\microtime(true) >= $orphanedAt + BackgroundShellRegistry::TERM_GRACE_SECONDS) {
                    @\posix_kill($pumpPid, 9);
                }
            }
            @\usleep(20000);
        }
        \pcntl_signal_dispatch();

        if (!$reaped) {
            // Never got the pump's own status: a surprise, say so in the
            // exit shape rather than mirroring a code we never observed.
            return 2;
        }

        if (WIFSIGNALED($status)) {
            return 128 + WTERMSIG($status);
        }

        return WIFEXITED($status) ? WEXITSTATUS($status) : 2;
    }
}

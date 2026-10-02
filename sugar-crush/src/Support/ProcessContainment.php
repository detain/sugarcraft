<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * THE ONE CONTAINMENT CHOKE POINT for every `proc_open()` in this package.
 *
 * Three questions, answered once, so every spawn site answers them the same:
 *
 *  1. WHAT does the child exec? — {@see spawnSpec()} wraps any command in a
 *     proven `setsid -w` so the child starts a NEW SESSION with no
 *     controlling terminal. `/dev/tty` then fails at OPEN (ENXIO), which is
 *     the only lever that reaches sudo/ssh/credential prompts/pagers: they
 *     refuse loudly onto the captured stderr instead of painting over the
 *     TUI frame or hanging on a prompt no human can see. E672 routed the
 *     remaining sites (hooks, workers, MCP/LSP servers, backends, providers,
 *     commands, status line) here; {@see \SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput}
 *     (Phase 9 layer A) was the first consumer and still is the exemplar.
 *
 *  2. WITH WHAT ENVIRONMENT? — {@see env()} copies the inherited block,
 *     forces {@see NONINTERACTIVE_ENV} over it and removes
 *     {@see STRIPPED_ENV_NAMES}. GPG_TTY joined the strip list under E674:
 *     detach removes the CONTROLLING terminal but a stale GPG_TTY is a PATH
 *     opened by name, so a child of a TUI could still `open("/dev/pts/N")`
 *     onto the user's real terminal and scribble outside the frame — or
 *     block on a pinentry the human never sees. An unset GPG_TTY costs gpg
 *     a loopback/askpass fallback, which is the loud refusal containment
 *     asks for; an inherited one is a tty handle containment cannot revoke.
 *
 *  3. HOW DOES IT DIE? — {@see terminate()} kills the child's WHOLE process
 *     GROUP when the child leads one (the case for every setsid-wrapped
 *     spawn: the wrapper is the session leader and the real command runs in
 *     its group). Without this, detaching would be a leak dressed as a fix:
 *     `proc_terminate()` reaches only the wrapper's pid, and a wrapper that
 *     has already exec'd past signal forwarding — or is SIGKILLed itself,
 *     which cannot be forwarded — would orphan the grandchild with the
 *     terminal removed from its reach. E673: a container terminate must
 *     reach descendants; {@see groupId()} is the safe discriminator (it
 *     answers non-null ONLY when the child's pgid equals its own pid, so a
 *     group-kill can never fire against the PHP process's own group).
 *
 * NOTHING HERE SPAWNS ON THE TTY-PATH FALLBACK. When no usable `setsid(1)`
 * exists (macOS ships none) {@see spawnSpec()} returns the command
 * unchanged and {@see env()} still applies — fail-fast half, attached
 * spawn, exactly the fallback CapturesProcessOutput measured. {@see
 * terminate()} then simply finds no owned group and falls back to
 * `proc_terminate()`, which is the pre-containment behaviour — correct for
 * an unwrapped child, because without the wrapper the direct child IS the
 * command.
 */
final class ProcessContainment
{
    /**
     * Fail-fast environment forced on every child this package spawns. These
     * are not askpass (the askpass route was rejected as a credential-entry
     * surface driven by model output) — they are how a command refuses LOUDLY
     * on the captured stderr instead of hanging on an invisible prompt.
     *
     * GIT_TERMINAL_PROMPT=0: git's own switch for refusing terminal prompts.
     * GIT_ASKPASS=/bin/false: an executed /bin/false fails every credential
     * prompt as a refusal git explains on stderr; an EMPTY value would point
     * git at the default askpass path and, where one exists, feed it an empty
     * secret — a wrong-credential ATTEMPT where a refusal was asked for.
     * SSH_ASKPASS=echo (not /bin/false, because ssh 3-times a succeeding
     * askprogram before dying "Permission denied" and an EMPTY reply is the
     * legible outcome there): with no controlling terminal, OpenSSH >= 8.4
     * uses the askprogram at all, so pinning it pins the refusal path.
     * DEBIAN_FRONTEND=noninteractive: apt's config/overwrite prompts.
     * PAGER / GIT_PAGER: kill the pager class of hang.
     * SYSTEMD_PAGER=/bin/cat — not the documented EMPTY spelling, because
     * PHP's proc_open env array drops empty-string values, and a bare UNSET
     * is worse than the hang it prevents: systemctl then falls back to its
     * built-in `less`. /bin/cat streams and exits, and unlike empty it also
     * defeats a user-exported SYSTEMD_PAGER=less rather than reviving it.
     */
    public const NONINTERACTIVE_ENV = [
        'GIT_TERMINAL_PROMPT' => '0',
        'GIT_ASKPASS' => '/bin/false',
        'SSH_ASKPASS' => 'echo',
        'DEBIAN_FRONTEND' => 'noninteractive',
        'PAGER' => 'cat',
        'GIT_PAGER' => 'cat',
        'SYSTEMD_PAGER' => '/bin/cat',
    ];

    /**
     * Inherited names REMOVED from every child environment. SUDO_ASKPASS per
     * the plan's option (A) ("unset + sudo -n"): a user-wide askpass helper
     * must not be handed to a child that has no terminal to refuse it on.
     * GPG_TTY added under E674 — see the class docblock, question 2: a stale
     * GPG_TTY path can be opened BY NAME from outside a session, which is
     * precisely the channel detach cannot revoke.
     */
    public const STRIPPED_ENV_NAMES = ['SUDO_ASKPASS', 'GPG_TTY'];

    /**
     * Path of a `setsid(1)` proven to forward its child's exit status
     * through `-w`, or '' when this host offers no usable detach.
     *
     * The probe RUNS the claim it makes — `setsid -w -- /bin/sh -c 'exit
     * 7'` must come back 7 — because the two real failure modes are silent:
     * a binary predating `-w` (or a busybox lacking it) execs, the wrapper
     * exits 0 immediately, and every wrapped spawn on the box reports
     * success for commands that failed. Trading a hang for a lie is not
     * containment. Located by a stat walk, never by spawning `setsid`
     * itself when it may be missing (PHP warns on a missing proc_open
     * target and phpunit.xml sets failOnWarning).
     *
     * Memoized PER PROCESS now: the probe used to run once per trait-host
     * class; with one choke point the honest question ("does THIS host have
     * a usable setsid?") has one answer per process, so the memo moved to
     * class scope — which only a Support class may declare (the trait hosts
     * are `final readonly` and PHP refuses a readonly class composing a
     * trait that declares any property).
     */
    public static function detachedSpawnBinary(): string
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return $resolved = '';
        }

        $path = self::locateOnPath('setsid');
        if ($path === '') {
            return $resolved = '';
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $probe = @\proc_open([$path, '-w', '--', '/bin/sh', '-c', 'exit 7'], $descriptors, $pipes);
        if (!\is_resource($probe)) {
            return $resolved = '';
        }
        \fclose($pipes[0]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return $resolved = \proc_close($probe) === 7 ? $path : '';
    }

    /**
     * The command to hand `proc_open()`: a `setsid -w` wrapped spec when the
     * host offers a usable detach, the original command otherwise.
     *
     * Array commands wrap as `[$setsid, '-w', '--', ...$argv]` — direct
     * exec, no shell, PHP's own argument escaping intact. String commands
     * wrap as `[$setsid, '-w', '--', '/bin/sh', '-c', $command]`, which is
     * exactly the shell PHP's string form runs. `-w` keeps `proc_close()`
     * reporting the COMMAND's status, not the wrapper's, and forwards
     * received TERM/INT to the child (the kernel-proof floor for signal
     * delivery stays {@see terminate()}'s group kill).
     */
    public static function spawnSpec(string|array $command): string|array
    {
        $setsid = self::detachedSpawnBinary();
        if ($setsid === '') {
            return $command;
        }

        return \is_array($command)
            ? [$setsid, '-w', '--', ...\array_values($command)]
            : [$setsid, '-w', '--', '/bin/sh', '-c', $command];
    }

    /**
     * The inherited environment with {@see NONINTERACTIVE_ENV} forced over
     * it and {@see STRIPPED_ENV_NAMES} removed, then $overrides applied last
     * so a site's own required keys (API tokens, hook context) still win.
     *
     * Built from a getenv() COPY, not a replacement: proc_open's $env is the
     * child's WHOLE environment, so a hand-rolled array would strip PATH
     * (every `bash -c` lookup dies) and HOME (git refuses to find its
     * config). Rebuilt per call, never cached: putenv() in this process —
     * tests and supervisors do it — must reach the next child, not be frozen
     * out by a stale snapshot.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    public static function env(array $overrides = []): array
    {
        $env = \getenv();
        foreach (self::STRIPPED_ENV_NAMES as $name) {
            unset($env[$name]);
        }

        return \array_merge($env, self::NONINTERACTIVE_ENV, $overrides);
    }

    /**
     * The process-group id a containment-aware kill should target, or null
     * when the child does not lead its own group.
     *
     * A setsid-wrapped child IS the session (and process-group) leader, and
     * everything the command forks runs in that group, so `kill(-pgid)`
     * reaches wrapper and grandchild alike. An UNWRAPPED child inherits the
     * PHP process's group — answering null here — and firing a group kill
     * would signal this very process. That is why the discriminator is
     * measured from the kernel (`posix_getpgid(child) === child`) rather
     * than tracked from the wrapper: a stale bookkeeping flag is how a
     * containment mechanism grows into a self-kill.
     *
     * @param resource|mixed $process the value a proc_open() call returned
     */
    public static function groupId(mixed $process): ?int
    {
        if (!\is_resource($process) || !\function_exists('posix_getpgid')) {
            return null;
        }

        $status = \proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);
        if ($pid <= 0) {
            return null;
        }

        return \posix_getpgid($pid) === $pid ? $pid : null;
    }

    /**
     * Signal a (possibly wrapped) child in a way that reaches its
     * descendants: group kill when the child leads a group,
     * `proc_terminate()` otherwise.
     *
     * Signals are INTEGER LITERALS, never the SIGTERM/SIGKILL constants:
     * those come from ext-pcntl and this runs on teardown paths that must
     * not themselves fatal on a build without it (same rule as
     * {@see ProcessReaper}).
     */
    public static function terminate(mixed $process, int $signal = 15): bool
    {
        if (!\is_resource($process)) {
            return false;
        }

        $groupId = self::groupId($process);
        if ($groupId !== null && \function_exists('posix_kill') && @\posix_kill(-$groupId, $signal)) {
            return true;
        }

        return \proc_terminate($process, $signal);
    }

    /**
     * The memo behind {@see interactiveAvailable()} — null until the first
     * probe, then the verdict for the life of the process (or until the
     * testing reset clears it).
     */
    private static ?bool $interactiveAvailableMemo = null;

    /**
     * Whether this host can run a child attached to a REAL pty — the gate
     * the layer-C opt-in consults before promising anything.
     *
     * An `interactive: true` request on a host that cannot honour it (no
     * ext-ffi, /dev/ptmx sealed, Windows, or candy-pty simply absent) is
     * refused, not deferred: a mode that silently degrades to a pipe would
     * hand the model a tty-shaped lie. Every check is a STAT, never a
     * spawn — same discipline as {@see locateOnPath()} answering "might the
     * binary be missing", because a probe that opened a pty to prove pty
     * availability would leak descriptors on exactly the failure paths this
     * method exists to survive. The class_exists check is the vendor-closure
     * half: sugar-crush requires candy-pty, but a trimmed install or a
     * mislinked vendor must answer "unavailable", not fatal mid-tool.
     * Memoized per process like the setsid probe — the honest question is
     * "can this host", and that answer does not change within a process.
     * The memo lives at class scope (not a function-local `static`) so
     * {@see resetInteractiveAvailabilityForTesting()} can clear it: the
     * promise is that the ANSWER does not change, not that a test may never
     * re-ask — pinning both shapes of the gate (spawn and refusal) on one
     * host requires a re-derive the production memo would refuse.
     */
    public static function interactiveAvailable(): bool
    {
        if (self::$interactiveAvailableMemo !== null) {
            return self::$interactiveAvailableMemo;
        }

        if (PHP_OS_FAMILY === 'Windows' || !\extension_loaded('ffi')) {
            return self::$interactiveAvailableMemo = false;
        }

        if (!@\is_readable('/dev/ptmx') || !@\is_writable('/dev/ptmx')) {
            return self::$interactiveAvailableMemo = false;
        }

        return self::$interactiveAvailableMemo = \class_exists(\SugarCraft\Pty\Pty::class);
    }

    /**
     * Discard the memoized host verdict so the next
     * {@see interactiveAvailable()} re-derives it from the same STATs.
     * Testing seam only — production callers never reset a per-process
     * fact mid-run; the reset exists so one host can pin both the
     * interactive-spawn branch and the 126-refusal branch instead of
     * leaving whichever the box does not match as dead code.
     */
    public static function resetInteractiveAvailabilityForTesting(): void
    {
        self::$interactiveAvailableMemo = null;
    }

    /**
     * The argv for a pty spawn: $command through /bin/sh, entered in $cwd
     * when one is given.
     *
     * There is deliberately NO setsid wrapper here, and that is not a
     * containment gap: layer A's detach exists to REMOVE the controlling
     * terminal, while the entire point of an interactive run is that the
     * child HAS one — the pty this app allocated and captures from, never
     * the user's screen. Wrapping with setsid would opt in and then defeat
     * the opt-in. The fail-fast env ({@see env()}) still applies verbatim:
     * interactive means "gets a terminal", never "accepts secrets" — the
     * standing no-askpass design rule says the pty takes no password at
     * all, so GIT_TERMINAL_PROMPT/GIT_ASKPASS/SSH_ASKPASS forcing and the
     * SUDO_ASKPASS/GPG_TTY strips ride both paths identically.
     *
     * @return non-empty-list<string>
     */
    public static function interactiveSpawnCommand(string $command, ?string $cwd = null): array
    {
        $script = ($cwd === null || $cwd === '' ? '' : 'cd ' . \escapeshellarg($cwd) . ' && ') . $command;

        return ['/bin/sh', '-c', $script];
    }

    /**
     * Signal a child by PID, reaching its group when it leads one and
     * itself otherwise — the proc_open-free twin of {@see terminate()}.
     *
     * The pty path holds a candy-pty handle, not a proc_open resource, so
     * there is nothing for terminate()'s is_resource gate to accept. The
     * group-first order still matters: the shell is not the program, and
     * killing only the shell would leave the actual child painting on a
     * terminal nobody reads anymore. Signals stay INTEGER LITERALS for the
     * same ext-pcntl-free teardown reason as terminate().
     */
    public static function terminatePid(int $pid, int $signal = 15): bool
    {
        if ($pid <= 0 || !\function_exists('posix_kill')) {
            return false;
        }

        if (\function_exists('posix_getpgid') && \posix_getpgid($pid) === $pid && @\posix_kill(-$pid, $signal)) {
            return true;
        }

        return @\posix_kill($pid, $signal);
    }

    /**
     * Upper bound on {@see killTree()}'s freeze walk: passes × poll is a
     * ~250 ms ceiling on how long a teardown may sit on the caller's thread
     * (the TUI's event loop, for the Escape-Escape path) while a tree that
     * will not hold still is chased. A quiet tree settles in two passes.
     */
    private const TREE_FREEZE_MAX_PASSES = 50;

    private const TREE_FREEZE_POLL_US = 5000;

    /**
     * KILL $pid AND EVERYTHING IT STARTED — the public group-kill entry point
     * for any site that tears down a forked child of this package (audit
     * B2/F-E2: EngineBackend's turn teardown, Runtime's parallel deadline; the
     * dormant Chat kill site and the custom-command `!` runner are meant to
     * call this too).
     *
     * WHY `posix_kill($pid, 9)` WAS NOT ENOUGH. Every command a tool runs is
     * `setsid -w`-wrapped ({@see spawnSpec()}), so it leads its OWN session and
     * process group. SIGKILLing the forked PHP child that ran it leaves
     * `/bin/sh -c …` and its children reparented to init, still running — a
     * cancelled `rm` loop, migration or `git push` finished anyway. And a
     * parallel Task sub-agent is a fork BELOW the killed child, exempt from the
     * group deadline, so it carried on (with its own Bash groups and parallel
     * forks) until its next progress check noticed the parent was gone.
     *
     * FREEZE, WALK, KILL — in that order, because the order is what makes the
     * walk sound:
     *  1. SIGSTOP the root, then repeatedly scan /proc for its descendants and
     *     SIGSTOP each new one, until two consecutive passes find nothing new
     *     and every collected process is stopped (or already dead). A stopped
     *     process cannot fork, so the set stops growing; and while the root is
     *     alive its children stay ITS children — once it dies they reparent to
     *     init and the tree can no longer be recovered. So the walk always
     *     runs BEFORE any kill. Bounded ({@see TREE_FREEZE_MAX_PASSES}): a
     *     process stuck in an uninterruptible wait cannot stop, and the kill
     *     below still goes out.
     *  2. For every distinct process group a collected member is in, signal
     *     the whole group — this reaches a setsid'd command's members that
     *     already left the tree (a backgrounded `&` job whose shell exited).
     *     NEVER the caller's own group: forks of this process inherit its
     *     pgrp (the TUI's), so those get per-pid kills only.
     *  3. Signal every collected pid and the root individually.
     *
     * $termGraceSeconds > 0 sends 15 first (with SIGCONT, so a stopped process
     * can act on it), waits up to the grace for the set to go, then sends 9 to
     * whatever is left. The default 0.0 is straight to 9, which is what both
     * live callers want: they were already SIGKILLing the root, and a stopped
     * tree has nothing left to do but die.
     *
     * Does NOT reap: the root stays the caller's to `waitpid()` (EngineBackend's
     * bounded reapChild(), Runtime's reapKilled()); descendants are reparented
     * to init by the kill and reaped there.
     *
     * LINUX-ONLY TREE HALF. Without a readable /proc ({@see ProcessTree}) or
     * without ext-posix's getpgrp this degrades to exactly the pre-fix
     * behaviour, `posix_kill($pid, 9)` on the root alone.
     *
     * Refuses $pid <= 0 and the caller's own pid (a kill(0)/kill(-1)/self-kill
     * through this method is never what a teardown meant).
     */
    public static function killTree(int $pid, float $termGraceSeconds = 0.0): void
    {
        if ($pid <= 0 || !\function_exists('posix_kill')) {
            return;
        }
        $self = \function_exists('posix_getpid') ? \posix_getpid() : \getmypid();
        if ($pid === $self) {
            return;
        }

        if (!\function_exists('posix_getpgrp') || !ProcessTree::available()) {
            if ($termGraceSeconds <= 0.0) {
                @\posix_kill($pid, 9);
            } else {
                ProcessReaper::escalate(
                    static function (int $signal) use ($pid): void {
                        @\posix_kill($pid, $signal);
                    },
                    static fn(): bool => !self::alive($pid),
                    $termGraceSeconds,
                );
            }

            return;
        }

        // SIGSTOP/SIGCONT numbers differ by architecture (19/18 on x86 and
        // arm, 17/19 on some others), unlike 9 and 15, so they come from
        // ext-pcntl when it is loaded and fall back to the Linux x86/arm
        // values only when it is not.
        $stop = \defined('SIGSTOP') ? \SIGSTOP : 19;
        $cont = \defined('SIGCONT') ? \SIGCONT : 18;
        $ownGroup = \posix_getpgrp();

        $members = self::freezeTree($pid, $self, $stop);

        $groups = [];
        foreach ($members as $member) {
            $stat = ProcessTree::stat($member);
            if ($stat !== null && $stat['pgid'] > 1 && $stat['pgid'] !== $ownGroup) {
                $groups[$stat['pgid']] = true;
            }
        }
        $groups = \array_keys($groups);

        $deliver = static function (int $signal) use ($groups, $members, $cont): void {
            foreach ($groups as $group) {
                @\posix_kill(-$group, $signal);
            }
            foreach ($members as $member) {
                @\posix_kill($member, $signal);
            }
            if ($signal !== 9) {
                foreach ($groups as $group) {
                    @\posix_kill(-$group, $cont);
                }
                foreach ($members as $member) {
                    @\posix_kill($member, $cont);
                }
            }
        };

        if ($termGraceSeconds <= 0.0) {
            $deliver(9);

            return;
        }

        ProcessReaper::escalate(
            $deliver,
            static function () use ($members): bool {
                foreach ($members as $member) {
                    if (self::alive($member)) {
                        return false;
                    }
                }

                return true;
            },
            $termGraceSeconds,
        );
    }

    /**
     * Step 1 of {@see killTree()}: stop $root and every descendant until the
     * set holds still, returning root + descendants (never $self).
     *
     * @return list<int>
     */
    private static function freezeTree(int $root, int $self, int $stop): array
    {
        @\posix_kill($root, $stop);
        $members = [$root => true];
        $stablePasses = 0;

        for ($pass = 0; $pass < self::TREE_FREEZE_MAX_PASSES; $pass++) {
            $snapshot = ProcessTree::snapshot() ?? [];
            $grew = false;
            foreach (ProcessTree::descendants($root, $snapshot) as $descendant) {
                if ($descendant === $self || isset($members[$descendant])) {
                    continue;
                }
                @\posix_kill($descendant, $stop);
                $members[$descendant] = true;
                $grew = true;
            }

            $allHeld = true;
            foreach (\array_keys($members) as $member) {
                $state = $snapshot[$member]['state'] ?? 'X';
                // T stopped, t tracing-stop, Z zombie, X dead/absent: none of
                // these can fork again.
                if (!\in_array($state, ['T', 't', 'Z', 'X'], true)) {
                    $allHeld = false;

                    break;
                }
            }

            // TWO clean passes, not one: a scan is not atomic, so a member
            // that forked after the directory listing and stopped before its
            // own stat was read looks "held" with a child the listing missed.
            // A second pass that starts after everything was seen stopped
            // cannot miss anything — nothing in the set can fork any more.
            $stablePasses = (!$grew && $allHeld) ? $stablePasses + 1 : 0;
            if ($stablePasses >= 2) {
                break;
            }

            \usleep(self::TREE_FREEZE_POLL_US);
        }

        return \array_keys($members);
    }

    /**
     * Whether $pid still names a running (non-zombie) process.
     */
    private static function alive(int $pid): bool
    {
        $stat = ProcessTree::stat($pid);
        if ($stat !== null) {
            return $stat['state'] !== 'Z' && $stat['state'] !== 'X';
        }

        return !ProcessTree::available() && @\posix_kill($pid, 0);
    }

    /**
     * Mark $stream's descriptor FD_CLOEXEC so no command spawned afterwards
     * inherits it; true when the flag is now set.
     *
     * WHY THIS IS NEEDED: proc_open() only marks its OWN pipe ends
     * close-on-exec. A stream_socket_pair() end, a plain fopen() handle — any
     * other descriptor this process holds — walks into every child it
     * spawns, and a child holding a socket's write end keeps the reader from
     * ever seeing EOF (audit B3: a backgrounded `sleep` held a turn's frame
     * socket open until it exited). PHP exposes neither the fd number nor
     * fcntl() for a stream, so the fd is found by matching the stream's
     * dev+ino against every `/proc/self/fd/N`, and the flag is set through
     * FFI's libc `fcntl(F_SETFD, FD_CLOEXEC)`.
     *
     * Best effort by contract and SILENT on every miss: no /proc, no ext-ffi,
     * FFI disabled by `ffi.enable`, no matching fd — each answers false and
     * changes nothing, because this runs on fork and spawn paths where a
     * warning (phpunit's failOnWarning) or a fatal would be worse than the
     * leak. It replaces the descriptor's flags with FD_CLOEXEC alone, which
     * is safe because FD_CLOEXEC is the only fd flag Linux defines.
     *
     * Only an exec boundary honours the flag: a pcntl_fork() child still
     * inherits the descriptor, so a caller that must notice a forked holder
     * dying needs its own liveness check (EngineBackend polls the pid).
     *
     * @param resource|mixed $stream
     */
    public static function closeOnExec(mixed $stream): bool
    {
        if (!\is_resource($stream) || !\extension_loaded('ffi') || !\is_dir('/proc/self/fd')) {
            return false;
        }

        $fd = self::descriptorNumber($stream);
        if ($fd === null) {
            return false;
        }

        try {
            $libc = \FFI::cdef('int fcntl(int fd, int cmd, ...);');

            // 2 = F_SETFD, 1 = FD_CLOEXEC on every Linux ABI.
            return $libc->fcntl($fd, 2, 1) === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The fd number behind $stream, found by dev+ino match in
     * `/proc/self/fd`, or null when no single entry matches.
     *
     * @param resource $stream
     */
    private static function descriptorNumber($stream): ?int
    {
        $own = @\fstat($stream);
        if (!\is_array($own) || ($own['ino'] ?? 0) === 0) {
            return null;
        }

        $match = null;
        foreach (@\scandir('/proc/self/fd') ?: [] as $entry) {
            if (!\ctype_digit($entry) || (int) $entry <= 2) {
                continue;
            }
            $candidate = @\stat('/proc/self/fd/' . $entry);
            if (\is_array($candidate) && $candidate['ino'] === $own['ino'] && $candidate['dev'] === $own['dev']) {
                if ($match !== null) {
                    // Two descriptors for one file (a dup): which one this
                    // stream owns is unknowable from here, so refuse.
                    return null;
                }
                $match = (int) $entry;
            }
        }

        return $match;
    }

    /**
     * First executable named $binary on the process PATH, or '' when absent.
     *
     * An empty PATH element is skipped, not treated as '.': answering a
     * containment question from whatever the working directory happens to be
     * is the nondeterminism DetectsCapabilities exists to avoid, same rule.
     */
    public static function locateOnPath(string $binary): string
    {
        foreach (\explode(':', (string) \getenv('PATH')) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = \rtrim($dir, '/') . '/' . $binary;
            if (\is_file($candidate) && \is_executable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}

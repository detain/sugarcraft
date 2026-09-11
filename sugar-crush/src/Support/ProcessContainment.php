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

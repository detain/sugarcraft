<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Support;

/**
 * The one place the hang watchdog decides HOW to signal a process
 * (CI-wave-5 lane 5B).
 *
 * WHY THIS EXISTS. The watchdog was ported from sugar-pty in 50cae006c with
 * `posix_kill()` as its only sender, and `HangWatchdog::install()` bailed out
 * when ext-posix was absent. That bail-out is precisely the runner that needs
 * the instrument: the macOS GitHub images do not ship ext-posix in the default
 * brew PHP, and .github/workflows/ci.yml:30 (PHP_EXTENSIONS) installs mbstring,
 * intl, json, pcntl, sqlite3, pdo_sqlite, pdo_mysql, pdo_pgsql, ssh2, gd, curl,
 * openssl, ffi — no posix. Result: the watchdog had NEVER armed on a macOS
 * runner; the three 35:00 blind cancellations of /tmp/opencode/ciwatch-1116/
 * TRIAGE4.md ran while the one defence against them was disabled by its own
 * pre-flight (/tmp/opencode/ciwatch-1116/TRIAGE5.md §5B).
 *
 * THE FALLBACK. `/bin/kill` is part of the base OS on Darwin and Linux alike,
 * so a runner without ext-posix can still deliver SIGKILL by shelling out.
 * This class is the single seam that decides: prefer `posix_kill()` where the
 * extension is loaded (byte-for-byte the old behavior on every box where it
 * worked), else `/bin/kill`, else nothing — a watchdog that cannot signal is
 * no better than none, and the caller's arm gate checks {@see supported()}.
 * Windows stays a silent no-op: {@see HangWatchdog::install()} still refuses
 * before any of this runs, so the Windows legs' behavior is unchanged.
 *
 * SIGNAL NUMBERS, NOT PHP CONSTANTS. SIGKILL is 9 on every POSIX platform
 * (Linux and Darwin alike), and pcntl — the extension that DEFINES PHP's
 * `SIGKILL` constant — is a separate absence risk from posix. A fallback that
 * fatal-errors because a constant is missing on the very runner it exists for
 * is not a fallback.
 *
 * THE SEAM. `SUGAR_CORE_HANG_SHELL_KILL=1` makes this report the posix path
 * as unavailable even where it is loaded, so the /bin/kill branch can be
 * exercised on any development box and on runners that do have ext-posix.
 * It is how the lane's named-test proof is produced (see
 * /tmp/opencode/ci5-1116/LANE5B.md), and it is deliberately an OPT-IN: with it
 * unset, nothing about the old signal path changes.
 */
final class HangKill
{
    /** POSIX SIGKILL — 9 on Linux and Darwin; pcntl's constant may be absent, the number is not. */
    public const KILL = 9;

    /** Seam: force the /bin/kill branch even where ext-posix is loaded (proof and simulation). */
    public const FORCE_SHELL_ENV = 'SUGAR_CORE_HANG_SHELL_KILL';

    /** The kill utility shipped by the Darwin and Linux base OSes alike. */
    private const KILL_BIN = '/bin/kill';

    /**
     * Can a signal be delivered at all on this platform, right now?
     *
     * The arm gate asks this instead of the retired `function_exists('posix_kill')`
     * clause: Windows (no signal semantics we can use) and a POSIX box with
     * neither ext-posix nor /bin/kill refuse; everything else arms.
     */
    public static function supported(): bool
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return false;
        }
        if (\function_exists('posix_kill')) {
            return true;
        }
        return \is_executable(self::KILL_BIN);
    }

    /** Whether signalling must go through /bin/kill: no ext-posix, or the opt-in seam. */
    public static function shellForced(): bool
    {
        $raw = \getenv(self::FORCE_SHELL_ENV);
        return \is_string($raw) && $raw !== '' && $raw !== '0';
    }

    /** Deliver $signal to $pid. True only when the kernel accepted it. */
    public static function send(int $pid, int $signal): bool
    {
        if (!self::shellForced() && \function_exists('posix_kill')) {
            return @\posix_kill($pid, $signal);
        }
        return self::shell($pid, $signal) === 0;
    }

    /** SIGKILL $pid — the watchdog's only destructive act. */
    public static function kill(int $pid): bool
    {
        return self::send($pid, self::KILL);
    }

    /**
     * Is $pid still signalable? Signal 0 runs the kernel's permission/existence
     * check without delivering anything — the same probe the old posix path
     * used for the child's "parent gone, exit quietly" test.
     */
    public static function alive(int $pid): bool
    {
        return self::send($pid, 0);
    }

    /**
     * Run /bin/kill and report its exit status (-1 when it could not run).
     *
     * exec() first because it is the cheapest shell-out that still returns a
     * status; proc_open() as the last resort for a box that disabled exec but
     * not proc_open — and a runner that reaches this method at all has
     * proc_open, because arming the watchdog already requires it. Flags and
     * arguments carry the AGENTS.md escapeshellarg mandate on every leg.
     */
    private static function shell(int $pid, int $signal): int
    {
        $command = \escapeshellarg(self::KILL_BIN)
            . ' ' . \escapeshellarg('-' . $signal)
            . ' ' . \escapeshellarg((string) $pid)
            . ' 2>/dev/null';

        if (\function_exists('exec')) {
            $output = [];
            $status = -1;
            @\exec($command, $output, $status);
            return (int) $status;
        }

        if (\function_exists('proc_open')) {
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['file', '/dev/null', 'a'],
            ];
            $pipes = [];
            $proc = @\proc_open(
                [self::KILL_BIN, '-' . $signal, (string) $pid],
                $descriptors,
                $pipes,
            );
            if (\is_resource($proc)) {
                return \proc_close($proc);
            }
        }

        return -1;
    }
}

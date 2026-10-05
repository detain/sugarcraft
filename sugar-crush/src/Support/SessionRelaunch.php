<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

use SugarCraft\Crush\Lang;

/**
 * Restart sugar-crush in another directory — what `/new` does when the
 * directory picked is not the current project root.
 *
 * WHY A RESTART: one process serves one root. `Cli\Bootstrap` keeps the
 * launch's root in process statics (settings layers, frozen trust lists, MCP
 * clients started once), so a session in another directory needs a fresh
 * process — the same reason `serve` runs other roots in workspace hosts.
 *
 * HOW: `/new` sets {@see \SugarCraft\Crush\Chat::pendingRelaunch()} and quits
 * through the ordinary `Cmd::quit()`, so the program restores the terminal
 * exactly as on any exit (alt-screen left, cursor shown, mouse and Kitty modes
 * popped). `bin/sugarcrush` then calls {@see exec()}: every inherited
 * descriptor above stderr is marked close-on-exec (a session lock or database
 * handle must not outlive this process into the next), the process moves into
 * the directory and replaces itself with the same PHP binary running the same
 * script with {@see flags()} — the launch's flags minus everything about
 * which session or root to open, so it starts a NEW session there. Without
 * `pcntl_exec()` (or if it fails) it prints the command to run instead.
 */
final class SessionRelaunch
{
    /** Launch flags carried into the restart; each takes one value. */
    public const KEPT_FLAGS = ['--config', '--model', '--permission-mode'];

    private function __construct()
    {
    }

    /**
     * The flags of $argv (the launch's, script name first) the restart keeps:
     * {@see KEPT_FLAGS} in either `--flag value` or `--flag=value` form, a
     * relative `--config` made absolute against $launchCwd (the restart runs
     * in another directory). Dropped: `--resume`, `--continue`/`-c`, `--root`,
     * positional operands (a directory or an initial prompt), `-p`/`--prompt`,
     * and everything after `--`.
     *
     * @param list<string> $argv
     *
     * @return list<string>
     */
    public static function flags(array $argv, string $launchCwd): array
    {
        $out = [];
        $count = \count($argv);
        for ($i = 1; $i < $count; $i++) {
            $arg = $argv[$i];
            if ($arg === '--') {
                break;
            }
            foreach (self::KEPT_FLAGS as $flag) {
                $value = null;
                if ($arg === $flag && isset($argv[$i + 1]) && !\str_starts_with($argv[$i + 1], '-')) {
                    $value = $argv[++$i];
                } elseif (\str_starts_with($arg, $flag . '=')) {
                    $value = \substr($arg, \strlen($flag) + 1);
                }
                if ($value === null) {
                    continue;
                }
                if ($flag === '--config' && $value !== '' && !\str_starts_with($value, '/')) {
                    $value = \rtrim($launchCwd, '/') . '/' . $value;
                }
                $out[] = $flag;
                $out[] = $value;
                break;
            }
        }

        return $out;
    }

    /** The restart as a shell command a person can paste: `cd <dir> && <php> <script> <flags>`. */
    public static function command(string $dir, string $php, string $script, array $flags): string
    {
        return 'cd ' . \escapeshellarg($dir) . ' && '
            . \implode(' ', \array_map('escapeshellarg', [$php, $script, ...$flags]));
    }

    /**
     * Restart in $dir. Returns only when it could not: the command to run is
     * then printed on stderr, and the exit code is what the caller exits with.
     *
     * @param list<string> $argv the launch's argv, script first
     */
    public static function exec(string $dir, array $argv, string $script): int
    {
        $php = \PHP_BINARY;
        $flags = self::flags($argv, \getcwd() ?: '/');
        $command = self::command($dir, $php, $script, $flags);

        if (!\function_exists('pcntl_exec')) {
            return self::tell(Lang::t('relaunch.manual', ['dir' => $dir]), $command, 0);
        }
        if (!@\chdir($dir)) {
            return self::tell(Lang::t('relaunch.failed', ['dir' => $dir, 'reason' => 'cannot enter the directory']), $command, 1);
        }
        self::closeInheritedOnExec();
        @\pcntl_exec($php, [$script, ...$flags]);

        // Only reached when the exec failed.
        $reason = \function_exists('pcntl_get_last_error') ? \pcntl_strerror(\pcntl_get_last_error()) : 'exec failed';

        return self::tell(Lang::t('relaunch.failed', ['dir' => $dir, 'reason' => $reason]), $command, 1);
    }

    /**
     * The one line saying why no restart happened, and the command that does
     * it by hand. Stderr alone: the TUI is gone and the terminal is the
     * user's shell again, so there is no transcript left to carry it.
     */
    private static function tell(string $why, string $command, int $exit): int
    {
        \fwrite(\STDERR, $why . "\n  " . $command . "\n");

        return $exit;
    }

    /**
     * Mark every descriptor above stderr close-on-exec, so nothing this
     * process held — a session lock, the session database, a provider socket
     * — survives into the restarted one. Best effort and silent: no /proc, no
     * ext-ffi, or FFI disabled changes nothing.
     */
    private static function closeInheritedOnExec(): void
    {
        if (!\extension_loaded('ffi') || !\is_dir('/proc/self/fd')) {
            return;
        }
        try {
            $libc = \FFI::cdef('int fcntl(int fd, int cmd, ...);');
        } catch (\Throwable) {
            return;
        }
        foreach (@\scandir('/proc/self/fd') ?: [] as $entry) {
            if (!\ctype_digit($entry) || (int) $entry <= 2) {
                continue;
            }
            try {
                // 2 = F_SETFD, 1 = FD_CLOEXEC on every Linux ABI.
                $libc->fcntl((int) $entry, 2, 1);
            } catch (\Throwable) {
                // A descriptor that closed meanwhile (scandir's own).
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

use SugarCraft\Pty\Posix\PosixProcess;
use SugarCraft\Pty\PtyException;

/**
 * Cross-platform helper for opening URLs and files in the user's
 * default application. Mirrors charmbracelet/x/exp/open.
 *
 * Always uses {@see proc_open()}'s argv form so user-supplied URLs
 * cannot reach a shell — `javascript:` / `data:` / `vbscript:` URIs
 * are also rejected up front by the scheme allowlist in {@see url()}.
 * The `cmd.exe` arms are the exception that proves the rule: cmd re-parses
 * the command line it is handed, so the target there goes through
 * {@see cmdStartArgument()} (sugar-core-061) or the launch is refused.
 *
 * WAITING IS THE CONTRACT, NOT A BETRAYED PROMISE (sugar-core-061): the
 * default runner holds the spawn until the LAUNCHER exits and reports that
 * exit code back — a launcher that fails (xdg-open with no handler, a wedged
 * `cmd start`) returns `false`, never a laundered `true`. Launchers detach
 * the real application themselves (`open`, `xdg-open`, `start`), so waiting
 * on the launcher is not waiting on the app; the docblock formerly claimed
 * "fire-and-forget" while the code unconditionally waited and discarded the
 * status. The only refusal without an exit code is an argument cmd.exe cannot
 * be fed safely: that never reaches a runner at all.
 */
final class Open
{
    /** @var \Closure(string, list<string>): bool|null */
    private static ?\Closure $runner = null;

    /**
     * Open `$url` in the system's default handler. Accepted schemes:
     * `http(s)://`, `file://`, `ftp://`, `ssh://`, `mailto:`. Anything
     * else (notably `javascript:`, `data:`, `vbscript:`) returns `false`
     * without invoking the runner.
     */
    public static function url(string $url): bool
    {
        $allowed = (bool) preg_match('#^(https?|file|ftp|ssh)://#i', $url)
            || stripos($url, 'mailto:') === 0;
        if (!$allowed) {
            return false;
        }
        return self::dispatch($url);
    }

    /**
     * Open the file at `$path` in the OS's default app for its type.
     * Returns `false` if the path can't be resolved via `realpath()`.
     */
    public static function file(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        $real = realpath($path);
        if ($real === false) {
            return false;
        }
        return self::dispatch($real);
    }

    /**
     * Resolve the [command, args] tuple for opening `$arg` on the given
     * platform. Exposed for tests; production callers should use
     * {@see url()} / {@see file()}.
     *
     * `$platform` accepts `PHP_OS_FAMILY` values (`Linux`, `BSD`,
     * `Solaris`, `Darwin`, `Windows`) plus the synthetic `WSL` for the
     * Windows-Subsystem-for-Linux dispatch path.
     *
     * @return array{0:string, 1:list<string>}
     */
    public static function commandFor(string $arg, ?string $platform = null, ?bool $hasWslview = null): array
    {
        $platform = $platform ?? self::detectPlatform();
        if ($platform === 'WSL') {
            $hasWslview = $hasWslview ?? self::commandExists('wslview');
            if ($hasWslview) {
                return ['wslview', [$arg]];
            }
            $safe = self::cmdStartArgument($arg);
            return $safe === null ? self::REFUSED : ['cmd.exe', ['/c', 'start', '', $safe]];
        }
        if ($platform === 'Windows') {
            // Empty "" is the window title — required so `start` doesn't
            // mistake a quoted URL for it (sugar-core-061: the target itself
            // is quoted + percent-doubled by cmdStartArgument, or the launch
            // is refused and no cmd.exe is ever fed the argument).
            $safe = self::cmdStartArgument($arg);
            return $safe === null ? self::REFUSED : ['cmd', ['/c', 'start', '', $safe]];
        }
        return match ($platform) {
            'Darwin'  => ['open', [$arg]],
            default   => ['xdg-open', [$arg]],
        };
    }

    /**
     * Inject a custom runner. The runner receives the resolved command
     * and argv list and returns whether the launch succeeded. Pass
     * `null` to restore the default `proc_open`-backed runner. Returns
     * the previously installed runner so tests can restore it.
     *
     * @param \Closure(string, list<string>): bool|null $runner
     * @return \Closure(string, list<string>): bool|null
     */
    public static function setRunner(?\Closure $runner): ?\Closure
    {
        $prev = self::$runner;
        self::$runner = $runner;
        return $prev;
    }

    /**
     * The unsanitizable-argument sentinel from {@see commandFor()}: an empty
     * command that {@see dispatch()} refuses without touching the runner
     * (sugar-core-061 — better a silent no-open than an escaped-into-`start`
     * argument; the `false` return keeps that decision with the caller).
     */
    private const REFUSED = ['', []];

    /**
     * Harden one argument for cmd.exe's `start` re-parse (sugar-core-061).
     *
     * proc_open hands CreateProcess a rebuilt command LINE, and cmd.exe then
     * RE-PARSES it: `&`, `|`, `<`, `>`, `^` act as operators and `%VAR%`
     * expands even inside a quoted context — a URL like
     * `https://x/&calc&` used to launch CALC on the user's desktop. Inside
     * double quotes the operators go literal, so the canonical shape is
     * `start "" "<target>"` with the target quoted and every percent
     * doubled (cmd's own escape for a literal `%`). A value that cannot be
     * made safe at all — an embedded double quote (cmd's own delimiter;
     * there is no quoting layer left to protect it) or any C0 control /
     * DEL byte (never legal in a URL or path) — is refused by returning
     * null, and the launch dies as `false` before a shell is ever involved.
     */
    public static function cmdStartArgument(string $arg): ?string
    {
        if (preg_match('/[\x00-\x1F\x7F"]/', $arg) === 1) {
            return null;
        }
        return '"' . str_replace('%', '%%', $arg) . '"';
    }

    private static function dispatch(string $arg): bool
    {
        [$cmd, $args] = self::commandFor($arg);
        if ($cmd === '') {
            // Named refusal (sugar-core-061): no cmd-safe form exists.
            return false;
        }
        $runner = self::$runner ?? self::defaultRunner();
        return $runner($cmd, $args);
    }

    private static function defaultRunner(): \Closure
    {
        return static function (string $cmd, array $args): bool {
            $argv = array_merge([$cmd], array_values($args));
            try {
                $proc = PosixProcess::spawn($argv, null, false, false);
                // sugar-core-061: the launcher's exit code IS the result — a
                // failed or wedged launcher must not report success. (This
                // waits on the LAUNCHER; launchers detach the application.)
                return $proc->wait() === 0;
            } catch (PtyException) {
                // Named non-success: the spawn never reached a launcher.
                return false;
            }
        };
    }

    private static function detectPlatform(): string
    {
        return self::isWsl() ? 'WSL' : PHP_OS_FAMILY;
    }

    private static function isWsl(): bool
    {
        if (getenv('WSL_INTEROP') !== false || getenv('WSL_DISTRO_NAME') !== false) {
            return true;
        }
        if (PHP_OS_FAMILY !== 'Linux' || !is_readable('/proc/version')) {
            return false;
        }
        $v = @file_get_contents('/proc/version');
        return is_string($v) && (stripos($v, 'microsoft') !== false || stripos($v, 'wsl') !== false);
    }

    private static function commandExists(string $cmd): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $out = @shell_exec('where ' . escapeshellarg($cmd) . ' 2>NUL');
        } else {
            $out = @shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null');
        }
        return is_string($out) && trim($out) !== '';
    }
}

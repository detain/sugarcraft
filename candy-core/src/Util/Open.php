<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Cross-platform helper for opening URLs and files in the user's
 * default application. Mirrors charmbracelet/x/exp/open.
 *
 * Always uses {@see proc_open()}'s argv form so user-supplied URLs
 * cannot reach a shell — `javascript:` / `data:` / `vbscript:` URIs
 * are also rejected up front by the scheme allowlist in {@see url()}.
 *
 * Fire-and-forget: the launched application is detached. Failures
 * (xdg-utils not installed, sandboxed `open`, no `cmd.exe` interop)
 * surface as a `false` return; callers decide UX.
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
            return $hasWslview
                ? ['wslview', [$arg]]
                : ['cmd.exe', ['/c', 'start', '', $arg]];
        }
        return match ($platform) {
            'Darwin'  => ['open', [$arg]],
            // Empty `""` is the window title — required so `start` doesn't
            // mistake a quoted URL for it.
            'Windows' => ['cmd', ['/c', 'start', '', $arg]],
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

    private static function dispatch(string $arg): bool
    {
        [$cmd, $args] = self::commandFor($arg);
        $runner = self::$runner ?? self::defaultRunner();
        return $runner($cmd, $args);
    }

    private static function defaultRunner(): \Closure
    {
        return static function (string $cmd, array $args): bool {
            $argv = array_merge([$cmd], array_values($args));
            $opts = DIRECTORY_SEPARATOR === '\\' ? ['bypass_shell' => true] : [];
            $proc = @proc_open(
                $argv,
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
                null,
                null,
                $opts,
            );
            if (!is_resource($proc)) {
                return false;
            }
            foreach ($pipes as $p) {
                if (is_resource($p)) {
                    fclose($p);
                }
            }
            proc_close($proc);
            return true;
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

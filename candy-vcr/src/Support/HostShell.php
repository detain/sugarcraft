<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Support;

use SugarCraft\Core\Util\Executable\Locator;

/**
 * Where a shell lives and how to start it, on the host PHP actually runs on.
 *
 * Both `record --shell` and a tape's `Set Shell` spawn a shell under a pty.
 * The POSIX answers (`$SHELL` or /bin/sh, `-l`/`-i`, a bare name found in
 * /bin or on a colon-split PATH) mean nothing on Windows, where the shell is
 * `%ComSpec%` (cmd.exe), executables carry a PATHEXT suffix, absolute paths
 * start with a drive letter, and cmd.exe takes no `-i`. Before this class the
 * Windows answers fell through to `/bin/sh`, which does not exist there.
 */
final class HostShell
{
    /** Windows system variables a child needs to start at all; the OS's own locations, no secrets. */
    public const WINDOWS_SYSTEM_ENV = [
        'SystemRoot', 'SystemDrive', 'windir', 'ComSpec', 'PATHEXT', 'TEMP', 'TMP',
        'USERPROFILE', 'USERNAME', 'HOMEDRIVE', 'HOMEPATH', 'APPDATA', 'LOCALAPPDATA',
        'ProgramData', 'ProgramFiles', 'ProgramFiles(x86)', 'ProgramW6432',
        'CommonProgramFiles', 'NUMBER_OF_PROCESSORS', 'PROCESSOR_ARCHITECTURE', 'OS',
    ];

    public static function onWindows(): bool
    {
        return \PHP_OS_FAMILY === 'Windows';
    }

    /** The shell to use when none is configured: `%ComSpec%` on Windows, /bin/sh elsewhere. */
    public static function fallback(): string
    {
        if (self::onWindows()) {
            $comspec = (string) (\getenv('ComSpec') ?: '');

            return $comspec !== '' ? $comspec : 'cmd.exe';
        }

        return '/bin/sh';
    }

    /**
     * Whether $path names a file this host can execute (on Windows, by
     * PATHEXT extension: PHP's is_executable() says false for .cmd/.bat).
     */
    public static function isExecutable(string $path): bool
    {
        return Locator::isExecutable($path);
    }
    public static function isAbsolute(string $path): bool
    {
        if (\str_starts_with($path, '/')) {
            return true;
        }

        return self::onWindows()
            && (\preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 || \str_starts_with($path, '\\\\'));
    }

    /**
     * Resolve a bare name to an executable path: the conventional POSIX bin
     * directories, then sugar-core's {@see Locator} (PATH, plus every PATHEXT
     * suffix on Windows). Null when nothing is found.
     */
    public static function locate(string $name): ?string
    {
        if ($name === '') {
            return null;
        }
        if (!self::onWindows()) {
            foreach (['/bin/', '/usr/bin/', '/usr/local/bin/'] as $dir) {
                if (\is_executable($dir . $name)) {
                    return $dir . $name;
                }
            }
        }

        return Locator::locate($name);
    }

    /**
     * The flags that start $shell interactive (or as a login shell). cmd.exe is
     * interactive by default and rejects `-i`; PowerShell takes its own words;
     * every POSIX-style shell (sh, bash, zsh, Git-for-Windows bash) gets `-l`
     * or `-i`.
     *
     * @return list<string>
     */
    public static function interactiveArgs(string $shell, bool $login): array
    {
        $base = \strtolower((string) \preg_replace('/\.(exe|com|bat|cmd)$/i', '', \basename(\str_replace('\\', '/', $shell))));

        return match ($base) {
            'cmd' => [],
            'powershell', 'pwsh' => $login ? ['-NoLogo'] : ['-NoLogo', '-NoProfile'],
            default => [$login ? '-l' : '-i'],
        };
    }

    /**
     * The Windows system variables present in this process, to merge into a
     * child's environment block (empty on other hosts).
     *
     * @return array<string, string>
     */
    public static function windowsSystemEnv(): array
    {
        if (!self::onWindows()) {
            return [];
        }
        $env = [];
        foreach (self::WINDOWS_SYSTEM_ENV as $name) {
            $value = \getenv($name);
            if ($value !== false) {
                $env[$name] = (string) $value;
            }
        }

        return $env;
    }
}

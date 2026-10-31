<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * The Windows half of shell resolution: PATH/PATHEXT lookup and the one
 * bash this package will run on Windows — Git for Windows' `bash.exe`.
 *
 * WHY THIS EXISTS (C-TUI-6.a). `ProcessContainment::locateOnPath()` split
 * PATH on `:` and tested candidates with `is_executable()`, and no caller
 * consulted `PATHEXT`. Both are POSIX-only assumptions: a Windows PATH is
 * `;`-separated and its entries name `C:\dir` — under `:`-splitting the
 * whole variable collapses into one unusable fragment — and a Windows
 * binary is found as `bash.exe`, an extension PHP's `is_file()` of the bare
 * `bash` never matches. The consequence was that `bash.exe` on a Windows
 * host was simply never found by the resolvers, feeding the quoting bug
 * {@see PosixShellQuote} closes with a silent `cmd.exe`-shaped fallback the
 * receiver cannot parse.
 *
 * Every method here is a pure function of (PATH-like string, PATHEXT-like
 * string, filesystem of candidate names) reached through injectable seams,
 * so the resolution ladder is EXEC-testable as data on any host — the
 * injected `osFamily` says "Windows" while the candidate files are real
 * temp-dir files with real `is_file()` answers. That is the C-TUI-6.b/6.c
 * posture: Windows-only legs tested by shape through the seams, never
 * pretending to run Windows.
 *
 * THE cmd.exe RULE. Nothing in this class returns or falls back to
 * `cmd.exe`. A Windows host without a resolvable bash.exe gets {@see
 * BASH_MISSING_NOTICE} from the callers and the command never runs: cmd.exe
 * speaks a different quoting language, a different redirection grammar and
 * a different command vocabulary, and "the Bash tool" that silently became
 * a cmd.exe runner would report success while corrupting every script it
 * was handed. Refusing loudly is the honest answer; installing Git for
 * Windows is the remedy the refusal names.
 */
final class WindowsShell
{
    /**
     * The refusal every Windows caller answers with when no bash.exe exists
     * on this host — it names the missing piece and its remedy, and states
     * the deliberate no-cmd.exe rule so nobody "fixes" the refusal by
     * quietly adding the fallback.
     */
    public const BASH_MISSING_NOTICE = 'Bash cannot run: no bash.exe was found on PATH or under %ProgramFiles%\\Git\\bin. '
        . 'Install Git for Windows (https://git-scm.com/downloads/win), which provides it. '
        . 'This tool does not fall back to cmd.exe: its quoting and command grammar cannot carry a bash script.';

    /**
     * Windows' extension-lookup list as `PATHEXT` would carry it — the
     * default a Windows host applies when the variable is unset.
     */
    public const DEFAULT_PATHEXT = '.COM;.EXE;.BAT;.CMD';

    /**
     * Test-only OS family override; null reads the live {@see \PHP_OS_FAMILY}.
     */
    private static ?string $osOverride = null;

    /**
     * Test-only environment reader; null reads the live {@see getenv()}.
     * The seam receives the variable name and answers ?string (null for
     * "unset"), so a test can hand a PATH full of `C:\dir` entries without
     * touching this process's real environment.
     */
    private static ?\Closure $envSeam = null;

    /**
     * The OS family every Windows arm in this namespace consults.
     */
    public static function osFamily(): string
    {
        return self::$osOverride ?? \PHP_OS_FAMILY;
    }

    public static function isWindows(): bool
    {
        return self::osFamily() === 'Windows';
    }

    /**
     * Point the family read at a fixed value (tests), or at null to hand it
     * back to {@see \PHP_OS_FAMILY}.
     */
    public static function setOsFamilyForTesting(?string $family): void
    {
        self::$osOverride = $family;
    }

    /**
     * Replace the environment reader (tests), or pass null to read the live
     * environment again.
     */
    public static function setEnvSeamForTesting(?\Closure $seam): void
    {
        self::$envSeam = $seam;
    }

    /**
     * The environment value for $name under the seams — null for unset or
     * empty, the answer every caller's loop treats the same way.
     */
    public static function env(string $name): ?string
    {
        $seam = self::$envSeam;
        if ($seam !== null) {
            $value = $seam($name);

            return $value === null || $value === '' ? null : (string) $value;
        }

        $value = \getenv($name);

        return $value === false || $value === '' ? null : $value;
    }

    /**
     * Find $binary the way a Windows host finds programs: walk the `;`-
     * separated PATH in order, and in each directory try the name as given
     * first, then the name with every `PATHEXT` extension appended.
     *
     * `is_executable()` is deliberately NOT consulted: on Windows it reports
     * false for files the OS runs happily (the executable bit is a POSIX
     * concept; PHP's Windows arm keys off the extension, which is exactly
     * the lookup being performed here). `is_file()` is the honest question.
     *
     * Returns '' when nothing matches — the caller's "might the binary be
     * missing" answer, same as {@see \SugarCraft\Code\Support\ProcessContainment::locateOnPath()}'s
     * POSIX arm: a pure stat ladder, never a spawn.
     */
    public static function locate(string $binary): string
    {
        $path = self::env('PATH');
        if ($path === null || $binary === '') {
            return '';
        }

        $candidates = [$binary];
        foreach (self::pathExtensions($binary) as $extension) {
            $candidates[] = $binary . $extension;
        }

        foreach (\explode(';', $path) as $dir) {
            if ($dir === '') {
                continue;
            }

            foreach ($candidates as $candidate) {
                $full = \rtrim($dir, '\\/') . \DIRECTORY_SEPARATOR . $candidate;
                if (\is_file($full)) {
                    return $full;
                }
            }
        }

        return '';
    }

    /**
     * THE bash resolution ladder, exactly one rung deep per ruling: PATH
     * first (a Git-for-Windows install that chose "add to PATH", or MSYS2 —
     * whatever bash.exe the user points at wins), then the stock
     * `%ProgramFiles%\Git\bin\bash.exe` of the default installer. None: ''.
     *
     * @return string the absolute path of a bash.exe that exists, or '' —
     *                 never a cmd.exe path, never a bare 'bash' guess
     */
    public static function bash(): string
    {
        $found = self::locate('bash');
        if ($found !== '') {
            return $found;
        }

        $programFiles = self::env('ProgramFiles');
        if ($programFiles === null) {
            return '';
        }

        $candidate = \rtrim($programFiles, '\\/') . \DIRECTORY_SEPARATOR . 'Git' . \DIRECTORY_SEPARATOR
            . 'bin' . \DIRECTORY_SEPARATOR . 'bash.exe';

        return \is_file($candidate) ? $candidate : '';
    }

    /**
     * The extensions to append to $binary when searching PATH.
     *
     * A name that already carries a dot (`taskkill.exe` style callers, and
     * every {@see self::locate()} self-extension test) gets no extension
     * list: Windows' own rule is that an explicit extension disables
     * PATHEXT matching, and appending `.EXE` to `bash.exe` would search for
     * `bash.exe.EXE`, a file no installer creates.
     *
     * @return list<string>
     */
    private static function pathExtensions(string $binary): array
    {
        if (\str_contains($binary, '.')) {
            return [];
        }

        $pathext = self::env('PATHEXT') ?? self::DEFAULT_PATHEXT;
        $extensions = [];
        foreach (\explode(';', $pathext) as $extension) {
            $extension = \trim($extension);
            if ($extension !== '') {
                $extensions[] = $extension;
            }
        }

        return $extensions;
    }
}

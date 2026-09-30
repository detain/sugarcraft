<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Executable;

/**
 * Which executable a bare program name resolves to on this host — answered
 * by a STAT WALK, never by spawning a probe.
 *
 * WHY A WALK AND NEVER A SPAWN is the load-bearing half of this class. The
 * idiomatic "is it installed" probe — `proc_open(['rg','--version'])` and
 * friends — emits a PHP warning on a host where the binary is absent, and a
 * suite that runs with `failOnWarning` turns red for nothing but asking the
 * question. A capability probe that cannot be asked inside a test is a probe
 * nobody pins, so every resolver here answers from `is_file()`/
 * `is_executable()` calls only.
 *
 * WHY UPSTREAM AT ALL: this resolution was solved sideways at least five
 * times across the monorepo — three copies inside sugar-crush alone
 * (`ProcessContainment::locateOnPath()`, `ClaudeCodeMcpClient::resolveExecutable()`
 * and the `DetectsCapabilities` stat walk, the last pair of docblocks naming
 * the duplication on purpose) plus per-lib walkers in candy-core's own
 * `Editor`, candy-pty, sugar-dash and sugar-reel. One class, one set of
 * rules, zero state.
 *
 * STATELESS BY CONTRACT: nothing here memoizes. A per-process cache of PATH
 * answers goes stale the moment a caller installs a tool mid-session (the
 * doctor/repair flows do exactly that), and every existing consumer either
 * asks once at boot or is cheap enough to re-ask. A consumer that wants a
 * memo owns its own invalidation; the locator stays a pure function of
 * `(name, PATH, PATHEXT, filesystem)`.
 *
 * The stat calls FOLLOW symlinks deliberately: a distribution shipping
 * `/usr/bin/rg` as a link into `/usr/bin/rg.real` is a host with `rg` on it,
 * and an `lstat()` here would report it missing.
 */
final class Locator
{
    /**
     * The extensions Windows appends when the bare name has none — used only
     * when `$PATHEXT` itself is unset or empty, mirroring how `cmd.exe`
     * behaves with no environment customization.
     */
    public const DEFAULT_WINDOWS_EXTENSIONS = ['.COM', '.EXE', '.BAT', '.CMD'];

    private function __construct()
    {
    }

    /**
     * First executable this name resolves to, or null when the host has none.
     *
     * `$extraPath` is PREPENDED to the process `PATH` (directory list in the
     * platform's own separator), so a caller can aim one lookup at an install
     * directory without a `putenv()` that would leak into every sibling in
     * the process. Entries in `$extraPath` therefore win over system ones —
     * the caller adding a directory means "prefer this one".
     */
    public static function locate(string $name, ?string $extraPath = null): ?string
    {
        return self::search($name, self::pathList($extraPath), self::pathExtensions());
    }

    /**
     * Whether {@see locate()} would answer non-null. Named for the capability
     * question ("is `rg` installed?") so callers never re-implement the walk
     * just to discard the path.
     */
    public static function exists(string $name, ?string $extraPath = null): bool
    {
        return self::locate($name, $extraPath) !== null;
    }

    /**
     * The walk over an EXPLICIT path list — the testable boundary.
     *
     * Pointing it at a sandbox directory answers a question about that
     * directory deterministically, on any host, without touching the
     * process environment at all. `$extensions` is likewise explicit rather
     * than platform-derived, so the Windows PATHEXT append order is pinned
     * on Linux CI too (the defaults {@see locate()} passes stay platform-
     * derived; on POSIX the list is empty and the exact name is the only
     * candidate).
     *
     * @param list<string> $extensions
     */
    public static function search(string $name, string $pathList, array $extensions = []): ?string
    {
        if ($name === '') {
            return null;
        }

        // A name containing a separator is not a PATH lookup at all — it is
        // a path, and is answered as one, exactly as every predecessor did.
        if (str_contains($name, DIRECTORY_SEPARATOR) || str_contains($name, '/')) {
            return self::withExtensions($name, $extensions);
        }

        if ($pathList === '') {
            return null;
        }

        foreach (explode(self::pathSeparator(), $pathList) as $dir) {
            // An empty element is skipped, not treated as '.': POSIX gives
            // the empty PATH element the meaning "the current directory",
            // and answering a capability question from whatever the working
            // directory happens to be is nondeterminism by contract.
            if ($dir === '') {
                continue;
            }

            $hit = self::withExtensions(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name, $extensions);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * The full search path a {@see locate()} call will walk: `$extraPath`
     * first, then the process `PATH`. Exposed because consumers that build
     * their own candidate lists (launchers showing what they probed) need
     * the same composition rule, not a fourth copy of it.
     */
    public static function pathList(?string $extraPath = null): string
    {
        $system = (string) getenv('PATH');

        if ($extraPath === null || $extraPath === '') {
            return $system;
        }

        if ($system === '') {
            return $extraPath;
        }

        return $extraPath . self::pathSeparator() . $system;
    }

    /**
     * `;` on Windows, `:` everywhere else — the list separator belongs to
     * the platform, not to whichever string arrived from the environment.
     */
    public static function pathSeparator(): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? ';' : ':';
    }

    /**
     * The extension list {@see locate()} appends: the Windows `PATHEXT`
     * environment value split on `;` (its documented default when unset),
     * and NOTHING on POSIX, where executability is a mode bit and an
     * extension carry has no meaning.
     *
     * @return list<string>
     */
    public static function pathExtensions(): array
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return [];
        }

        $pathExt = getenv('PATHEXT');
        if (!is_string($pathExt) || $pathExt === '') {
            return self::DEFAULT_WINDOWS_EXTENSIONS;
        }

        return array_values(array_filter(
            explode(';', $pathExt),
            static fn (string $extension): bool => $extension !== '',
        ));
    }

    /**
     * The exact candidate first — an explicit extension in the name is
     * honoured as written — then the name with each appended extension, in
     * list order.
     *
     * @param list<string> $extensions
     */
    private static function withExtensions(string $candidate, array $extensions): ?string
    {
        if (self::isExecutableFile($candidate)) {
            return $candidate;
        }

        foreach ($extensions as $extension) {
            if ($extension === '') {
                continue;
            }

            $suffixed = $candidate . $extension;
            if (self::isExecutableFile($suffixed)) {
                return $suffixed;
            }
        }

        return null;
    }

    private static function isExecutableFile(string $candidate): bool
    {
        return is_file($candidate) && is_executable($candidate);
    }
}

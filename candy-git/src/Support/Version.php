<?php

declare(strict_types=1);

namespace SugarCraft\Git\Support;

/**
 * The version string this tool reports on `--version`.
 *
 * Lighter namespace copy of candy-top/src/Support/Version.php (the DST-1
 * program): {@see self::CURRENT} is the checkout fallback and the ONE
 * override is the build stamp of the phar this process was launched from.
 * candy-git ships no `self-update`, so the ordering machinery candy-top
 * needs (parse/compare, fail-closed on `dev-master`) has nothing to serve
 * here — any non-empty stamp the build wrote is reported verbatim, and a
 * dev-built archive (whose stamp is the sentinel `dev-build`) honestly falls
 * through to CURRENT.
 *
 * WHY THE ARCHIVE ARM EXISTS AT ALL (DST-1): the frozen `vendor/` baked
 * into a release archive reports the BUILD ROOT's dev guess, so a `--version`
 * that trusted anything but the archive's own manifest would lie about the
 * artifact it is running from. The build stamps `metadata['version']` in
 * tools/build-phar.php; this class is the sole reader.
 */
final class Version
{
    /**
     * The release namespace whose tags name a candy-git version: the phar
     * build stamps the full tag, this reader strips the prefix — one
     * spelling, both ends.
     */
    public const TAG_PREFIX = 'candy-git/';

    /** Fallback for non-phar installs: a source checkout has no fixed version. */
    public const CURRENT = 'dev-master';

    /**
     * The stamp a dev working-tree build carries. It is not a version, so
     * {@see self::fromPharMetadata()} refuses it and the report falls
     * through to {@see self::CURRENT} — mirroring candy-code's rule that
     * `dev-build` is never read as a version.
     */
    public const DEV_BUILD = 'dev-build';

    private function __construct()
    {
    }

    /**
     * The `--version` line: plain text, newline-terminated, produced by no
     * state and no host probe — the flag must answer anywhere.
     */
    public static function line(): string
    {
        return 'candy-git ' . self::current() . "\n";
    }

    /** The version this process reports: the archive stamp when running from
     *  a stamped phar, {@see self::CURRENT} everywhere else. */
    public static function current(): string
    {
        $archive = self::hostArchive();
        if ($archive !== '') {
            $stamped = self::fromPharMetadata($archive);
            if ($stamped !== null) {
                return $stamped;
            }
        }

        return self::CURRENT;
    }

    /**
     * The `version` a phar archive at $path was built to carry, or null when
     * it names none (unreadable archive, wrong metadata shape, the
     * `dev-build` sentinel, or an empty stamp). Every refusal is a null,
     * never an exception — `--version` must answer on a machine whose phar
     * cannot be opened.
     */
    public static function fromPharMetadata(string $path): ?string
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }

        try {
            $metadata = (new \Phar($path))->getMetadata();
        } catch (\Throwable) {
            return null;
        }

        return self::versionFromMetadata($metadata);
    }

    /**
     * The judgement on the stamp itself, split out because the archive-open
     * leg is unreachable from a suite that must not build phars: the release
     * namespace strip, the `dev-build` refusal, and the wrong-type nulls are
     * a pure function over what `getMetadata()` answers.
     */
    public static function versionFromMetadata(mixed $metadata): ?string
    {
        if (!is_array($metadata)) {
            return null;
        }
        $stamp = $metadata['version'] ?? null;
        if (!is_string($stamp)) {
            return null;
        }

        $stamp = trim($stamp);
        if (str_starts_with($stamp, self::TAG_PREFIX)) {
            $stamp = substr($stamp, strlen(self::TAG_PREFIX));
        }
        if ($stamp === '' || $stamp === self::DEV_BUILD) {
            return null;
        }

        return $stamp;
    }

    /**
     * The filesystem path of the phar this process was launched from, or ''
     * when it was not launched from one — the two-identity law measured on
     * candy-code's real builds and pinned by the bin autoload guard: inside
     * archive-loaded code `Phar::running(false)` answers the archive path,
     * while the stub context answers '' and there a '.phar'-suffixed
     * `__DIR__` is the enclosing archive.
     */
    public static function hostArchive(): string
    {
        $running = \Phar::running(false);
        if ($running !== '') {
            return $running;
        }

        $dir = __DIR__;

        return str_ends_with($dir, '.phar') ? $dir : '';
    }
}

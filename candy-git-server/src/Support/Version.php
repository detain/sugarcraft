<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Support;

/**
 * Reads the version stamped into a running phar archive's metadata.
 *
 * Mirrors candy-top's Support\Version (the DST-1 program): when the app runs
 * from inside a self-contained .phar, the frozen vendor/ makes Composer's
 * InstalledVersions report the build root's dev guess, so a build stamp that
 * nothing consumes would let `--version` lie. The stamp is read back here and
 * wins over the compiled-in fallback the caller passes in. The
 * `candy-git-server/` tag prefix and an optional leading `v` are stripped so
 * the caller always sees the bare X.Y.Z it can render; anything that does not
 * parse as a comparable release (a dev-build, a bad stamp, or a non-archive
 * path) yields null and the caller keeps its own constant.
 */
final class Version
{
    /** Tag prefix the release tags carry, e.g. candy-git-server/v0.2.0. */
    public const TAG_PREFIX = 'candy-git-server/';

    /** A bare, comparable release once TAG_PREFIX and `v` are removed. */
    private const COMPARABLE = '/^\d+\.\d+\.\d+$/';

    private function __construct()
    {
    }

    /**
     * Resolve the version for the current run.
     *
     * @param string $archive absolute filesystem path to the running .phar, or
     *                        '' when not executing inside an archive
     * @param string $fallback compiled-in version used outside an archive, or
     *                         when the archive stamp is absent or unparseable
     */
    public static function resolve(string $archive, string $fallback): string
    {
        return self::fromPharMetadata($archive) ?? $fallback;
    }

    /**
     * Read and normalize the version stamp inside an archive, or null when the
     * path is empty, is not a file, is not a readable phar, or its stamp does
     * not parse as a comparable release. Every failure mode falls back to
     * null rather than throwing — a corrupt archive must not crash `--version`.
     */
    public static function fromPharMetadata(string $archivePath): ?string
    {
        if ($archivePath === '' || !is_file($archivePath)) {
            return null;
        }

        try {
            $phar = new \Phar($archivePath);
            $metadata = $phar->getMetadata();
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($metadata) || !isset($metadata['version']) || !is_string($metadata['version'])) {
            return null;
        }

        $stamped = $metadata['version'];
        if (str_starts_with($stamped, self::TAG_PREFIX)) {
            $stamped = substr($stamped, strlen(self::TAG_PREFIX));
        }
        if (str_starts_with($stamped, 'v')) {
            $stamped = substr($stamped, 1);
        }

        return preg_match(self::COMPARABLE, $stamped) === 1 ? $stamped : null;
    }
}

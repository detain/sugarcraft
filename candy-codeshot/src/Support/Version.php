<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Support;

/**
 * Single source of truth for the printed version.
 *
 * `candy-codeshot --version` renders CURRENT — unless the run happens inside
 * a phar archive whose metadata carries a build stamp (lane PHE / DST-1): a
 * self-contained .phar freezes vendor/, so Composer's InstalledVersions
 * reports the build root's dev guess and only the stamp the builder wrote
 * tells the truth. The stamp wins when present; CURRENT is the fallback
 * outside any archive. Bump CURRENT in lockstep with the release tag, never
 * fork the literal into bin/ or the docs.
 */
final class Version
{
    public const CURRENT = '0.1.0';

    /** Tag prefix the release tags carry, e.g. candy-codeshot/v0.2.0. */
    public const TAG_PREFIX = 'candy-codeshot/';

    /** A bare, comparable release once TAG_PREFIX and `v` are removed. */
    private const COMPARABLE = '/^\d+\.\d+\.\d+$/';

    /**
     * CI rolling-build stamps: 'dev-' + 7..40 hex commit characters.
     */
    private const DEV = '/^dev-[0-9a-f]{7,40}$/';

    private function __construct()
    {
    }

    /**
     * Resolve the version for the current run.
     *
     * @param string $archive  absolute filesystem path to the running .phar,
     *                         or '' when not executing inside an archive
     * @param string $fallback compiled-in version used outside an archive, or
     *                         when the archive stamp is absent or unparseable
     */
    public static function resolve(string $archive, string $fallback = self::CURRENT): string
    {
        return self::fromPharMetadata($archive) ?? $fallback;
    }

    /**
     * Read and normalize the version stamp inside an archive, or null when the
     * path is empty, is not a file, is not a readable phar, or its stamp does
     * not parse as a comparable release or a dev-<sha> build stamp. Every
     * failure mode falls back to null rather than throwing — a corrupt archive
     * must not crash --version.
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

        return self::normalizeVersion($metadata['version']);
    }

    /**
     * Shared normalization for release and CI-dev build stamps: strips the
     * release-tag prefix and an optional 'v', then accepts either a comparable
     * release or a dev-<sha> stamp. Describe forms and 'dev-build' stay
     * unreportable and render as {@see CURRENT}.
     */
    private static function normalizeVersion(string $stamped): ?string
    {
        if (str_starts_with($stamped, self::TAG_PREFIX)) {
            $stamped = substr($stamped, strlen(self::TAG_PREFIX));
        }
        if (str_starts_with($stamped, 'v')) {
            $stamped = substr($stamped, 1);
        }

        return preg_match(self::COMPARABLE, $stamped) === 1
            || preg_match(self::DEV, $stamped) === 1 ? $stamped : null;
    }
}

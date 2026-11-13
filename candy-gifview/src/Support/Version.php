<?php

declare(strict_types=1);

namespace SugarCraft\GifView\Support;

/**
 * Single source of truth for the printed version.
 *
 * `candy-gifview --version` renders CURRENT — unless the run happens inside a
 * phar archive whose metadata carries a build stamp: a
 * self-contained .phar freezes vendor/, so Composer's InstalledVersions
 * reports the build root's dev guess and only the stamp the builder wrote
 * tells the truth. The stamp wins when present; CURRENT is the fallback
 * outside any archive. Bump CURRENT in lockstep with the release tag, never
 * fork the literal into bin/ or the docs.
 */
final class Version
{
    public const CURRENT = '0.1.0';

    /** Tag prefix the release tags carry, e.g. candy-gifview/v0.2.0. */
    public const TAG_PREFIX = 'candy-gifview/';

    /** A bare, comparable release once TAG_PREFIX and `v` are removed. */
    private const COMPARABLE = '/^\d+\.\d+\.\d+$/';

    /**
     * A development stamp: both the CI dev channel and a local build write
     * 'dev-<7-hex>' ('dev-build' when git cannot answer; a pasted full
     * 40-hex HEAD also parses). These are NOT
     * releases — they must self-report verbatim so a dev phar never silently
     * impersonates the stable version CURRENT would print.
     */
    private const DEV = '/^dev-(?:[0-9a-f]{7,40}|build)$/';

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
     * not parse as a comparable release. Every failure mode falls back to null
     * rather than throwing — a corrupt archive must not crash --version.
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

        return self::normalizeStamp($metadata['version']);
    }

    /**
     * Normalize a build stamp to a printable version, or null when it names
     * neither a comparable release nor a recognised development identity:
     * TAG_PREFIX stripped, a leading `v` stripped, then the COMPARABLE
     * release shape, then the DEV grammar. One grammar, one home — the
     * producer (tools/build-phar.php) validates against exactly this rule
     * through isStampable(), so a stamp that would be dropped at runtime is
     * refused at build time instead of silently printing CURRENT.
     */
    public static function normalizeStamp(string $stamped): ?string
    {
        if (str_starts_with($stamped, self::TAG_PREFIX)) {
            $stamped = substr($stamped, strlen(self::TAG_PREFIX));
        }
        if (str_starts_with($stamped, 'v')) {
            $stamped = substr($stamped, 1);
        }

        if (preg_match(self::COMPARABLE, $stamped) === 1) {
            return $stamped;
        }

        return preg_match(self::DEV, $stamped) === 1 ? $stamped : null;
    }

    /** Whether a producer may stamp this string at all (the consumer decides). */
    public static function isStampable(string $stamped): bool
    {
        return self::normalizeStamp($stamped) !== null;
    }
}

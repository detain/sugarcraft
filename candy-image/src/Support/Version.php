<?php

declare(strict_types=1);

namespace SugarCraft\CandyImage\Support;

/**
 * Reads the version stamped into a running phar archive's metadata.
 *
 * Mirrors candy-top's Support\Version: under a self-
 * contained .phar the frozen vendor/ makes Composer's InstalledVersions report
 * the build root's dev guess, so a build stamp nothing consumes would make
 * `--version` lie. The stamp is read back here and wins over the caller's
 * compiled-in fallback (Cli::VERSION). The `candy-image/` tag prefix and an
 * optional leading `v` are stripped so the caller always sees bare X.Y.Z —
 * candy-image's --version line prints the bare value with no banner prefix.
 * A recognised `dev-*` stamp survives verbatim: the rolling-dev channel ships
 * those, and dropping them would make a dev phar impersonate the stable
 * number (candy-gifview's reader carries the same arm; this port had dropped
 * it, so phar-dev.yml's `dev-<shortsha>` never surfaced through --version).
 */
final class Version
{
    /** Tag prefix the release tags carry, e.g. candy-image/v0.2.0. */
    public const TAG_PREFIX = 'candy-image/';

    /** A bare, comparable release once TAG_PREFIX and `v` are removed. */
    private const COMPARABLE = '/^\d+\.\d+\.\d+$/';

    /**
     * A development stamp: both the CI dev channel and a local build write
     * 'dev-<7-hex>' ('dev-build' when git cannot answer; a pasted full
     * 40-hex HEAD also parses). These are NOT releases — they must
     * self-report verbatim so a dev phar never silently impersonates the
     * stable version the compiled-in fallback would print.
     */
    private const DEV = '/^dev-(?:[0-9a-f]{7,40}|build)$/';

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
     * not parse as either a comparable release or a recognized development
     * identity. Every failure mode falls back to null
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
     * neither a comparable release nor a recognized development identity:
     * TAG_PREFIX stripped, a leading `v` stripped, then the COMPARABLE
     * release shape, then the DEV grammar. One grammar, one home — the
     * reader above delegates here so every arm is testable without minting
     * an archive (tests/Support/VersionTest.php pins the matrix, plus a
     * real-stamp round-trip through the reader).
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
}

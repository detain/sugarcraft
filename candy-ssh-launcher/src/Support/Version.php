<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Support;

/**
 * Version surface for the phar build (lane PHE, the DST-1 shape).
 *
 * `candy-ssh-launcher -v|--version` renders {@see resolve()} — under a running
 * archive the version stamped into the archive metadata at build time wins,
 * because the frozen vendor/ inside the artifact makes Composer's
 * InstalledVersions report the build root's dev guess (candy-code's measured
 * DST-1 lesson: a stamp nothing consumes makes --version lie). Outside an
 * archive — or when the stamp is absent or unparseable — the compiled-in
 * {@see CURRENT} fallback answers. The `candy-ssh-launcher/` tag prefix and an
 * optional leading `v` are stripped so the caller always sees bare X.Y.Z.
 */
final class Version
{
    /** Compiled-in fallback outside any archive; bumped with each release. */
    public const CURRENT = '0.1.0';

    /** Tag prefix the release tags carry, e.g. candy-ssh-launcher/v0.2.0. */
    public const TAG_PREFIX = 'candy-ssh-launcher/';

    /** A bare, comparable release once TAG_PREFIX and `v` are removed. */
    private const COMPARABLE = '/^\d+\.\d+\.\d+$/';

    private function __construct()
    {
    }

    /**
     * Resolve the version for the current run.
     *
     * @param string $archive absolute filesystem path of the running .phar, or
     *                        '' when not executing inside an archive
     */
    public static function resolve(string $archive): string
    {
        return self::fromPharMetadata($archive) ?? self::CURRENT;
    }

    /**
     * Read and normalize the version stamp inside an archive, or null when the
     * path is empty, is not a file, is not a readable phar, or its stamp does
     * not parse as a comparable release. Every failure mode falls back to
     * null rather than throwing — a corrupt archive must not crash --version.
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

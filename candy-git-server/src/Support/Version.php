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
 * the caller sees the bare X.Y.Z it can render.
 *
 * WHAT IT REFUSES (and what it now NAMES, 053): the ordered grammar is
 * exactly `X.Y.Z` — pre-release suffixes, build metadata, and four-segment
 * strings all answer null, fail-closed by design (guessing the intent of an
 * ordering it cannot express is how a dev checkout gets downgraded to the
 * last stable tag). A DEV stamp is not ordered but it IS locatable: the
 * phar-latest workflow law stamps every rolling build `dev-<shortsha>`
 * precisely so the artifact can name its commit, and the old shape threw
 * that identity away — COMPARABLE rejected it and `--version` printed the
 * compiled-in `0.1.0` for every build on the channel, indistinguishable.
 * Since 053 a canonical `dev-<hex>` stamp is an answer {@see
 * self::fromPharMetadata()} returns (mirroring candy-code's DEV_PREFIX
 * handling), {@see self::displayLabel()} renders it against the base triple
 * as `0.1.0 (dev-<sha>)`, and null is reserved for stamps this class
 * genuinely cannot read: an absent archive, junk metadata, or a dev-family
 * string that names no commit (`dev-build`, `dev-master`).
 */
final class Version
{
    /** Tag prefix the release tags carry, e.g. candy-git-server/v0.2.0. */
    public const TAG_PREFIX = 'candy-git-server/';

    /**
     * The lowercase spelling every DEV build stamp starts with (twin of
     * candy-code's DEV_PREFIX — the stable grammar deliberately does not
     * accept this family; dev builds are ordered by commit identity, not
     * by a triple that never moves).
     */
    public const DEV_PREFIX = 'dev-';

    /**
     * A canonical, commit-naming dev stamp: 'dev-' plus a git object name
     * at any honest abbreviation (4 = git's own minimum, 40 = full id —
     * the same window candy-code's devShaOf allows). The phar-latest law
     * stamps exactly `dev-<7hex>`; the wider window names commits, not
     * branch words — anything else stays unreadable, by design.
     */
    public const DEV_STAMP = '/^dev-[0-9a-f]{4,40}$/i';

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
     * Read and normalize the version stamp inside an archive, or null when
     * the path is empty, is not a file, is not a readable phar, or its stamp
     * is neither a comparable `X.Y.Z` release nor a canonical commit-naming
     * `dev-<hex>` build stamp. Every failure mode falls back to null rather
     * than throwing — a corrupt archive must not crash `--version`.
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

        // 053: a comparable release, or the canonical commit dev stamp —
        // null is reserved for what genuinely cannot be read (junk of the
        // wrong type, or a dev-family word naming no commit: 'dev-build',
        // 'dev-master'). The 'v' strip above never touches 'dev-...'.
        if (\preg_match(self::COMPARABLE, $stamped) === 1
            || \preg_match(self::DEV_STAMP, $stamped) === 1
        ) {
            return $stamped;
        }

        return null;
    }

    /**
     * Whether $version names a DEV build: its lowercase spelling starts
     * with DEV_PREFIX. Deliberately the literal rule and nothing more
     * (mirror of candy-code's isDev) — `dev-master` classifies as dev,
     * and {@see self::displayLabel()} simply has no commit to render
     * from it because fromPharMetadata() never surfaces one.
     */
    public static function isDev(string $version): bool
    {
        return \str_starts_with(\strtolower($version), self::DEV_PREFIX);
    }

    /**
     * The string `--version` prints (053): a comparable release — or any
     * run outside an archive, or one whose stamp names nothing readable —
     * answers exactly as {@see self::resolve()} does; a dev build carries
     * its commit as a parenthetical over the compiled-in base triple,
     * `0.1.0 (dev-abc1234)`, so the output names BOTH the product's
     * train and the exact build. The phar-latest smoke gate greps for
     * the raw `dev-<sha>` substring, which is why the stamp travels
     * verbatim inside the label.
     */
    public static function displayLabel(string $archive, string $fallback): string
    {
        $resolved = self::resolve($archive, $fallback);

        return self::isDev($resolved) ? $fallback . ' (' . $resolved . ')' : $resolved;
    }
}

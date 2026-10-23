<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * The dotted `major.minor.patch` version strings this tool reports on
 * `--version` and installs through `self-update`, parsed and compared
 * without a Composer runtime.
 *
 * WHY THIS EXISTS AT ALL. `self-update` must answer "is what GitHub
 * publishes newer than what is running?" before it writes a byte. Composer
 * knows how to order versions, but reaching for it from a phar means
 * reaching into the install metadata of whatever vendor directory happens
 * to sit around — the same dependency that `Help::versionString()` already
 * has to treat as conditional (a vendored non-Composer autoloader answers
 * nothing). The ordering rule `self-update` needs is three integers, and a
 * class this small cannot rot into a second source of truth about
 * releases: it parses what it is handed and compares, and knows nothing
 * about where either string came from — except the one source it DOES own:
 * {@see self::fromPharMetadata()} is the single reader of the version a phar
 * build stamps into its archive manifest (DST-1).
 *
 * WHAT IT REFUSES. Anything that is not exactly `X.Y.Z` (with one optional
 * leading `v`, which is what this repo's release tags carry:
 * `candy-code/v0.1.0`): pre-release suffixes, build metadata, four-segment
 * strings and `dev-master` all answer `null` from {@see self::parse()} and
 * `null` from {@see self::compare()}. That is fail-closed by design —
 * `self-update` treats a `null` comparison as "no swap", because guessing
 * the intent of an ordering it cannot express is how a dev checkout gets
 * downgraded to the last stable tag.
 *
 * THE ONE THING IT NAMES WITHOUT ORDERING. A DEV build is a version string
 * whose lowercase spelling starts with {@see self::DEV_PREFIX} — the stable
 * grammar is untouched (ordering a moving target is exactly the guess the
 * paragraph above refuses), but `self-update`'s channel decision only asks
 * "is this a dev build?", and {@see self::isDev()} answers that; the
 * canonical stamp a CI dev build carries is `dev-<shortsha>`
 * ({@see self::devShaOf()} reads the commit out of it).
 */
final class Version
{
    /**
     * The one release namespace whose tags name a candy-code version. It is
     * a SUPPORT constant (DST-1/DST-3): the phar build stamps its metadata
     * with the full tag, `self-update` filters tags by it, and the version
     * reader strips it — three readers, one spelling, no local copy to drift.
     */
    public const TAG_PREFIX = 'candy-code/';

    /**
     * The lowercase spelling every DEV build stamp starts with. The stable
     * grammar of {@see self::parse()} deliberately does NOT accept this
     * family — dev builds are ordered by commit identity, not by a triple
     * that never moves — but `self-update` must know which train it is
     * riding, and that question is a prefix test, not an ordering.
     */
    public const DEV_PREFIX = 'dev-';

    /**
     * A git object name at any honest abbreviation: 4 digits is `git`'s own
     * minimum, 40 a full id. This is the shape both halves of a dev-channel
     * comparison must be — the commit embedded in a `dev-<shortsha>` stamp
     * and the `target_commitish` a release names — and the shared gate that
     * makes "short" a refusal instead of a coincidental prefix match.
     */
    public const HEX_SHA_PATTERN = '/^[0-9a-f]{4,40}$/i';

    private function __construct()
    {
    }

    /**
     * The three numeric segments of a `v?X.Y.Z` string, or null when the
     * string is not that shape.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    public static function parse(string $version): ?array
    {
        if (\preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', \trim($version), $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    /**
     * -1, 0, or 1 as $left is older, equal, or newer than $right; null when
     * either side will not parse.
     *
     * Segments compare numerically left to right. There is no tie-break
     * beyond the patch number because there is nothing left to tie on: a
     * string that parses to three integers IS a full version under this
     * rule.
     */
    public static function compare(string $left, string $right): ?int
    {
        $a = self::parse($left);
        $b = self::parse($right);
        if ($a === null || $b === null) {
            return null;
        }

        foreach ([0, 1, 2] as $i) {
            $cmp = $a[$i] <=> $b[$i];
            if ($cmp !== 0) {
                return $cmp <=> 0;
            }
        }

        return 0;
    }

    /**
     * Whether $version is a version this class can order at all — the
     * question `self-update` asks of what `--version` reports before it
     * dares to compare it against a release tag.
     */
    public static function isComparable(string $version): bool
    {
        return self::parse($version) !== null;
    }

    /**
     * Whether $version is a DEV build: its lowercase spelling starts with
     * 'dev-'. Deliberately the literal rule and nothing more — no trim (the
     * canonical sources, `Help::versionString()` and the injected seam,
     * arrive trimmed), no grammar beyond the prefix. `dev-master` IS a dev
     * build under this rule, and so is the canonical CI stamp
     * `dev-<shortsha>`; `v1.2.3` and the `unknown` answer are not.
     */
    public static function isDev(string $version): bool
    {
        return \str_starts_with(\strtolower($version), self::DEV_PREFIX);
    }

    /**
     * The commit embedded in a canonical dev stamp `dev-<hex4-40>`, lower-
     * cased — or null for every string the dev channel cannot compare with
     * that precision ('dev' bare, 'dev-' with nothing after it, 'dev-master'
     * — a branch name, not a commit — and every non-dev shape). This is the
     * parse-don't-validate seam: one read turns the stamp into the trusted
     * hex it means, and the ordering rule downstream never re-inspects it.
     */
    public static function devShaOf(string $version): ?string
    {
        $trimmed = \trim($version);
        $body = \substr($trimmed, \strlen(self::DEV_PREFIX));
        if (!self::isDev($trimmed) || \preg_match(self::HEX_SHA_PATTERN, $body) !== 1) {
            return null;
        }

        return \strtolower($body);
    }

    /**
     * The version string a phar archive at $path was built to carry, or null
     * when the archive answers none this class can order.
     *
     * DST-1/DST-3 (the dead-on-arrival fix): `tools/build-phar.php` stamps
     * `metadata['version']` with the release tag, and NOTHING used to read it
     * back — the frozen `vendor/` inside the artifact made Composer's
     * `InstalledVersions` report the root's stale `dev-master`, so `--version`
     * lied and `self-update`'s ordering refused on the only install mode the
     * verb exists to serve. The archive's own manifest is the authority for
     * the archive; this is the sole reader of that stamp.
     *
     * The tag arrives prefixed (`candy-code/v0.1.0` — what `git describe`
     * answers and what the release carries); the {@see self::TAG_PREFIX}
     * decoration is stripped here so callers get the version itself. A stamp
     * that is neither a `v?X.Y.Z` nor a canonical `dev-<sha>` under any
     * spelling (`dev-build`, a foreign namespace, junk of the wrong type)
     * answers null: fail-closed, so a working-tree build falls through to
     * the Composer/checkout arms instead of shipping a label the release
     * stream cannot name. A canonical dev stamp, by contrast, IS the answer
     * — the CI dev build stamps `dev-$SHORT_SHA` precisely so `--version`
     * and the dev channel of `self-update` can read the commit back (the
     * DST-1 lesson: inside a phar the manifest is the authority, and
     * falling through to the frozen vendor reproduces the stale-guess lie
     * the stamp exists to cure).
     *
     * Every failure to OPEN the archive is likewise null, never an exception
     * — `--version` must answer on a machine whose phar is unreadable, and
     * `self-update` reads this before its own gates have said a word.
     */
    public static function fromPharMetadata(string $path): ?string
    {
        if ($path === '' || !\is_file($path)) {
            return null;
        }

        try {
            $metadata = (new \Phar($path))->getMetadata();
        } catch (\Throwable) {
            return null;
        }

        if (!\is_array($metadata)) {
            return null;
        }
        $stamp = $metadata['version'] ?? null;
        if (!\is_string($stamp)) {
            return null;
        }

        $stamp = \trim($stamp);
        if (\str_starts_with($stamp, self::TAG_PREFIX)) {
            $stamp = \substr($stamp, \strlen(self::TAG_PREFIX));
        }

        return (self::isComparable($stamp) || self::devShaOf($stamp) !== null) ? $stamp : null;
    }
}

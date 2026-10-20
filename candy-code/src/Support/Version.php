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
 * about where either string came from.
 *
 * WHAT IT REFUSES. Anything that is not exactly `X.Y.Z` (with one optional
 * leading `v`, which is what this repo's release tags carry:
 * `candy-code/v0.1.0`): pre-release suffixes, build metadata, four-segment
 * strings and `dev-master` all answer `null` from {@see self::parse()} and
 * `null` from {@see self::compare()}. That is fail-closed by design —
 * `self-update` treats a `null` comparison as "no swap", because guessing
 * the intent of an ordering it cannot express is how a dev checkout gets
 * downgraded to the last stable tag.
 */
final class Version
{
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
}

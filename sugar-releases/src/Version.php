<?php

declare(strict_types=1);

namespace SugarCraft\Releases;

/**
 * The strict `v?X.Y.Z` ordering the self-update engine runs on, plus the
 * dev-stamp classifier. Anything outside the grammar compares as `null`
 * ("cannot order"), which every caller treats as "no swap": guessing the
 * intent of an ordering it cannot express is how a dev checkout gets
 * downgraded to the last stable tag.
 *
 * A DEV build is a version whose lowercase spelling starts with `dev-`; the
 * canonical CI stamp is `dev-<shortsha>` and {@see devShaOf()} reads the commit
 * out of it. Dev builds are ordered by commit identity, never by this class.
 *
 * Extracted from candy-code's `Support/Version` (the archive-metadata reader
 * stays app-side).
 */
final class Version
{
    public const DEV_PREFIX = 'dev-';

    /** A git object name at any honest abbreviation (4 digits is git's floor). */
    public const HEX_SHA_PATTERN = '/^[0-9a-f]{4,40}$/i';

    private function __construct()
    {
    }

    /** @return array{0: int, 1: int, 2: int}|null */
    public static function parse(string $version): ?array
    {
        if (\preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', \trim($version), $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    /** -1, 0 or 1 as $left is older, equal or newer than $right; null when either side will not parse. */
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

    public static function isComparable(string $version): bool
    {
        return self::parse($version) !== null;
    }

    /** The literal prefix rule and nothing more: `dev-master` IS dev, `v1.2.3` and `unknown` are not. */
    public static function isDev(string $version): bool
    {
        return \str_starts_with(\strtolower($version), self::DEV_PREFIX);
    }

    /** The lower-cased commit of a `dev-<hex4-40>` stamp, or null for every other shape. */
    public static function devShaOf(string $version): ?string
    {
        $trimmed = \trim($version);
        $body = \substr($trimmed, \strlen(self::DEV_PREFIX));
        if (!self::isDev($trimmed) || \preg_match(self::HEX_SHA_PATTERN, $body) !== 1) {
            return null;
        }

        return \strtolower($body);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Git\Support;

/**
 * The git-version floor candy-git relies on, kept as pure string math so the
 * launcher and the tests share one authority.
 *
 * WHY 2.23: the status engine parses `--porcelain=v1` output and stage/unstage
 * drive `git restore` / `git restore --staged` — `git restore` only exists
 * from 2.23, so older binaries would fail mid-operation instead of at boot.
 * A loud refusal at launch is strictly kinder than a dead key on a Tuesday.
 */
final class GitRequirement
{
    /** Oldest git (major.minor) candy-git supports. */
    public const MINIMUM = '2.23';

    /**
     * Extract "major.minor" from a `git --version` banner.
     *
     * Handles plain (`git version 2.43.0`), distro suffixes
     * (`git version 2.39.5 (Apple Git-154)`) and Windows builds
     * (`git version 2.23.0.windows.1`). Returns null when nothing numeric is
     * present — the caller must NOT read that as "old but fine".
     */
    public static function parse(string $banner): ?string
    {
        if (preg_match('/git version (\d+)\.(\d+)/', $banner, $m) === 1) {
            return $m[1] . '.' . $m[2];
        }

        return null;
    }

    /** Does a "major.minor" string clear MINIMUM? */
    public static function satisfies(string $version): bool
    {
        return version_compare($version, self::MINIMUM, '>=');
    }
}

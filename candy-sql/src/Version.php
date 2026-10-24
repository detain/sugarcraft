<?php

declare(strict_types=1);

namespace SugarCraft\Sql;

/**
 * The `--version` answer, phar-aware (the DST-1 lesson from candy-code).
 *
 * Two laws meet in this file. 14a7e605a established that the composer-mode
 * answer tracks the installed package through Composer\InstalledVersions —
 * never a number hardcoded in the bin. DST-1 established that a shipped
 * phar must print the version its own build manifest stamped, because the
 * vendor/ frozen inside an archive answers the BUILD ROOT's state (a
 * perpetual `dev-master`) for the artifact's whole life. Fused precedence:
 *
 *   1. running from a phar with a real stamp  => that stamp WINS;
 *   2. composer mode                           => the InstalledVersions answer;
 *   3. no archive, no composer metadata        => `dev` (source fallback).
 *
 * A `dev-build` stamp is working-tree noise, not a version, and falls
 * through to 2/3 fail-closed — same sentinel, same refusal, as the candy-mail
 * fusion in this program (66b95734f).
 */
final class Version
{
    /** Stamps no release tag was reachable for at build time. */
    public const WORKING_TREE_STAMP = 'dev-build';

    /** What the answer degrades to when neither archive nor Composer speaks. */
    public const DEV = 'dev';

    private function __construct()
    {
    }

    public static function current(): string
    {
        return self::resolve(self::hostArchive(), self::installedVersion());
    }

    /**
     * The fused precedence as a pure function: an archive path (or '') and
     * the composer-mode answer. Splitting it out lets the suite pin the
     * chain — stamp wins, `dev-build` refuses, empty falls through — without
     * minting an archive (the real build smoke covers the end-to-end shape).
     */
    public static function resolve(string $archive, string $composerAnswer): string
    {
        if ($archive !== '') {
            try {
                $metadata = (new \Phar($archive))->getMetadata();
            } catch (\Throwable) {
                $metadata = null;
            }

            $stamp = self::stampFrom($metadata);
            if ($stamp !== null) {
                return $stamp;
            }
        }

        return $composerAnswer;
    }

    /**
     * The stamped version out of a build manifest, or null when the metadata
     * carries no servable version (not an array, absent, empty, or the
     * working-tree sentinel). Fail-closed: garbage metadata never prints.
     */
    public static function stampFrom(mixed $metadata): ?string
    {
        if (!is_array($metadata)) {
            return null;
        }

        $stamp = $metadata['version'] ?? null;
        if (!is_string($stamp)) {
            return null;
        }

        $stamp = trim($stamp);
        if ($stamp === '' || $stamp === self::WORKING_TREE_STAMP) {
            return null;
        }

        return $stamp;
    }

    /**
     * The composer-mode half: the installed package's own pretty version.
     * Pinned by candy-sql/tests/BinWiringTest.php as the launcher's answer
     * outside any archive (14a7e605a's contract, preserved through the phar
     * fusion).
     */
    public static function installedVersion(): string
    {
        if (!\class_exists(\Composer\InstalledVersions::class)) {
            return self::DEV;
        }

        try {
            return \Composer\InstalledVersions::isInstalled('sugarcraft/candy-sql')
                ? (string) \Composer\InstalledVersions::getPrettyVersion('sugarcraft/candy-sql')
                : self::DEV;
        } catch (\Throwable) {
            return self::DEV;
        }
    }

    /**
     * The real-filesystem path of the phar archive hosting this install, or ''
     * when this code is not running from an archive. Three identities, first
     * answer wins (measured on PHP 8.3.6 while porting the candy-code
     * DST-1 guard): Phar::running() names the archive for code executing
     * inside it; in the stub region running() answers '' and `__FILE__` names
     * the self-naming archive; the `phar://` prefix on this file's own
     * `__FILE__` covers shapes where a class was loaded from the archive but
     * running() stayed empty. Delegated to the pure hostArchiveFrom() so every
     * identity is pinable in-process (the candy-mail fusion shape).
     */
    public static function hostArchive(): string
    {
        return self::hostArchiveFrom(\Phar::running(false), __FILE__);
    }

    /**
     * @param string $running the Phar::running(false) answer
     * @param string $self    the calling file's __FILE__
     */
    public static function hostArchiveFrom(string $running, string $self): string
    {
        if ($running !== '') {
            return $running;
        }

        if (str_starts_with($self, 'phar://')) {
            if (preg_match('#^phar://(.+\.phar)(?:/|$)#', $self, $m) === 1) {
                return $m[1];
            }

            return '';
        }

        return str_ends_with($self, '.phar') ? $self : '';
    }
}

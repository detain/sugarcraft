<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher;

use RuntimeException;

/**
 * Tilde expansion for identity-file paths, ONE implementation shared by both
 * ingest paths (F-4, 2026-11-06).
 *
 * The ssh_config importer used to own this privately while the flat wishlist
 * reader passed identity values through untouched, so the same `~/keys/id`
 * spelling resolved on import and silently broke from the wishlist. Both
 * readers now route through {@see expand()}: a leading `~` resolves against
 * $HOME (passwd entry as fallback, loud refusal when neither exists) and
 * `~user` resolves via the passwd database.
 *
 * Expansion is deliberately leading-`~`-only and idempotent: absolute paths,
 * relative paths and mid-string tildes (`a~b`, `/x/~/y`) pass through byte
 * unchanged, so an already-expanded value survives a second visit. Tildes in
 * NON-identity fields (options, ProxyJump, host) are NOT touched here — they
 * stay verbatim argv and their expansion is client-dependent (OpenSSH expands
 * `-i` against the passwd home, not $HOME; dropbear/busybox never expand).
 */
final class IdentityPath
{
    private function __construct()
    {
    }

    /**
     * Expand a leading `~` in an identity-file path; everything else is
     * returned verbatim.
     */
    public static function expand(string $path): string
    {
        if (strncmp($path, '~', 1) !== 0) {
            return $path;
        }

        // Parse ~user/path or ~/path using a simple string scan instead of regex
        $len = strlen($path);
        if ($len >= 2 && $path[1] === '/') {
            // ~/path — current user's home
            return self::homeDirectory($path) . substr($path, 1);
        }

        // ~user/path — find the first slash
        $slashPos = strpos($path, '/');
        if ($slashPos === false) {
            // ~user with no slash — entire path is the user name
            $user = substr($path, 1);
            $rest = '';
        } else {
            $user = substr($path, 1, $slashPos - 1);
            $rest = substr($path, $slashPos);
        }

        if ($user === '') {
            // Edge case: just ~ (the ~/... shape was handled above)
            return self::homeDirectory($path) . $rest;
        }

        // ~user/path — try to resolve via posix_getpwnam
        if (function_exists('posix_getpwnam')) {
            $pw = @posix_getpwnam($user);
            if ($pw !== false && isset($pw['dir'])) {
                return $pw['dir'] . $rest;
            }
        }

        // Cannot resolve ~user — return path unchanged rather than
        // producing garbage like <home>user/...
        return $path;
    }

    /**
     * Home directory for `~` expansion: $HOME, then the passwd entry of the
     * effective user, then a loud refusal. The old code silently substituted
     * the literal '/root', so an import run without $HOME would have hunted
     * identity files under /root (2026-10-23).
     */
    private static function homeDirectory(string $path): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/');
        }

        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && isset($pw['dir']) && is_string($pw['dir']) && $pw['dir'] !== '') {
                return rtrim($pw['dir'], '/');
            }
        }

        throw new RuntimeException(Lang::t('config.home_unset', ['path' => $path]));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Files\Support;

/**
 * Home-directory resolution for the launcher (CF-18, closes 092).
 *
 * getenv('HOME') alone is POSIX-only: a Windows shell without MSYS exports
 * USERPROFILE (or the split HOMEDRIVE+HOMEPATH pair) and never HOME, which
 * parked the right pane at the drive root. The cascade takes POSIX HOME
 * first, then the Windows-native forms, and falls back to '/' only when
 * nothing resolves at all.
 */
final class Home
{
    /**
     * @param array<string, string|false>|null $env override for tests; null reads the live environment
     */
    public static function resolve(?array $env = null): string
    {
        $read = static function (string $key) use ($env): string {
            $value = $env === null ? \getenv($key) : ($env[$key] ?? false);

            return \is_string($value) && $value !== '' ? $value : '';
        };

        $home = $read('HOME');
        if ($home !== '') {
            return $home;
        }

        $userProfile = $read('USERPROFILE');
        if ($userProfile !== '') {
            return $userProfile;
        }

        $drive = $read('HOMEDRIVE');
        $path  = $read('HOMEPATH');
        if ($drive !== '' && $path !== '') {
            return $drive . $path;
        }

        return '/';
    }
}

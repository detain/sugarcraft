<?php

// codacy ignore tainted-filename
declare(strict_types=1);

namespace SugarCraft\Dashboard\Plugin;

/**
 * Discovers plugins in the plugin directory.
 *
 * Scans a directory for executable plugin files and returns their
 * paths. Plugins are executables that respond to the lattice JSON
 * protocol.
 *
 * SECURITY (finding sugar-dashboard-090): a discovered plugin is spawned,
 * so the directory it came from must be TRUSTWORTHY — owned by this user
 * and not group/world-writable. The old code fell back to `/tmp` when
 * HOME was unset, which turns any local user's dropped executable into
 * code running as the dashboard user; that fallback is gone. Home is
 * resolved through getenv() first (the half a process can actually set,
 * per the sugar-core/candy-code HomeDirectory doctrine this ports) and
 * nothing replaces it: hosts that run without HOME pass an explicit
 * CANDY_DASHBOARD_PLUGINS_DIR or get no plugins plus a logged reason.
 *
 * Mirrors the lattice discovery pattern.
 */
final class Discovery
{
    /**
     * Discover plugins in a directory.
     *
     * Untrusted targets (missing, not a directory, group/world-writable,
     * owned by another uid) yield an empty scan with the reason on
     * error_log — discovery fails closed, never open.
     *
     * @param string $directory Path to the plugins directory
     * @return list<string> Paths to discovered plugin executables
     */
    public static function scan(string $directory): array
    {
        // Resolve real path and validate the directory exists AND is a
        // trustworthy place to take executables from.
        $realDir = self::trustedDirectory($directory);
        if ($realDir === null) {
            return [];
        }

        $plugins = [];

        try {
            $iterator = new \FilesystemIterator($realDir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO);
        } catch (\UnexpectedValueException) {
            return [];
        }

        foreach ($iterator as $entry) {
            // Skip non-executable files
            // @codingStandardsIgnoreLine PHPCS_MEQP1_Security_DiscouragedFunction
            if (!$entry->isFile() || !$entry->isExecutable()) {
                continue;
            }

            $plugins[] = $entry->getPathname();
        }

        // FilesystemIterator hands entries out in raw directory order,
        // which varies across filesystems and over time. Sorting makes
        // plugin load order — and therefore panel order — deterministic.
        sort($plugins);

        return $plugins;
    }

    /**
     * Get the default plugin directory.
     *
     * Precedence: CANDY_DASHBOARD_PLUGINS_DIR (the explicit opt-in for
     * hosts without HOME), then XDG_CONFIG_HOME, then HOME + /.config.
     * Returns null — with a logged reason — when none is set; there is
     * deliberately no /tmp stand-in (finding sugar-dashboard-090: a
     * world-writable plugin source is arbitrary code execution as the
     * dashboard user).
     */
    public static function defaultDirectory(): ?string
    {
        $explicit = getenv('CANDY_DASHBOARD_PLUGINS_DIR');
        if ($explicit !== false && $explicit !== '') {
            return $explicit;
        }

        $home = getenv('HOME');
        if ($home === false || $home === '') {
            error_log('Discovery: no plugin directory (CANDY_DASHBOARD_PLUGINS_DIR, XDG_CONFIG_HOME and HOME all unset); refusing the world-writable /tmp fallback — discovery skipped (finding sugar-dashboard-090).');

            return null;
        }

        return (getenv('XDG_CONFIG_HOME') ?: rtrim($home, '/') . '/.config')
            . '/sugar-dashboard/plugins';
    }

    /**
     * Discover plugins in the default directory.
     *
     * @return list<string> Paths to discovered plugin executables
     */
    public static function scanDefault(): array
    {
        $directory = self::defaultDirectory();

        return $directory === null ? [] : self::scan($directory);
    }

    /**
     * The directory, realpath-resolved, iff it exists and is safe to take
     * executables from: not group/world-writable (0o0022), and — where the
     * platform says who we are — owned by this euid. This is the HomeDirectory
     * ::owned() doctrine from the trusted-config readers, ported to the
     * plugin scan: the answer to "does this path exist" was never the answer
     * to "can I run what's in it".
     */
    private static function trustedDirectory(string $directory): ?string
    {
        $real = realpath($directory);
        if ($real === false || !is_dir($real)) {
            return null;
        }

        $perms = @fileperms($real);
        if ($perms !== false && ($perms & 0o0022) !== 0) {
            error_log("Discovery: refusing to scan group/world-writable plugin directory '{$real}' (finding sugar-dashboard-090).");

            return null;
        }

        if (\function_exists('posix_geteuid')) {
            $owner = @fileowner($real);
            if ($owner !== false && $owner !== posix_geteuid()) {
                error_log("Discovery: refusing to scan plugin directory '{$real}' owned by uid {$owner}, not this process (uid " . posix_geteuid() . ').');

                return null;
            }
        }

        return $real;
    }
}

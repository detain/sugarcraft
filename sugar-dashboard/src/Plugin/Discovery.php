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
 * Mirrors the lattice discovery pattern.
 */
final class Discovery
{
    /**
     * Discover plugins in a directory.
     *
     * @param string $directory Path to the plugins directory
     * @return list<string> Paths to discovered plugin executables
     */
    public static function scan(string $directory): array
    {
        // Resolve real path and validate the directory exists
        $realDir = realpath($directory);
        if ($realDir === false || !is_dir($realDir)) {
            return [];
        }

        $plugins = [];

        try {
            $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO);
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
     * Uses XDG_CONFIG_HOME or falls back to ~/.config/sugar-dashboard/plugins.
     */
    public static function defaultDirectory(): string
    {
        // XDG wins; the fallback is the HOME directory itself joined to
        // '.config'. (This line once ran dirname() on HOME, collapsing
        // /home/user to /home and pointing every non-XDG host at a
        // /home/.config path that structurally cannot exist.)
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/tmp');

        return (getenv('XDG_CONFIG_HOME') ?: $home . '/.config')
            . '/sugar-dashboard/plugins';
    }

    /**
     * Discover plugins in the default directory.
     *
     * @return list<string> Paths to discovered plugin executables
     */
    public static function scanDefault(): array
    {
        return self::scan(self::defaultDirectory());
    }
}

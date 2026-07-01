<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plugin;

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
        // @codingStandardsIgnoreLine PHPCS_MEQP1_Security_DiscouragedFunction
        if (!is_dir($directory)) {
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

        return $plugins;
    }

    /**
     * Get the default plugin directory.
     *
     * Uses XDG_CONFIG_HOME or falls back to ~/.config/sugar-dash/plugins.
     */
    public static function defaultDirectory(): string
    {
        $configHome = getenv('XDG_CONFIG_HOME') ?: dirname($_SERVER['HOME'] ?? '/tmp') . '/.config';
        return $configHome . '/sugar-dash/plugins';
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

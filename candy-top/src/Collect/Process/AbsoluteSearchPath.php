<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Process;

/**
 * The PATH sweep both binary finders share: existing absolute directories
 * only, realpath'd, in PATH order, de-duplicated.
 *
 * Relative PATH entries are refused outright. The collectors run inside a
 * long-lived TUI whose working directory is nothing like the shell that
 * exported PATH, so a relative entry would resolve a helper against wherever
 * the monitor happens to be — the confusion {@see \SugarCraft\Top\Collect\Ipmi\Ipmi::find()}
 * documents, and a hijack the moment a bare argv[0] could follow it:
 * {@see AsyncCommand} gates spawns to executable paths, so the directories
 * must be filtered before a name is joined onto one, not after.
 */
final class AbsoluteSearchPath
{
    private function __construct()
    {
    }

    /**
     * @param string|null $path a PATH string; null reads the environment
     * @return list<string> realpath'd directories that exist, PATH order, no duplicates
     */
    public static function directories(?string $path = null): array
    {
        $dirs = [];
        $seen = [];
        foreach (explode(PATH_SEPARATOR, $path ?? (string) getenv('PATH')) as $dir) {
            if ($dir === '' || !\str_starts_with($dir, DIRECTORY_SEPARATOR)) {
                continue;
            }
            $real = \realpath($dir);
            if ($real === false || !is_dir($real) || isset($seen[$real])) {
                continue;
            }
            $seen[$real] = true;
            $dirs[] = $real;
        }

        return $dirs;
    }
}

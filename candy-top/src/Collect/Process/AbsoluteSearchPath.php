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
     * @param string|null $path      a PATH string; null reads the environment
     * @param string|null $osFamily  PHP_OS_FAMILY seam for tests; production passes null
     * @return list<string> realpath'd directories that exist, PATH order, no duplicates
     */
    public static function directories(?string $path = null, ?string $osFamily = null): array
    {
        // Drive-absolute entries ("C:\Windows\system32") are the Windows
        // shape of "absolute" (candy-top-038); the leading-separator rule
        // alone rejected every one of them. Acceptance must be OS-gated,
        // not merely pattern-based: on Linux "C:\foo" is a RELATIVE name
        // and realpath would resolve it against the monitor's cwd.
        $windows = ($osFamily ?? PHP_OS_FAMILY) === 'Windows';
        $dirs = [];
        $seen = [];
        // PATH's own separator is per-OS (';' on Windows, ':' elsewhere):
        // a drive entry contains ':', so splitting with the host's PATH_SEPARATOR
        // would shred "C:\Windows" the moment the seam forces Windows.
        $separator = $windows ? ';' : PATH_SEPARATOR;
        foreach (explode($separator, $path ?? (string) getenv('PATH')) as $dir) {
            $absolute = \str_starts_with($dir, DIRECTORY_SEPARATOR)
                || ($windows && preg_match('#^[A-Za-z]:[\\\\/]#', $dir) === 1);
            if ($dir === '' || !$absolute) {
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

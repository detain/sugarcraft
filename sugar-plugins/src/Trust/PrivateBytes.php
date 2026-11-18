<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Trust;

/**
 * The private-bytes gate: a file and its directory must be the process's own
 * and not world-writable before the host executes them.
 *
 * Name trust says who opted in, not that the bytes are intact. Another account
 * that can rewrite the file or its directory could swap the code under a
 * trusted name, so both are checked before every require.
 */
final class PrivateBytes
{
    /** Returns a refusal reason word, or null when the file and its directory are private. */
    public static function refusal(string $path): ?string
    {
        foreach ([$path, \dirname($path)] as $target) {
            if (!\file_exists($target)) {
                continue; // a vanished file fails the require, not this gate
            }

            if ((@\fileperms($target) & 0002) !== 0) {
                return 'world-writable';
            }

            if (\function_exists('posix_geteuid') && @\fileowner($target) !== false && @\fileowner($target) !== \posix_geteuid()) {
                return 'foreign-owner';
            }
        }

        return null;
    }
}

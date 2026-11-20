<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Trust;

/**
 * The private-bytes gate: a file and its directory must be the process's own
 * and not writable by the world before the host executes them.
 *
 * Name trust says who opted in, not that the bytes are intact. Another account
 * that can rewrite the file or its directory could swap the code under a
 * trusted name, so both are checked before every require. A world-writable
 * file or directory is refused, and so is one owned by another uid. Group write
 * is accepted, matching candy-code's refuseLooseBytes. The owner check needs a
 * uid to compare against, so it is skipped when ext-posix is absent.
 */
final class PrivateBytes
{
    /**
     * Returns a refusal reason word, or null when the file and its directory are private.
     *
     * $processUid is the uid the owner must match. Null reads the process's
     * effective uid; a test passes one explicitly so the foreign-owner branch runs
     * without root.
     */
    public static function refusal(string $path, ?int $processUid = null): ?string
    {
        $uid = $processUid ?? (\function_exists('posix_geteuid') ? \posix_geteuid() : null);

        foreach ([$path, \dirname($path)] as $target) {
            if (!\file_exists($target)) {
                continue; // a vanished file fails the require, not this gate
            }

            $perms = @\fileperms($target);

            if (($perms & 0002) !== 0) {
                return 'world-writable';
            }

            $owner = @\fileowner($target);

            if ($uid !== null && $owner !== false && $owner !== $uid) {
                return 'foreign-owner';
            }
        }

        return null;
    }
}

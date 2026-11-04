<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Format;

/**
 * Shared post-write permission policy for cassette files.
 *
 * A cassette records everything typed at the terminal (payloads, env,
 * commands), so every writer in the Format family lands files at 0600 —
 * the same contract Recorder's streaming open() enforces. Relying on the
 * ambient umask left owner-private recordings group- and world-readable on
 * default-umask systems.
 */
trait RestrictsCassettePermissions
{
    private static function restrictToOwner(string $path): void
    {
        if (!@chmod($path, 0600)) {
            throw new \RuntimeException("cannot restrict permissions on {$path}");
        }
    }
}

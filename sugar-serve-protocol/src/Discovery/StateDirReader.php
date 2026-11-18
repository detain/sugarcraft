<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol\Discovery;

use SugarCraft\ServeProtocol\Support\PrivateDirCheck;

/**
 * Locates an existing serve state directory without creating it.
 *
 * The read-only half of candy-code's `Server\StateDir::existing`: a missing
 * path is "no server here" (null); a path that exists but is not a private
 * directory is a refusal and throws, so a hostile or mis-permissioned state
 * directory is never read.
 */
final class StateDirReader
{
    private const LABEL = 'server state';

    private function __construct()
    {
    }

    /**
     * The trailing-slash-trimmed path when it is an existing private
     * directory, null when nothing exists at $path.
     *
     * @throws \RuntimeException when something exists at $path but is not a
     *                           private directory
     */
    public static function existing(string $path): ?string
    {
        $path = \rtrim($path, '/');
        clearstatcache(true, $path);
        if (@\lstat($path) === false) {
            return null;
        }
        $reason = PrivateDirCheck::refusal($path, self::LABEL);
        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }

        return $path;
    }
}

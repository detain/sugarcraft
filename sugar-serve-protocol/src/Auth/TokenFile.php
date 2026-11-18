<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol\Auth;

/**
 * The read path of the serve server's bearer token file.
 *
 * The token is 64 lowercase hex characters in a file named {@see FILE} inside
 * the state directory. Only the read is here: rotation and the write path stay
 * with the host, and the caller verifies the directory before calling
 * {@see read()}.
 */
final class TokenFile
{
    /** The file name, relative to the state directory. */
    public const FILE = 'token';

    /**
     * The byte limit on a token file. A token is 64 hex characters, so anything
     * longer is not one; the bound keeps an oversized file from being loaded whole.
     */
    public const MAX_BYTES = 128;

    private function __construct()
    {
    }

    /**
     * The stored token, or null when there is no usable one: the file is
     * absent, is a symbolic link or not a regular file, is longer than
     * {@see MAX_BYTES}, or its content is not a 64-character lowercase hex
     * token. A null return tells the host to mint a fresh token.
     *
     * @throws \RuntimeException when the file is present but cannot be read,
     *                           so an unreadable token is never mistaken for a
     *                           malformed one and replaced
     */
    public static function read(string $dir): ?string
    {
        $path = $dir . '/' . self::FILE;
        if (@\lstat($path) === false || !\is_file($path) || \is_link($path)) {
            return null;
        }

        // One byte past the limit is enough to tell an oversized file apart,
        // and the bound holds even if the file grows between lstat and read.
        $raw = @\file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($raw === false) {
            throw new \RuntimeException(\sprintf('the server token file %s exists but cannot be read; refusing to replace it', $path));
        }
        if (\strlen($raw) > self::MAX_BYTES) {
            return null;
        }
        $stored = \trim($raw);

        return \preg_match('/^[0-9a-f]{64}$/', $stored) === 1 ? $stored : null;
    }
}

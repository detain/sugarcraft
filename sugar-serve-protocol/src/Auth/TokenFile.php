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

    private function __construct()
    {
    }

    /**
     * The stored token, or null when there is no usable one: the file is
     * absent, is a symbolic link or not a regular file, or its trimmed content
     * is not a 64-character lowercase hex token. A null return tells the host
     * to mint a fresh token.
     *
     * The content is trimmed before it is checked, the same order as the host's
     * TokenStore::read, so a token file with trailing padding reads as the token.
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

        $raw = @\file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(\sprintf('the server token file %s exists but cannot be read; refusing to replace it', $path));
        }
        $stored = \trim($raw);

        return \preg_match('/^[0-9a-f]{64}$/', $stored) === 1 ? $stored : null;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\McpClient;

use SugarCraft\McpClient\JsonLines\Reader;

/**
 * The writer half of the JSON-Lines pair ({@see Reader} is the reader):
 * the ONE place the line-protocol encode flag set lives.
 *
 * Flags: INVALID_UTF8_SUBSTITUTE (a stray byte from a tool's output becomes
 * U+FFFD rather than failing the whole frame), UNESCAPED_SLASHES and
 * UNESCAPED_UNICODE (readable, shorter wire; byte-identical to what peers
 * parse either way).
 */
final class JsonLines
{
    public const FLAGS = \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    private function __construct()
    {
    }

    /**
     * One line of JSON, no trailing newline (the transport appends it).
     *
     * @param int $extraFlags OR-ed onto {@see FLAGS}, e.g. JSON_PRESERVE_ZERO_FRACTION
     *
     * @throws \JsonException on INF/NAN or other unencodable values — never an
     *         empty string, which a peer would silently ignore
     */
    public static function encode(mixed $value, int $extraFlags = 0): string
    {
        return json_encode($value, self::FLAGS | $extraFlags | \JSON_THROW_ON_ERROR);
    }

    public static function reader(?int $maxLineBytes = null): Reader
    {
        return new Reader($maxLineBytes);
    }
}

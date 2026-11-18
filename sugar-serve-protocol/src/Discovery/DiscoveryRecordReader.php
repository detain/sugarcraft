<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol\Discovery;

/**
 * The read-only half of candy-code's `Server\DiscoveryFile::read`: it finds
 * the `server.json` discovery record and decodes it, and nothing more.
 *
 * It returns the validated raw array, not a value object. The host keeps the
 * field-level rules (which keys are required, which fall back to a default)
 * in its own `fromArray`, so the record's shape is decided in one place.
 *
 * A record is a claim, not a fact: callers must still check that the named
 * process is alive before they signal it or report the server as running.
 */
final class DiscoveryRecordReader
{
    /** The JSON nesting limit. A discovery record is flat; anything deeper is not one. */
    public const MAX_DEPTH = 8;

    /**
     * The byte limit on a record. Read with a bound so an oversized file is
     * refused rather than loaded whole, which would exhaust memory before the
     * depth check ever runs.
     */
    public const MAX_BYTES = 65536;

    private const TYPE_MASK = 0o170000;

    private const TYPE_REGULAR = 0o100000;

    private function __construct()
    {
    }

    /**
     * The decoded record at $path, or null when there is none or it is not a
     * record: the path is absent, a symbolic link, or not a regular file, or
     * the content is longer than {@see MAX_BYTES}, or it is not JSON within
     * {@see MAX_DEPTH} that decodes to an array. A torn or hand-edited file is
     * no server.
     *
     * @return array<mixed>|null
     */
    public static function read(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @\lstat($path);
        if ($stat === false || ((int) $stat['mode'] & self::TYPE_MASK) !== self::TYPE_REGULAR) {
            return null;
        }

        // One byte past the limit is enough to tell an oversized file apart,
        // and the bound holds even if the file grows between lstat and read.
        $raw = @\file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($raw === false || \strlen($raw) > self::MAX_BYTES) {
            return null;
        }

        try {
            $data = \json_decode($raw, true, self::MAX_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }
}

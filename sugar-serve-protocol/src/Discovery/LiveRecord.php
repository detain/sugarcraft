<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol\Discovery;

/**
 * The URL of a running serve process, read from its discovery record.
 *
 * Combines the record reader with the host's field rules (a pid and a url are
 * required, a missing start time is "unknown") and the liveness claim. A
 * record that is absent, malformed or stale answers null, the same answer as
 * candy-code's `DiscoveryFile::read()` followed by `isLive()`.
 */
final class LiveRecord
{
    /** The record's file name inside the serve state directory. */
    public const FILE = 'server.json';

    private function __construct()
    {
    }

    /**
     * The record's url when the process it names is still running, else null.
     */
    public static function url(string $stateDir): ?string
    {
        $data = DiscoveryRecordReader::read(\rtrim($stateDir, '/') . '/' . self::FILE);
        if ($data === null) {
            return null;
        }

        $pid = $data['pid'] ?? null;
        $url = $data['url'] ?? null;
        if (!\is_int($pid) || $pid <= 0 || !\is_string($url) || $url === '') {
            return null;
        }

        $start = \is_int($data['procStartTime'] ?? null) ? $data['procStartTime'] : null;

        return ProcessIdentity::isSameProcess($pid, $start) ? $url : null;
    }
}

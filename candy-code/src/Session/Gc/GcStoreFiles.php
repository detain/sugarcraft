<?php

/**
 * File-size accounting for a session store and its WAL siblings.
 *
 * The db/-wal/-shm trio is the true on-disk footprint of the store; a lone
 * stat of session.db under-reports every live WAL instance. This mirrors what
 * the doctor probe measures (Cli\Bootstrap session-store row), so `gc status`
 * and `gc vacuum` never disagree with the doctor about what the store costs.
 */

declare(strict_types=1);

namespace SugarCraft\Code\Session\Gc;

final class GcStoreFiles
{
    /** @var list<string> journal siblings that ride the store's disk cost */
    private const SUFFIXES = ['', '-wal', '-shm'];

    /**
     * @return array{db:int,wal:int,shm:int,total:int,present:bool}
     */
    public static function sizes(string $dbPath): array
    {
        $db = 0;
        $wal = 0;
        $shm = 0;

        foreach (self::SUFFIXES as $index => $suffix) {
            $bytes = self::sizeOf($dbPath . $suffix);

            if ($index === 0) {
                $db = $bytes;
            } elseif ($index === 1) {
                $wal = $bytes;
            } else {
                $shm = $bytes;
            }
        }

        return [
            'db' => $db,
            'wal' => $wal,
            'shm' => $shm,
            'total' => $db + $wal + $shm,
            'present' => $db > 0 || is_file($dbPath),
        ];
    }

    private static function sizeOf(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }

        clearstatcache(true, $path);
        $size = filesize($path);

        return $size === false ? 0 : $size;
    }

    /**
     * Bytes available on the filesystem holding the store (0 when unreadable).
     */
    public static function freeSpace(string $dbPath): int
    {
        $free = @disk_free_space(\dirname($dbPath));

        return $free === false ? 0 : (int) $free;
    }
}

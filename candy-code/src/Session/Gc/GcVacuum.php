<?php

/**
 * Rewrite paths for `candy-code gc vacuum`.
 *
 * A rewrite (full VACUUM or the one-time auto_vacuum flip, which only takes
 * effect through a full rewrite) runs on a freshly opened read-write
 * connection that never sits inside a transaction — VACUUM refuses one. The
 * incremental drain path (PRAGMA incremental_vacuum) is rewrite-free and is
 * the mode a flipped store settles into. Lock contention surfaces as a
 * PDOException whose advice the caller grounds in flock truth, never pgrep.
 */

declare(strict_types=1);

namespace SugarCraft\Code\Session\Gc;

use PDO;
use SugarCraft\Code\Support\SqliteConnect;

final class GcVacuum
{
    public function __construct(private readonly string $dbPath)
    {
    }

    /**
     * @return array{
     *     performed:bool,
     *     mode:string,
     *     flipped:bool,
     *     auto_vacuum_before:int,
     *     auto_vacuum_after:int,
     *     freelist_before:int,
     *     freelist_after:int,
     *     pages_released:int,
     *     before:array{db:int,wal:int,shm:int,total:int,present:bool},
     *     after:array{db:int,wal:int,shm:int,total:int,present:bool},
     *     free_bytes:int
     * }
     */
    public function run(bool $incremental): array
    {
        $before = GcStoreFiles::sizes($this->dbPath);
        $connection = SqliteConnect::open($this->dbPath);

        $autoVacuumBefore = $this->autoVacuum($connection);
        $freelistBefore = $this->freelist($connection);
        $flipped = false;
        $mode = 'full';

        if ($incremental && $autoVacuumBefore === GcInventory::AUTO_VACUUM_INCREMENTAL) {
            $mode = 'drain';
            $connection->exec('PRAGMA incremental_vacuum');
        } elseif ($incremental) {
            $mode = 'flip';
            $flipped = true;
            $connection->exec('PRAGMA auto_vacuum = ' . (string) GcInventory::AUTO_VACUUM_INCREMENTAL);
            $connection->exec('VACUUM');
        } else {
            $connection->exec('VACUUM');
        }

        $freelistAfter = $this->freelist($connection);

        return [
            'performed' => true,
            'mode' => $mode,
            'flipped' => $flipped,
            'auto_vacuum_before' => $autoVacuumBefore,
            'auto_vacuum_after' => $this->autoVacuum($connection),
            'freelist_before' => $freelistBefore,
            'freelist_after' => $freelistAfter,
            'pages_released' => max(0, $freelistBefore - $freelistAfter),
            'before' => $before,
            'after' => GcStoreFiles::sizes($this->dbPath),
            'free_bytes' => GcStoreFiles::freeSpace($this->dbPath),
        ];
    }

    private function autoVacuum(PDO $connection): int
    {
        return (int) $connection->query('PRAGMA auto_vacuum')->fetchColumn();
    }

    private function freelist(PDO $connection): int
    {
        return (int) $connection->query('PRAGMA freelist_count')->fetchColumn();
    }
}

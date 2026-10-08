<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Mem;

/**
 * One line-up entry of the disks section — btop's `disk_info` as the
 * draw loop reads it: the map key (mount point, or `swap`), display name,
 * space figures and whether IO history exists (btop `io_read.empty()`).
 */
final class DiskRow
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly int $total,
        public readonly int $used,
        public readonly int $free,
        public readonly int $usedPercent,
        public readonly int $freePercent,
        public readonly bool $io,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Mem;

use SugarCraft\Top\Collect\DiskDevice;
use SugarCraft\Top\Collect\Mount;

/**
 * One {@see DisksSource} sample: the selected mounts ("/" first, then
 * mount-table order) and, per mount point, the block device its IO is
 * read from — absent when the device has no stat (btop `disk.stat`
 * empty: tmpfs, network shares, zfs datasets), which hides that disk's
 * IO readouts and graphs.
 */
final class DisksSample
{
    /**
     * @param list<Mount>               $mounts
     * @param array<string, DiskDevice> $io     keyed by mount point
     */
    public function __construct(
        public readonly array $mounts,
        public readonly array $io,
    ) {
    }
}

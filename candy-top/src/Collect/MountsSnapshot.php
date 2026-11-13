<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * One mount-table sample: filesystems with their space figures in bytes,
 * "/" first then /proc/mounts order (btop disks_order).
 *
 * A mount whose statvfs failed never enters the list (the collector
 * ignores it like btop and remembers to skip), so total/free/used are
 * always measured bytes here — the sentinel class lives on the probe
 * side, not in this DTO.
 */
final class MountsSnapshot
{
    /**
     * @param list<Mount> $mounts "/" first, then /proc/mounts order (btop disks_order)
     */
    public function __construct(
        public readonly array $mounts,
    ) {
    }
}

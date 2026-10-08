<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

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

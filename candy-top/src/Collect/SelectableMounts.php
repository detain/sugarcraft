<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * A mounts collector the disks section re-gates with the CURRENT
 * disks_filter / use_fstab / only_physical / zfs_hide_datasets at collect
 * time. Implemented by the Linux {@see Mounts} and {@see FreeBsd\Mounts}.
 */
interface SelectableMounts
{
    public function withSelection(MountSelection $selection): SelectableMounts;

    public function selection(): MountSelection;

    /** @return array{0: MountsSnapshot, 1: SelectableMounts} */
    public function sample(): array;
}

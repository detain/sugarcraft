<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * The ctr box's sample — btop PR #1873 `Ctr::current_ctrs` after
 * `Ctr::collect`, sorted by name, plus the denominators the box needs.
 */
final class ContainerSnapshot
{
    /**
     * @param list<ContainerInfo> $containers sorted by name (btop rng::sort by ctr_info::name)
     * @param int $memTotal MemTotal in bytes (the mem colour / default limit), UNMEASURED_INT when unknown
     */
    public function __construct(
        public readonly array $containers,
        public readonly int $memTotal = Sentinel::UNMEASURED_INT,
        public readonly int $coreCount = 1,
    ) {
    }

    public function count(): int
    {
        return \count($this->containers);
    }

    /** The container whose cgroup path is `$path`, or null. */
    public function find(string $path): ?ContainerInfo
    {
        foreach ($this->containers as $c) {
            if ($c->path === $path) {
                return $c;
            }
        }

        return null;
    }

    /** Index of `$path` in the sorted list, or null (btop rng::find). */
    public function indexOf(string $path): ?int
    {
        foreach ($this->containers as $i => $c) {
            if ($c->path === $path) {
                return $i;
            }
        }

        return null;
    }
}

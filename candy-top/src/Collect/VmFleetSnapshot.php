<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * One VM dashboard sample: every running libvirt/KVM guest found under
 * the machined cgroup tree (scope order — the panel sorts), plus the host
 * figures the dashboard's allocation summary compares against.
 */
final class VmFleetSnapshot
{
    /**
     * @param list<VmGuest> $guests
     * @param int  $hostMem MemTotal bytes, UNMEASURED_INT when unknown
     * @param bool $limited  no libvirt XML was readable (not root): vCPUs and
     *                       RAM come from the qemu command lines, NICs by MAC
     * @param bool $baseline the first sample, or the first after a gap: no
     *                       rates yet, histories start over
     */
    public function __construct(
        public readonly array $guests,
        public readonly int $hostMem = Sentinel::UNMEASURED_INT,
        public readonly bool $limited = false,
        public readonly bool $baseline = false,
    ) {
    }

    public function count(): int
    {
        return \count($this->guests);
    }

    public function find(string $path): ?VmGuest
    {
        foreach ($this->guests as $guest) {
            if ($guest->path === $path) {
                return $guest;
            }
        }

        return null;
    }

    /** Sum of the guests' vCPUs (unknown ones count 0). */
    public function vcpus(): int
    {
        return array_sum(array_map(static fn (VmGuest $g): int => max(0, $g->vcpus), $this->guests));
    }

    /** Sum of the guests' configured RAM in bytes (unknown ones count 0). */
    public function memory(): int
    {
        return array_sum(array_map(static fn (VmGuest $g): int => max(0, $g->memBytes), $this->guests));
    }
}

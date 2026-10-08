<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * The container — or KVM/QEMU virtual machine — a process runs in, as
 * recognised from its cgroup path (btop #1873 Ctr::ctr_info; VMs: plan
 * Wave U1b, see Vm).
 *
 *  - `engine`: "docker", "podman", "k8s", "containerd", "nerdctl", "crio",
 *    … (the OCI cgroup prefix, normalised), "lxc", "nspawn", or
 *    "container" for a bare 64-hex id under an unknown parent, or
 *    Vm::ENGINE ("kvm") for a QEMU guest;
 *  - `id`: the 12-hex short id for OCI runtimes, the container name for
 *    lxc / nspawn, the raw VM name (guest= else the scope's) for "kvm";
 *  - `name`: what to print — the short id or the name, restricted to
 *    [A-Za-z0-9_.:-] (anything else becomes '?') because cgroup names are
 *    arbitrary bytes and land on the terminal. Docker names from the
 *    engine socket are a post-v1 step (btop's ctr box);
 *  - `cgroupPath`: the container root's cgroup path, raw (an identity key
 *    for grouping processes and for /sys/fs/cgroup stats — never print it
 *    unsanitised); '' for a non-libvirt qemu, which owns no VM cgroup;
 *  - `vm`: the VM facts (domain id, uuid, vCPUs, memory, both names) when
 *    engine is "kvm", else null.
 *
 * WHY a VM is a ContainerRef rather than a separate VmRef on Process: every
 * consumer of "which guest does this process belong to" — the proc-list
 * tag, proc_filter_containers, grouping by cgroupPath — wants VMs and
 * containers through one field; a parallel field would have to be checked
 * everywhere. The VM-only facts ride along in `vm`.
 */
final class ContainerRef
{
    public function __construct(
        public readonly string $engine,
        public readonly string $id,
        public readonly string $name,
        public readonly string $cgroupPath,
        public readonly ?VmInfo $vm = null,
    ) {
    }

    public function isVm(): bool
    {
        return $this->vm !== null;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * The container a process runs in, as recognised from its cgroup path
 * (btop #1873 Ctr::ctr_info).
 *
 *  - `engine`: "docker", "podman", "k8s", "containerd", "nerdctl", "crio",
 *    … (the OCI cgroup prefix, normalised), "lxc", "nspawn", or
 *    "container" for a bare 64-hex id under an unknown parent;
 *  - `id`: the 12-hex short id for OCI runtimes, the container name for
 *    lxc / nspawn;
 *  - `name`: what to print — the short id or the name, restricted to
 *    [A-Za-z0-9_.:-] (anything else becomes '?') because cgroup names are
 *    arbitrary bytes and land on the terminal. Docker names from the
 *    engine socket are a post-v1 step (btop's ctr box);
 *  - `cgroupPath`: the container root's cgroup path, raw (an identity key
 *    for grouping processes and for /sys/fs/cgroup stats — never print it
 *    unsanitised).
 */
final class ContainerRef
{
    public function __construct(
        public readonly string $engine,
        public readonly string $id,
        public readonly string $name,
        public readonly string $cgroupPath,
    ) {
    }
}

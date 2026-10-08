<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * What the ctr box samples after a process scan — btop PR #1873
 * `Ctr::collect(procs)`: group the processes by container, then read each
 * container's own figures. Live: {@see Containers} (cgroup v2 + the docker
 * socket); `--fake`: {@see \SugarCraft\Top\Source\Fake\FakeContainers}.
 *
 * Immutable: the returned collector carries the cpu baselines and the
 * known containers (and their resolved names) to the next call.
 */
interface ContainerCollector
{
    /** False where containers are never read (FreeBSD): the box stays empty and nothing is sampled for it. */
    public function enabled(): bool;

    /**
     * Whether at least `$minWindowUs` passed since the previous collect()
     * (always true before the first). Called inside a Cmd only — it may
     * read the clock. A proc rescan outside the data tick (a sort change,
     * Enter, a toggle) would otherwise give the cgroup cpu delta a
     * window of a few milliseconds and push an extra history point.
     */
    public function due(int $minWindowUs): bool;

    /**
     * Copy with the sampling window reset (the box was just re-shown): the
     * next collect() is due at once and, having no cpu baseline, shows the
     * process sums; known containers, names and histories are kept.
     */
    public function rebased(): ContainerCollector;

    /**
     * Copy that lists libvirt/KVM guests beside the containers (on) or
     * leaves them out as btop does (off) — the ctr_show_vms option,
     * re-applied from the current config before every collect().
     */
    public function withVms(bool $vms): ContainerCollector;

    /**
     * @param list<Process> $processes one process scan (any order)
     * @param int  $memTotal   MemTotal bytes from the same scan (UNMEASURED_INT when unknown)
     * @param int  $cores      logical cpus (btop Shared::coreCount)
     * @param bool $perCore    proc_per_core: cpu in percent of one core
     * @param int  $historyCap cpu-graph samples kept per container (btop: Term::width)
     * @return array{0: ContainerSnapshot, 1: ContainerCollector}
     */
    public function collect(array $processes, int $memTotal, int $cores, bool $perCore, int $historyCap): array;
}

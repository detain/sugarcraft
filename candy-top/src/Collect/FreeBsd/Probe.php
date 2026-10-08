<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\FreeBsd;

/**
 * Everything a FreeBSD collector asks the machine, behind one seam.
 *
 * WHY an interface rather than the Linux collectors' Paths root: FreeBSD
 * publishes its counters through sysctl(3) and a handful of base-system
 * tools (netstat, iostat, ps, swapinfo, mount), not through a pseudo
 * filesystem a fixture tree can stand in for. Tests hand the collectors a
 * probe that answers from captured text; {@see LiveProbe} asks the host.
 * The clocks live here too so a fixture probe controls the interval every
 * rate is computed over.
 *
 * Every method is total: an absent OID, a missing binary, EPERM or a
 * timeout comes back as "nothing", never as an exception (the sentinel
 * law, {@see \SugarCraft\Top\Collect\Sentinel}).
 */
interface Probe
{
    /**
     * Values for the OIDs (or whole subtrees) that exist; absent ones are
     * simply missing from the result.
     *
     * @param list<string> $names
     * @return array<string, string> OID => raw sysctl(8) text value
     */
    public function sysctl(array $names): array;

    /**
     * Run a base-system tool; argv[0] is a bare tool name.
     *
     * @param list<string> $argv
     * @return string|null stdout on exit status 0, else null
     */
    public function run(array $argv): ?string;

    /** A regular file's contents (e.g. /etc/fstab); null when unreadable. */
    public function file(string $path): ?string;

    /**
     * statvfs for a mount point.
     *
     * @return array{0: int, 1: int}|null [total, free-to-unprivileged] bytes
     */
    public function space(string $mountpoint): ?array;

    /** Monotonic seconds (rates). */
    public function monotonic(): float;

    /** Wall-clock UNIX seconds (uptime from kern.boottime). */
    public function epoch(): float;
}

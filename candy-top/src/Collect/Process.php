<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * One row of the process table.
 *
 * Units differ between the two CPU figures, deliberately (btop's cpu_p vs
 * cpu_c):
 *  - `cpu`: percent of the interval since the previous scan, rounded to
 *    0.1. By default a share of the WHOLE machine (0..100, so one busy
 *    thread on 8 cores reads 12.5); with proc_per_core a share of ONE core
 *    (0..100 × cores). Sentinel::UNMEASURED on the collector's first scan.
 *  - `cpuCumulative`: lifetime CPU time over lifetime wall time, percent of
 *    ONE core and unrounded (a 4-thread process can read 400). UNMEASURED
 *    when /proc/uptime is unreadable.
 *  - `mem`: resident bytes (RSS pages × page size).
 */
final class Process
{
    public function __construct(
        public readonly int $pid,
        public readonly int $ppid,
        public readonly string $name,
        public readonly string $cmd,
        public readonly string $user,
        public readonly int $uid,
        public readonly string $state,
        public readonly int $threads,
        public readonly int $nice,
        public readonly int $mem,
        public readonly float $cpu,
        public readonly float $cpuCumulative,
    ) {
    }
}

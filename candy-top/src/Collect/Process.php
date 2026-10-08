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
 *
 * Additive fields (btop upstream PRs, Wave U1):
 *  - `cmdBasenameOffset` (#1859): byte offset in `cmd` where argv[0]'s
 *    basename starts ("/usr/bin/firefox --x" → 9), recorded before the
 *    NUL→space flattening so spaces in the path and slashes in later args
 *    cannot confuse it. cmdBasename() applies it (proc_command_basename);
 *  - `ioRead` / `ioWrite` (#1823): bytes/second from /proc/[pid]/io
 *    read_bytes / write_bytes (storage-layer bytes; cancelled_write_bytes
 *    is NOT subtracted, matching btop). UNMEASURED when not collected
 *    (ProcList::withIo off), unreadable (EACCES: another uid without
 *    CAP_SYS_PTRACE — render "-", never 0) or on the first sighting;
 *    `ioReadTotal` / `ioWriteTotal` the raw counters, UNMEASURED_INT
 *    likewise;
 *  - `container` (#1873): the container recognised from the cgroup path,
 *    or the KVM/QEMU guest (engine "kvm", Wave U1b) from cgroup + cmdline;
 *    null for a host process (read once per process lifetime).
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
        public readonly int $cmdBasenameOffset = 0,
        public readonly float $ioRead = Sentinel::UNMEASURED,
        public readonly float $ioWrite = Sentinel::UNMEASURED,
        public readonly int $ioReadTotal = Sentinel::UNMEASURED_INT,
        public readonly int $ioWriteTotal = Sentinel::UNMEASURED_INT,
        public readonly ?ContainerRef $container = null,
    ) {
    }

    /** `cmd` starting at argv[0]'s basename ("firefox --x"); `cmd` itself when no offset applies. */
    public function cmdBasename(): string
    {
        return $this->cmdBasenameOffset > 0 && $this->cmdBasenameOffset < strlen($this->cmd)
            ? substr($this->cmd, $this->cmdBasenameOffset)
            : $this->cmd;
    }

    /** read + write bytes/second (btop "io total" sort); UNMEASURED when either is. */
    public function ioTotal(): float
    {
        return $this->ioRead >= 0.0 && $this->ioWrite >= 0.0 ? $this->ioRead + $this->ioWrite : Sentinel::UNMEASURED;
    }
}

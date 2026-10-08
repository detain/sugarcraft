<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * The process table from /proc/[pid]/{stat,status,cmdline}.
 *
 * Mirrors aristocratos/btop Proc::collect (src/linux/btop_collect.cpp):
 *  - the denominator is the aggregate /proc/stat line's first 8 fields
 *    (user..steal, guest excluded), so cpu% = 100 × Δ(utime+stime) /
 *    Δcputimes is a share of the WHOLE machine; proc_per_core multiplies
 *    by the core count so one saturated thread reads 100%;
 *  - a pid seen for the first time has no previous cpu time and reads 0%
 *    (btop seeds cpu_t with the current value); the collector's very first
 *    scan has no interval at all and reads UNMEASURED;
 *  - mem = RSS pages × page size (btop: "can be inaccurate, but parsing
 *    smaps increases total cpu usage by ~20x");
 *  - name/cmd/user are read once per process and cached — only stat is
 *    re-read each tick. The cache key includes starttime so a recycled pid
 *    is recognised as a new process, not inherited history;
 *  - proc_filter_kernel drops kthreadd (pid 2) and its children.
 *
 * Races (plan §7 R2): any per-pid file that vanishes or comes back
 * malformed mid-scan skips that pid silently; the next scan re-snapshots.
 * The process name comes from stat's parenthesised comm, split on the LAST
 * ')' — a name may itself contain spaces and parentheses.
 *
 * User names (plan §7 R1): posix_getpwuid behind function_exists, cached
 * per uid for the collector's lifetime; without ext-posix (or for a uid
 * with no passwd entry) the numeric uid is shown, as btop does.
 *
 * Known risk, shared with btop/htop/ps: reading /proc/[pid]/cmdline takes
 * the target's mmap lock, so a process stuck holding it (e.g. in D state
 * mid page-fault on dead NFS) can block that read. It is paid once per
 * process lifetime here (cached with name/user), not every scan, which
 * bounds the exposure but cannot remove it from userspace.
 */
final class ProcList
{
    private const int KTHREADD = 2;

    /**
     * @param \Closure(int): ?string $userLookup uid → login name
     * @param array<int, array{start: int, cpuT: int, name: string, cmd: string, user: string, uid: int}> $known
     * @param array<int, string> $users uid → resolved name cache
     */
    private function __construct(
        private readonly Paths $paths,
        private readonly \Closure $userLookup,
        private readonly bool $perCore,
        private readonly bool $filterKernel,
        private readonly int $pageSize,
        private readonly int $clkTck,
        private readonly ?int $previousTotal,
        private readonly array $known,
        private readonly array $users,
    ) {
    }

    /**
     * @param int|null $pageSize bytes per RSS page; null detects it from
     *                           /proc/self/smaps (btop: sysconf(_SC_PAGE_SIZE)), else 4096
     * @param (\Closure(int): ?string)|null $userLookup defaults to posix_getpwuid when ext-posix is loaded
     */
    public static function new(
        ?Paths $paths = null,
        bool $perCore = false,
        bool $filterKernel = false,
        ?int $pageSize = null,
        int $clkTck = 100,
        ?\Closure $userLookup = null,
    ): self {
        $paths ??= Paths::system();

        return new self(
            $paths,
            $userLookup ?? self::posixLookup(...),
            $perCore,
            $filterKernel,
            max(1, $pageSize ?? self::detectPageSize($paths)),
            max(1, $clkTck),
            null,
            [],
            [],
        );
    }

    /**
     * @return array{0: ProcSnapshot, 1: self}
     */
    public function sample(): array
    {
        [$total, $coreCount] = $this->readCpuTimes();
        $uptime = $this->readUptime();
        $interval = $total !== null && $this->previousTotal !== null ? max(1, $total - $this->previousTotal) : null;
        $multiplier = $this->perCore ? max(1, $coreCount) : 1;

        $known = [];
        $users = $this->users;
        $processes = [];

        foreach (Read::entries($this->paths->proc()) as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }
            $pid = (int) $entry;
            $dir = $this->paths->proc($entry);

            $stat = self::parseStat(Read::file($dir . '/stat'));
            if ($stat === null) {
                continue; // exited between scandir and open, or truncated
            }
            if ($this->filterKernel && ($pid === self::KTHREADD || $stat['ppid'] === self::KTHREADD)) {
                continue;
            }

            $cached = $this->known[$pid] ?? null;
            if ($cached === null || $cached['start'] !== $stat['start']) {
                $info = $this->readStatic($dir, $users);
                if ($info === null) {
                    continue;
                }
                $cached = ['start' => $stat['start'], 'cpuT' => $stat['cpuT']] + $info;
            }

            $cpu = Sentinel::UNMEASURED;
            if ($interval !== null) {
                $raw = round($multiplier * 1000.0 * ($stat['cpuT'] - $cached['cpuT']) / $interval) / 10.0;
                $cpu = max(0.0, min(100.0 * max(1, $coreCount), $raw));
            }
            $lifetime = $uptime >= 0.0 ? $uptime * $this->clkTck - $stat['start'] : -1.0;
            $cumulative = $uptime >= 0.0 ? 100.0 * $stat['cpuT'] / max(1.0, $lifetime) : Sentinel::UNMEASURED;

            $known[$pid] = ['cpuT' => $stat['cpuT']] + $cached;
            $processes[] = new Process(
                $pid,
                $stat['ppid'],
                $stat['name'],
                $cached['cmd'],
                $cached['user'],
                $cached['uid'],
                $stat['state'],
                $stat['threads'],
                $stat['nice'],
                $stat['rss'] * $this->pageSize,
                $cpu,
                $cumulative,
            );
        }

        return [
            new ProcSnapshot($processes, $coreCount),
            new self(
                $this->paths,
                $this->userLookup,
                $this->perCore,
                $this->filterKernel,
                $this->pageSize,
                $this->clkTck,
                $total ?? $this->previousTotal,
                $known,
                $users,
            ),
        ];
    }

    /**
     * @return array{name: string, state: string, ppid: int, cpuT: int, nice: int, threads: int, start: int, rss: int}|null
     */
    private static function parseStat(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $open = strpos($raw, '(');
        $close = strrpos($raw, ')');
        if ($open === false || $close === false || $close < $open) {
            return null;
        }
        // Fields after "pid (comm) ": state is field 3, so index = field − 3.
        $f = explode(' ', trim(substr($raw, $close + 2)));
        if (count($f) < 22) {
            return null;
        }

        return [
            'name' => substr($raw, $open + 1, $close - $open - 1),
            'state' => $f[0],
            'ppid' => (int) $f[1],
            'cpuT' => (int) $f[11] + (int) $f[12],
            'nice' => (int) $f[16],
            'threads' => (int) $f[17],
            'start' => (int) $f[19],
            'rss' => max(0, (int) $f[21]),
        ];
    }

    /**
     * cmdline + Uid, read once per process lifetime.
     *
     * @param array<int, string> $users uid → name cache, extended in place
     * @return array{cmd: string, user: string, uid: int}|null
     */
    private function readStatic(string $dir, array &$users): ?array
    {
        $cmdline = Read::file($dir . '/cmdline');
        $status = Read::file($dir . '/status');
        if ($cmdline === null || $status === null || preg_match('/^Uid:\s+(\d+)/m', $status, $m) !== 1) {
            return null;
        }
        $uid = (int) $m[1];
        if (!isset($users[$uid])) {
            $users[$uid] = ($this->userLookup)($uid) ?? (string) $uid;
        }
        // NUL-separated argv; kernel threads have an empty cmdline. btop caps at 1000.
        $cmd = substr(rtrim(str_replace("\0", ' ', $cmdline)), 0, 1000);

        return ['cmd' => $cmd, 'user' => $users[$uid], 'uid' => $uid];
    }

    /**
     * @return array{0: int|null, 1: int} [aggregate cputimes (first 8 fields), core count]
     */
    private function readCpuTimes(): array
    {
        $raw = Read::file($this->paths->proc('stat'));
        if ($raw === null) {
            return [null, 0];
        }
        $total = null;
        $cores = 0;
        foreach (explode("\n", $raw) as $line) {
            if (str_starts_with($line, 'cpu ')) {
                $fields = preg_split('/\s+/', trim(substr($line, 4))) ?: [];
                $total = array_sum(array_map('intval', array_slice($fields, 0, 8)));
            } elseif (preg_match('/^cpu\d+ /', $line) === 1) {
                $cores++;
            }
        }

        return [$total, $cores];
    }

    private function readUptime(): float
    {
        $first = explode(' ', Read::line($this->paths->proc('uptime')) ?? '')[0];

        return is_numeric($first) ? (float) $first : Sentinel::UNMEASURED;
    }

    /**
     * PHP has no sysconf(), and RSS in /proc/[pid]/stat is in pages: 4096
     * is wrong on 16K/64K-page arm64 and ppc64 kernels, inflating or
     * shrinking every MEM column. The first mapping in our own smaps names
     * the kernel page size; only its head is read, never the whole file.
     */
    private static function detectPageSize(Paths $paths): int
    {
        $handle = @fopen($paths->proc('self/smaps'), 'r');
        if ($handle === false) {
            return 4096;
        }
        try {
            for ($i = 0; $i < 64 && ($line = fgets($handle)) !== false; $i++) {
                if (preg_match('/^KernelPageSize:\s+(\d+)\s+kB/', $line, $m) === 1 && (int) $m[1] > 0) {
                    return (int) $m[1] * 1024;
                }
            }
        } finally {
            fclose($handle);
        }

        return 4096;
    }

    private static function posixLookup(int $uid): ?string
    {
        if (!function_exists('posix_getpwuid')) {
            return null;
        }
        $entry = @posix_getpwuid($uid);

        return is_array($entry) && ($entry['name'] ?? '') !== '' ? $entry['name'] : null;
    }
}

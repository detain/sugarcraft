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
 *
 * Wave U1 additions (btop upstream PRs):
 *  - #1859: argv[0]'s basename offset is computed from the first
 *    NUL-delimited cmdline segment, before flattening (basenameOffset()).
 *    Deliberate deviation: when the whole cmdline is ONE segment
 *    containing a space and it is a rewritten title rather than a path —
 *    it does not start with '/', or its first space-delimited token ends
 *    with ':' (nginx "nginx: master process /usr/sbin/nginx", sshd
 *    "sshd: joe@pts/0") — only that first token is treated as the path;
 *    btop would cut those at the last '/' of the title ("0"). An absolute
 *    single segment is a path, possibly with a space in it: it is cut
 *    before its first " -" option when there is one (chrome joins its argv
 *    with spaces: "/opt/google/chrome/chrome --type=renderer
 *    --user-data-dir=/home/x" → "chrome"), else the whole segment gets the
 *    plain last-'/' rule (an argument-less "/opt/My App/app" → "app"). A
 *    space-joined absolute argv with a slashed non-option argument still
 *    cuts at that argument's last '/', as btop does;
 *  - #1823: /proc/[pid]/io read_bytes / write_bytes rates over the
 *    /proc/uptime delta (btop: Δbytes / max(0.1, Δuptime)). One extra
 *    open per pid per scan, so it is opt-in (withIo) — the view turns it
 *    on only when an IO column, an io sort or the detail view needs it.
 *    Unreadable (EACCES for other uids) → UNMEASURED, never 0;
 *  - #1873: the container from /proc/[pid]/cgroup (Cgroup::fromProcFile),
 *    cached with cmd/user — a process moved to another cgroup later keeps
 *    the first value, as in btop.
 */
final class ProcList
{
    private const int KTHREADD = 2;

    /**
     * @param \Closure(int): ?string $userLookup uid → login name
     * @param array<int, array{start: int, cpuT: int, cmd: string, user: string, uid: int, argv0: int, container: ?ContainerRef, ioR?: int, ioW?: int}> $known
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
        private readonly bool $readIo = false,
        private readonly ?float $previousUptime = null,
    ) {
    }

    /**
     * @param int|null $pageSize bytes per RSS page; null detects it from
     *                           /proc/self/smaps (btop: sysconf(_SC_PAGE_SIZE)), else 4096
     * @param (\Closure(int): ?string)|null $userLookup defaults to posix_getpwuid when ext-posix is loaded
     * @param bool $readIo read /proc/[pid]/io each scan (see withIo())
     */
    public static function new(
        ?Paths $paths = null,
        bool $perCore = false,
        bool $filterKernel = false,
        ?int $pageSize = null,
        int $clkTck = 100,
        ?\Closure $userLookup = null,
        bool $readIo = false,
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
            $readIo,
        );
    }

    /**
     * Toggle the per-pid /proc/[pid]/io read (btop #1823). Off costs
     * nothing; on, rates appear from the second scan that reads io.
     */
    public function withIo(bool $readIo): self
    {
        return $readIo === $this->readIo ? $this : new self(
            $this->paths,
            $this->userLookup,
            $this->perCore,
            $this->filterKernel,
            $this->pageSize,
            $this->clkTck,
            $this->previousTotal,
            $this->known,
            $this->users,
            $readIo,
            $this->previousUptime,
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
        $ioSeconds = $this->readIo && $uptime >= 0.0 && $this->previousUptime !== null ? max(0.1, $uptime - $this->previousUptime) : null;
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

            $io = [Sentinel::UNMEASURED, Sentinel::UNMEASURED, Sentinel::UNMEASURED_INT, Sentinel::UNMEASURED_INT];
            if ($this->readIo) {
                [$ioR, $ioW] = self::parseIo(Read::file($dir . '/io'));
                $io = [
                    self::rate($ioR, $cached['ioR'] ?? null, $ioSeconds),
                    self::rate($ioW, $cached['ioW'] ?? null, $ioSeconds),
                    $ioR,
                    $ioW,
                ];
                $cached['ioR'] = $ioR;
                $cached['ioW'] = $ioW;
            } else {
                unset($cached['ioR'], $cached['ioW']);
            }

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
                $cached['argv0'],
                $io[0],
                $io[1],
                $io[2],
                $io[3],
                $cached['container'],
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
                $this->readIo,
                $uptime >= 0.0 ? $uptime : null,
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
     * btop #1859 Tools::command_basename_offset over the raw argv[0]:
     * "" / "firefox" / "/" / "/usr/bin/" → 0, "./firefox" → 2,
     * "../bin/firefox" → 7, "/usr/bin/firefox" → 9. Byte offset.
     */
    public static function basenameOffset(string $argv0): int
    {
        $slash = strrpos($argv0, '/');
        if ($slash === false || $slash === strlen($argv0) - 1) {
            return 0;
        }

        return $slash + 1;
    }

    /**
     * read_bytes / write_bytes from a /proc/[pid]/io body; UNMEASURED_INT
     * for each one missing or the file unreadable.
     *
     * @return array{0: int, 1: int}
     */
    private static function parseIo(?string $raw): array
    {
        if ($raw === null) {
            return [Sentinel::UNMEASURED_INT, Sentinel::UNMEASURED_INT];
        }
        $read = preg_match('/^read_bytes:\s*(\d+)/m', $raw, $r) === 1 ? (int) $r[1] : Sentinel::UNMEASURED_INT;
        $write = preg_match('/^write_bytes:\s*(\d+)/m', $raw, $w) === 1 ? (int) $w[1] : Sentinel::UNMEASURED_INT;

        return [$read, $write];
    }

    /** Δcounter / Δseconds; a counter that went backwards reads 0 (btop). */
    private static function rate(int $now, ?int $before, ?float $seconds): float
    {
        if ($seconds === null || $before === null || $before < 0 || $now < 0) {
            return Sentinel::UNMEASURED;
        }

        return $now >= $before ? ($now - $before) / $seconds : 0.0;
    }

    /**
     * cmdline + Uid + cgroup, read once per process lifetime.
     *
     * @param array<int, string> $users uid → name cache, extended in place
     * @return array{cmd: string, user: string, uid: int, argv0: int, container: ?ContainerRef}|null
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
        $segments = explode("\0", rtrim($cmdline, "\0"));
        $argv0 = $segments[0];
        if (count($segments) === 1 && str_contains($argv0, ' ')) {
            $first = explode(' ', $argv0, 2)[0];
            if (!str_starts_with($argv0, '/') || str_ends_with($first, ':')) {
                $argv0 = $first; // rewritten argv: a title, not a path
            } elseif (($dash = strpos($argv0, ' -')) !== false) {
                $argv0 = substr($argv0, 0, $dash); // space-joined argv (chrome): the path ends before the first option
            }
        }
        $offset = self::basenameOffset($argv0);

        return [
            'cmd' => $cmd,
            'user' => $users[$uid],
            'uid' => $uid,
            'argv0' => $offset < strlen($cmd) ? $offset : 0,
            'container' => Cgroup::fromProcFile(Read::file($dir . '/cgroup')),
        ];
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

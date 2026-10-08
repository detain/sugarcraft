<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\FreeBsd;

use SugarCraft\Top\Collect\ProcDetail;
use SugarCraft\Top\Collect\Process;
use SugarCraft\Top\Collect\ProcList as LinuxProcList;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Collect\TunableProcList;

/**
 * The process table from one ps(1) run.
 *
 * Mirrors aristocratos/btop Proc::collect (src/freebsd/btop_collect.cpp),
 * which reads kvm_getprocs(KERN_PROC_PROC) kinfo_proc entries. ps prints
 * the same kinfo_proc fields:
 *  - cpu: btop uses ki_pctcpu / fscale — the kernel's decaying per-process
 *    estimate, which is ps `%cpu`, a share of ONE core. To honour
 *    {@see Process::$cpu}'s contract it is divided by the core count
 *    unless proc_per_core (btop instead multiplies by cores for per-core,
 *    leaving the default a one-core share — inconsistent with its Linux
 *    port). Measured from the first scan; never UNMEASURED;
 *  - cpuCumulative = 100 × cputime / elapsed (btop cpu_c), one core;
 *  - mem = rss (KiB) × 1024 (btop ki_rssize × pagesize);
 *  - btop drops pid 0 (the kernel) and the `idle` process; so do we;
 *  - state: ps's first STAT letter; FreeBSD's I (idle > 20 s) and W
 *    (idle interrupt thread) read as S, L (waiting on a lock) as D, so the
 *    detail view's Linux status names apply.
 *
 * Kernel filter (proc_filter_kernel): FreeBSD has no kthreadd; a kernel
 * process is one parented to pid 0 whose args ps prints in brackets
 * ("[geom]"). User names: posix_getpwuid per uid, cached, numeric
 * fallback — as the Linux collector.
 *
 * Limited visibility: with `security.bsd.see_other_uids=0` an
 * unprivileged ps lists only the caller's own processes; the table is
 * then simply shorter (btop's kvm_getprocs sees the same filtered view).
 *
 * Detail (withDetail): elapsed from ps `etimes`; cwd (btop #1546) from
 * `procstat -f <pid>` — one extra child, for that one pid only, null
 * when procstat is denied (other uid).
 *
 * Deviations / deferrals: per-process io (#1823) is not collected —
 * FreeBSD exposes only block-op counts (ps inblk/oublk, btop's BSD
 * "Op/R"/"Op/W"), which do not fit the B/s fields, so io stays
 * UNMEASURED and renders "-"; withIo() is accepted and ignored. No
 * container/jail tagging (#1873) yet.
 *
 * cmd: ps appends " (comm)" to args whose argv[0] differs from the
 * process name ("-bash (bash)", "sshd-session: detain@pts/82
 * (sshd-session)"); that suffix is ps's annotation, not part of the
 * command line, and is stripped. Bracketed kernel args ("[geom]") are
 * kept as printed.
 *
 * Cost: one ps child per sample. The host figures (hw.ncpu, the memory
 * total) are static, read by one sysctl with the first sample and carried
 * forward.
 */
final class ProcList implements TunableProcList
{
    /** `ps -o` keywords in parse order; `=` suppresses the header. */
    public const array KEYWORDS = ['pid', 'ppid', 'uid', 'state', 'nlwp', 'nice', 'rss', '%cpu', 'time', 'etimes', 'comm', 'args'];

    /**
     * @param \Closure(int): ?string $userLookup uid → login name
     * @param array<int, string> $users uid → resolved name cache
     */
    private function __construct(
        private readonly Probe $probe,
        private readonly \Closure $userLookup,
        private readonly bool $perCore,
        private readonly bool $filterKernel,
        private readonly bool $readIo,
        private readonly ?int $detailPid,
        private readonly array $users,
        /** @var array{0: int, 1: int}|null [cores, memTotal], once read */
        private readonly ?array $host = null,
    ) {
    }

    /**
     * @param (\Closure(int): ?string)|null $userLookup defaults to posix_getpwuid when ext-posix is loaded
     */
    public static function new(?Probe $probe = null, bool $perCore = false, bool $filterKernel = false, ?\Closure $userLookup = null): self
    {
        return new self($probe ?? LiveProbe::new(), $userLookup ?? self::posixLookup(...), $perCore, $filterKernel, false, null, []);
    }

    /** Accepted for parity with the Linux collector; FreeBSD io is not collected (see class doc). */
    public function withIo(bool $readIo): self
    {
        return $readIo === $this->readIo ? $this : $this->copy(readIo: $readIo);
    }

    public function withPerCore(bool $perCore): self
    {
        return $perCore === $this->perCore ? $this : $this->copy(perCore: $perCore);
    }

    public function withFilterKernel(bool $filterKernel): self
    {
        return $filterKernel === $this->filterKernel ? $this : $this->copy(filterKernel: $filterKernel);
    }

    public function withDetail(?int $pid): self
    {
        return $pid === $this->detailPid ? $this : $this->copy(detailPid: $pid, detailSet: true);
    }

    /**
     * @return array{0: ProcSnapshot, 1: self}
     */
    public function sample(): array
    {
        $host = $this->host ?? self::readHost($this->probe);
        [$cores, $memTotal] = $host ?? [1, Sentinel::UNMEASURED_INT];
        $self = $host === $this->host ? $this : $this->copy(host: $host, hostSet: true);

        $argv = ['ps', '-axww'];
        foreach (self::KEYWORDS as $keyword) {
            $argv[] = '-o';
            $argv[] = $keyword . '=';
        }
        $raw = $this->probe->run($argv);
        if ($raw === null) {
            return [new ProcSnapshot([], $cores, $memTotal), $self];
        }

        $users = $this->users;
        $processes = [];
        $detail = null;
        foreach (self::parse($raw) as $row) {
            if ($row['pid'] < 1 || $row['comm'] === 'idle') {
                continue;
            }
            if ($this->filterKernel && $row['ppid'] === 0 && preg_match('/^\[.*\]$/', $row['args']) === 1) {
                continue;
            }
            $uid = $row['uid'];
            if (!isset($users[$uid])) {
                $users[$uid] = ($this->userLookup)($uid) ?? (string) $uid;
            }
            $multiplier = $this->perCore ? 1.0 : 1.0 / $cores;
            $cpu = max(0.0, min(100.0 * $cores, round($row['pcpu'] * $multiplier * 10.0) / 10.0));
            $cumulative = $row['time'] < 0.0 ? Sentinel::UNMEASURED : 100.0 * $row['time'] / max(1.0, (float) $row['etimes']);
            $cmd = self::command($row['args'], $row['comm']);
            $argv0 = explode(' ', $cmd, 2)[0];

            $processes[] = new Process(
                $row['pid'],
                $row['ppid'],
                $row['comm'],
                $cmd,
                $users[$uid],
                $uid,
                self::state($row['state']),
                $row['nlwp'],
                $row['nice'],
                $row['rss'] * 1024,
                $cpu,
                $cumulative,
                str_starts_with($cmd, '[') ? 0 : LinuxProcList::basenameOffset($argv0),
            );
            if ($row['pid'] === $this->detailPid) {
                $detail = new ProcDetail($row['pid'], self::cwd($this->probe->run(['procstat', '-f', (string) $row['pid']])), (float) $row['etimes']);
            }
        }
        usort($processes, static fn (Process $a, Process $b): int => $a->pid <=> $b->pid);

        return [new ProcSnapshot($processes, $cores, $memTotal, $detail), $self->copy(users: $users)];
    }

    /** args without ps's " (comm)" annotation; comm itself when args is empty. */
    public static function command(string $args, string $comm): string
    {
        $suffix = ' (' . $comm . ')';
        if ($comm !== '' && str_ends_with($args, $suffix) && strlen($args) > strlen($suffix)) {
            $args = substr($args, 0, -strlen($suffix));
        }

        return $args === '' ? $comm : $args;
    }

    /**
     * @return array{0: int, 1: int}|null [cores, memTotal bytes]; null when sysctl gave nothing (retried next sample)
     */
    private static function readHost(Probe $probe): ?array
    {
        $v = $probe->sysctl(['hw.ncpu', 'hw.pagesize', 'vm.stats.vm.v_page_count', 'hw.physmem']);
        if ($v === []) {
            return null;
        }
        $page = Sysctl::int($v, 'hw.pagesize');
        $pages = Sysctl::int($v, 'vm.stats.vm.v_page_count');

        return [
            max(1, Sysctl::int($v, 'hw.ncpu') ?? 1),
            $page !== null && $pages !== null ? $page * $pages : (Sysctl::int($v, 'hw.physmem') ?? Sentinel::UNMEASURED_INT),
        ];
    }

    /**
     * @return list<array{pid: int, ppid: int, uid: int, state: string, nlwp: int, nice: int, rss: int, pcpu: float, time: float, etimes: int, comm: string, args: string}>
     */
    public static function parse(string $text): array
    {
        $out = [];
        $re = '/^\s*(\d+)\s+(\d+)\s+(\d+)\s+(\S+)\s+(\d+)\s+(\S+)\s+(\d+)\s+([\d.]+)\s+(\S+)\s+(\d+)\s+(\S+)[ \t]*(.*)$/';
        foreach (explode("\n", $text) as $line) {
            if (preg_match($re, rtrim($line), $m) !== 1) {
                continue; // a header line, or a row cut mid-write
            }
            $out[] = [
                'pid' => (int) $m[1],
                'ppid' => (int) $m[2],
                'uid' => (int) $m[3],
                'state' => $m[4],
                'nlwp' => (int) $m[5],
                'nice' => (int) $m[6],
                'rss' => (int) $m[7],
                'pcpu' => (float) $m[8],
                'time' => self::seconds($m[9]),
                'etimes' => (int) $m[10],
                'comm' => $m[11],
                'args' => $m[12],
            ];
        }

        return $out;
    }

    /** ps `time`: [[dd-]hh:]mm:ss.cc (minutes may exceed 59) → seconds; −1 when unparsable. */
    public static function seconds(string $time): float
    {
        if (preg_match('/^(?:(\d+)-)?(?:(\d+):)?(\d+):(\d+(?:\.\d+)?)$/', $time, $m) !== 1) {
            return -1.0;
        }

        return (int) $m[1] * 86400 + (int) $m[2] * 3600 + (int) $m[3] * 60 + (float) $m[4];
    }

    /** procstat -f: the NAME of the row whose FD column is "cwd"; null when absent/denied. */
    public static function cwd(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        foreach (explode("\n", $text) as $line) {
            // PID COMM FD T V FLAGS REF OFFSET PRO NAME
            if (preg_match('/^\s*\d+\s+.*?\s+cwd\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+(.+)$/', rtrim($line), $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private static function state(string $stat): string
    {
        $c = $stat[0] ?? '?';

        return match ($c) {
            'I', 'W' => 'S',
            'L' => 'D',
            default => $c,
        };
    }

    private function copy(
        ?bool $perCore = null,
        ?bool $filterKernel = null,
        ?bool $readIo = null,
        ?int $detailPid = null,
        bool $detailSet = false,
        ?array $users = null,
        ?array $host = null,
        bool $hostSet = false,
    ): self {
        return new self(
            $this->probe,
            $this->userLookup,
            $perCore ?? $this->perCore,
            $filterKernel ?? $this->filterKernel,
            $readIo ?? $this->readIo,
            $detailSet ? $detailPid : $this->detailPid,
            $users ?? $this->users,
            $hostSet ? $host : $this->host,
        );
    }

    private static function posixLookup(int $uid): ?string
    {
        if (!function_exists('posix_getpwuid')) {
            return null;
        }
        $pw = @posix_getpwuid($uid);

        return is_array($pw) && is_string($pw['name'] ?? null) ? $pw['name'] : null;
    }
}

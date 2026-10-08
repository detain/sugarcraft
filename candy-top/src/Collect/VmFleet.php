<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * The VM dashboard's collector: every running libvirt/KVM guest with its
 * cpu, memory, disk and network rates and PSI pressure — files only, no
 * subprocess and no libvirt connection (candy-top's own; btop has no VM
 * view). Linux; {@see disabled()} elsewhere (FreeBSD bhyve is out of
 * scope).
 *
 * Per sample:
 *  1. Discovery: the machined scopes under the cgroup v2 root
 *     (`machine.slice/machine-qemu\x2d<id>\x2d<name>.scope`, and libvirt's
 *     own `machine/qemu-<id>-<name>.libvirt-qemu`), decoded by
 *     {@see Vm::scope()} — the same path the ctr box keys a guest by.
 *  2. Facts ({@see VmDomain}): libvirt's live status XML
 *     `/run/libvirt/qemu/*.xml`, re-parsed only when its mtime moves and
 *     matched to a scope by domain id (else by name). The files are
 *     root-only; without them each scope's qemu pid (`libvirt/cgroup.procs`)
 *     gives its command line once per scope lifetime (`-name guest=`,
 *     `-smp`, `-m`, the NIC MACs) and the taps are found by MAC in
 *     /sys/class/net (libvirt gives a tap its guest's MAC with the first
 *     octet 0xfe, 0xfa when it already was 0xfe; a macvtap keeps it).
 *  3. Readings, deltas against the previous sample: the scope's
 *     `cpu.stat` usage_usec (÷ wall time ÷ vCPUs), `memory.current` minus
 *     `memory.stat` inactive_file, `cpu|memory|io.pressure` some avg10;
 *     disk from `/proc/<qemu pid>/io` read_bytes/write_bytes (root) — on a
 *     host whose image files sit on ZFS or are written back by the
 *     kernel's flusher, the scope's io.stat is near zero (measured on a
 *     55-guest host while `virsh domstats --block` and the qemu io agreed
 *     to a few %), so io.stat summed over devices is only the fallback;
 *     network from `/sys/class/net/<tap>/statistics/{rx,tx}_bytes`, swapped
 *     to the guest's view.
 *
 * Cost: called only while the dashboard is shown. About ten small reads
 * per guest per sample; the XML and command lines are read once.
 *
 * Immutable: the returned collector carries the counters, the parsed XML
 * and the per-scope facts to the next sample. Never throws.
 */
final class VmFleet
{
    public const CGROUP_ROOT = '/sys/fs/cgroup';

    public const LIBVIRT_RUN = '/run/libvirt/qemu';

    /** A libvirt status XML is a few tens of KiB; anything past this is not read. */
    public const XML_MAX_BYTES = 1024 * 1024;

    /** Where machined (systemd) and libvirt's own cgroupfs driver put guest scopes. */
    private const SCOPE_PARENTS = ['machine.slice', 'machine'];

    /**
     * @param \Closure(): int                                 $clock    monotonic microseconds
     * @param array<string, array{0: string, 1: ?VmDomain}>  $xml      file => ["mtime:size", parsed]
     * @param array<string, VmDomain>                         $facts    scope path => command-line facts
     * @param array<string, array{t: int, cpu: ?int, rd: ?int, wr: ?int, rx: ?int, tx: ?int, ifs: ?string}> $counters scope path => last counters
     * @param array{0: string, 1: array<string, string>}      $macMap   [iface listing signature, mac suffix => tap]
     */
    private function __construct(
        private readonly Paths $paths,
        private readonly \Closure $clock,
        private readonly bool $enabled,
        private readonly array $xml = [],
        private readonly array $facts = [],
        private readonly array $counters = [],
        private readonly array $macMap = ['', []],
        private readonly int $hostMem = 0,
        private readonly int $maxWindow = 0,
        private readonly int $lastAt = 0,
    ) {
    }

    /** @param ?\Closure(): int $clock defaults to hrtime in microseconds */
    public static function new(?Paths $paths = null, ?\Closure $clock = null): self
    {
        return new self($paths ?? Paths::system(), $clock ?? static fn (): int => intdiv(hrtime(true), 1000), true);
    }

    /** The non-Linux collector: an empty fleet, no reads (FreeBSD bhyve is not covered). */
    public static function disabled(): self
    {
        return new self(Paths::system(), static fn (): int => 0, false);
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Copy that treats a sample more than `$us` microseconds after the
     * previous one as a fresh start: no rates from that window, and the
     * snapshot says `baseline` so the dashboard restarts its histories
     * (it was hidden, or the host slept). 0 = any window counts.
     */
    public function withMaxWindow(int $us): self
    {
        return new self($this->paths, $this->clock, $this->enabled, $this->xml, $this->facts, $this->counters, $this->macMap, $this->hostMem, max(0, $us), $this->lastAt);
    }

    /**
     * Whether this host runs libvirt/KVM guests — a libvirt qemu state
     * directory, or a guest scope in the cgroup tree. One stat and at most
     * two directory listings; {@see \SugarCraft\Top\HostInfo} asks it once
     * at startup to decide whether the cpu title shows the `vms` button.
     */
    public static function present(?Paths $paths = null): bool
    {
        $paths ??= Paths::system();
        if (is_dir($paths->path(self::LIBVIRT_RUN))) {
            return true;
        }

        return self::scopes($paths) !== [];
    }

    /** @return array{0: VmFleetSnapshot, 1: self} */
    public function sample(): array
    {
        if (!$this->enabled) {
            return [new VmFleetSnapshot([]), $this];
        }
        clearstatcache();
        $now = ($this->clock)();
        $stale = $this->lastAt > 0 && $this->maxWindow > 0 && $now - $this->lastAt > $this->maxWindow;
        $hostMem = $this->hostMem > 0 ? $this->hostMem : self::memTotal($this->paths);
        [$xml, $byId, $byName] = $this->domains();
        $scopes = self::scopes($this->paths);

        $facts = [];
        $counters = [];
        $guests = [];
        $macMap = $this->macMap;
        $netScanned = false;
        $limited = $xml === [] && $scopes !== [];
        $cgroup = $this->paths->sys('fs/cgroup');
        foreach ($scopes as $path => [$domainId, $scopeName]) {
            $dir = $cgroup . $path;
            $domain = ($domainId >= 0 ? $byId[$domainId] ?? null : null) ?? $byName[$scopeName] ?? null;
            if ($domain === null) {
                $domain = $this->facts[$path] ?? $this->cmdlineFacts($dir, $scopeName, $domainId);
                // Unmatched NICs are retried every sample (a tap can appear late).
                if (\count($domain->interfaces ?? []) < \count($domain->macs)) {
                    // /sys/class/net is listed at most once per sample, whatever the guest count.
                    if (!$netScanned) {
                        $macMap = $this->tapsByMac($macMap);
                        $netScanned = true;
                    }
                    $domain = self::matched($domain, $macMap[1]);
                }
                if ($domain->pid > 0) {
                    // A scope caught before its qemu pid showed is asked again next sample.
                    // Cached per scope PATH: a libvirt scope carries the domain id, which
                    // changes on restart, so a restarted guest is a new path. A legacy
                    // id-less scope (`machine-qemu\x2d<name>.scope`, pre-1.3 libvirt) keeps
                    // its path across a restart, so without root (no XML) its command-line
                    // facts and qemu pid can go stale until candy-top restarts.
                    $facts[$path] = $domain;
                }
            }
            $prev = $stale ? null : $this->counters[$path] ?? null;
            [$guest, $counters[$path]] = $this->read($path, $dir, $domain, $prev, $now);
            $guests[] = $guest;
        }

        return [
            new VmFleetSnapshot($guests, $hostMem > 0 ? $hostMem : Sentinel::UNMEASURED_INT, $limited, $this->lastAt === 0 || $stale),
            new self($this->paths, $this->clock, true, $xml, $facts, $counters, $macMap, $hostMem, $this->maxWindow, $now),
        ];
    }

    /**
     * The running guests' scopes, cgroup path => [domain id, scope name].
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function scopes(Paths $paths): array
    {
        $out = [];
        foreach (self::SCOPE_PARENTS as $parent) {
            foreach (Read::entries($paths->sys('fs/cgroup/' . $parent)) as $entry) {
                $path = '/' . $parent . '/' . $entry;
                $scope = Vm::scope($path);
                if ($scope !== null && $scope['path'] === $path && !str_contains($entry, "\0")) {
                    $out[$path] = [$scope['domainId'], $scope['name']];
                }
            }
        }

        return $out;
    }

    /**
     * One guest's readings and the counters to diff against next time.
     *
     * @param ?array{t: int, cpu: ?int, rd: ?int, wr: ?int, rx: ?int, tx: ?int, ifs: ?string} $prev
     * @return array{0: VmGuest, 1: array{t: int, cpu: ?int, rd: ?int, wr: ?int, rx: ?int, tx: ?int, ifs: ?string}}
     */
    private function read(string $path, string $dir, VmDomain $domain, ?array $prev, int $now): array
    {
        $cpu = Containers::cpuUsage(Read::file($dir . '/cpu.stat'));
        $current = Containers::integer(Read::file($dir . '/memory.current'));
        $memUsed = Sentinel::UNMEASURED_INT;
        if ($current !== null) {
            $inactive = Containers::statValue(Read::file($dir . '/memory.stat'), 'inactive_file');
            $memUsed = $current - min($current, $inactive ?? 0);
        }
        [$rd, $wr] = self::diskBytes($this->paths, $dir, $domain->pid);
        [$rxHost, $txHost] = self::netBytes($this->paths, $domain->interfaces);

        $ifs = $domain->interfaces === null ? null : implode(' ', $domain->interfaces);
        $counters = ['t' => $now, 'cpu' => $cpu, 'rd' => $rd, 'wr' => $wr, 'rx' => $rxHost, 'tx' => $txHost, 'ifs' => $ifs];
        // A tap that joined (a late MAC match) or left would put its whole
        // byte total into one window: a changed interface set restarts the
        // net baseline instead.
        $netPrev = $prev !== null && ($prev['ifs'] ?? null) === $ifs ? $prev : null;
        $window = $prev === null ? 0 : $now - $prev['t'];
        $rate = static function (?int $was, ?int $is) use ($window): float {
            if ($was === null || $is === null || $window <= 0 || $is < $was) {
                return Sentinel::UNMEASURED;
            }

            return ($is - $was) * 1_000_000 / $window;
        };
        $vcpus = $domain->vcpus > 0 ? $domain->vcpus : 1;
        $cpuPct = $prev === null || $cpu === null || $prev['cpu'] === null || $window <= 0 || $cpu < $prev['cpu']
            ? Sentinel::UNMEASURED
            : ($cpu - $prev['cpu']) * 100 / $window / $vcpus;

        return [
            new VmGuest(
                $path,
                Cgroup::safe($domain->name),
                $domain->domainId,
                $domain->vcpus,
                $domain->memBytes,
                $cpuPct,
                $memUsed,
                $rate($prev['rd'] ?? null, $rd),
                $rate($prev['wr'] ?? null, $wr),
                // The tap's tx is what the guest received; its rx what the guest sent.
                $rate($netPrev['tx'] ?? null, $txHost),
                $rate($netPrev['rx'] ?? null, $rxHost),
                self::pressure(Read::file($dir . '/cpu.pressure')),
                self::pressure(Read::file($dir . '/memory.pressure')),
                self::pressure(Read::file($dir . '/io.pressure')),
            ),
            $counters,
        ];
    }

    /**
     * Bytes the guest read and wrote so far: the qemu process's
     * read_bytes / write_bytes when readable (root), else the scope's
     * io.stat rbytes / wbytes summed over its devices.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public static function diskBytes(Paths $paths, string $dir, int $pid): array
    {
        if ($pid > 0) {
            $io = Read::file($paths->proc($pid . '/io'));
            if ($io !== null && preg_match('/^read_bytes:\s*(\d+)/m', $io, $r) === 1 && preg_match('/^write_bytes:\s*(\d+)/m', $io, $w) === 1) {
                return [(int) $r[1], (int) $w[1]];
            }
        }
        $stat = Read::file($dir . '/io.stat');
        if ($stat === null) {
            return [null, null];
        }
        $read = 0;
        $write = 0;
        preg_match_all('/\brbytes=(\d+)/', $stat, $r);
        preg_match_all('/\bwbytes=(\d+)/', $stat, $w);
        foreach ($r[1] as $v) {
            $read += (int) $v;
        }
        foreach ($w[1] as $v) {
            $write += (int) $v;
        }

        return [$read, $write];
    }

    /**
     * Host-side rx / tx byte totals over the guest's taps; [null, null]
     * when the taps are unknown or none was readable.
     *
     * @param ?list<string> $interfaces
     * @return array{0: ?int, 1: ?int}
     */
    public static function netBytes(Paths $paths, ?array $interfaces): array
    {
        if ($interfaces === null) {
            return [null, null];
        }
        $rx = 0;
        $tx = 0;
        $any = $interfaces === [];
        foreach ($interfaces as $iface) {
            $base = $paths->sys('class/net/' . $iface . '/statistics/');
            $r = Read::int($base . 'rx_bytes');
            $t = Read::int($base . 'tx_bytes');
            if ($r !== null && $t !== null) {
                $rx += $r;
                $tx += $t;
                $any = true;
            }
        }

        return $any ? [$rx, $tx] : [null, null];
    }

    /** `some avg10=` of a PSI file, in percent; UNMEASURED when unreadable. */
    public static function pressure(?string $raw): float
    {
        if ($raw === null || preg_match('/^some\s+avg10=(\d+(?:\.\d+)?)/m', $raw, $m) !== 1) {
            return Sentinel::UNMEASURED;
        }

        return (float) $m[1];
    }

    /**
     * The libvirt status XML, re-parsed only for files whose mtime or size
     * moved; files over {@see XML_MAX_BYTES} are skipped.
     *
     * @return array{0: array<string, array{0: string, 1: ?VmDomain}>, 1: array<int, VmDomain>, 2: array<string, VmDomain>}
     */
    private function domains(): array
    {
        $dir = $this->paths->path(self::LIBVIRT_RUN);
        $cache = [];
        $byId = [];
        $byName = [];
        foreach (Read::entries($dir) as $entry) {
            if (!str_ends_with($entry, '.xml')) {
                continue;
            }
            $file = $dir . '/' . $entry;
            $mtime = @filemtime($file);
            $size = @filesize($file);
            if ($mtime === false || $size === false || $size > self::XML_MAX_BYTES) {
                continue; // gone, or not a libvirt status file
            }
            // mtime alone has 1 s resolution on some filesystems: a rewrite
            // within the second that changes the length is caught too.
            $stamp = $mtime . ':' . $size;
            [$was, $parsed] = $this->xml[$entry] ?? ['', null];
            if ($was !== $stamp) {
                $raw = @file_get_contents($file, false, null, 0, self::XML_MAX_BYTES);
                if ($raw === false) {
                    continue; // root-only: the command-line facts take over
                }
                $parsed = VmDomain::fromXml($raw);
            }
            $cache[$entry] = [$stamp, $parsed];
            if ($parsed !== null) {
                if ($parsed->domainId >= 0) {
                    $byId[$parsed->domainId] = $parsed;
                }
                $byName[Vm::machineName($parsed->name)] = $parsed;
                $byName[$parsed->name] = $parsed;
            }
        }

        return [$cache, $byId, $byName];
    }

    /**
     * Facts from the scope's qemu command line, its pid from the threaded
     * domain's cgroup.procs. The last resort, the emulator's
     * cgroup.threads, holds TIDs: the first is normally the main thread
     * (= the pid), and any tid still works for /proc/<tid>/cmdline and
     * /proc/<tid>/io, whose I/O accounting is per thread group.
     */
    private function cmdlineFacts(string $dir, string $scopeName, int $domainId): VmDomain
    {
        $pid = 0;
        foreach (['/libvirt/cgroup.procs', '/cgroup.procs', '/libvirt/emulator/cgroup.threads'] as $file) {
            $line = Read::line($dir . $file);
            if ($line !== null && ctype_digit($line)) {
                $pid = (int) $line;
                break;
            }
        }
        $cmdline = $pid > 0 ? Read::file($this->paths->proc($pid . '/cmdline')) : null;

        return VmDomain::fromCmdline($cmdline, $scopeName, $domainId, $pid);
    }

    /**
     * Host interfaces by MAC, rebuilt only when the interface listing
     * changes. Bridges are skipped (a bridge may borrow a port's MAC).
     *
     * @param array{0: string, 1: array<string, string>} $map
     * @return array{0: string, 1: array<string, string>}
     */
    private function tapsByMac(array $map): array
    {
        $net = $this->paths->sys('class/net');
        $ifaces = array_values(array_filter(Read::entries($net), static fn (string $i): bool => VmDomain::safeIface($i)));
        $signature = implode(' ', $ifaces);
        if ($signature === $map[0]) {
            return $map;
        }
        $byMac = [];
        foreach ($ifaces as $iface) {
            $mac = strtolower(Read::line($net . '/' . $iface . '/address') ?? '');
            if (strlen($mac) === 17 && !is_dir($net . '/' . $iface . '/bridge')) {
                $byMac[$mac] = $iface;
            }
        }

        return [$signature, $byMac];
    }

    /**
     * The guest's host-side interfaces, found by MAC as libvirt sets them
     * (virNetDevTapCreate / macvtap): a tap carries the guest MAC with its
     * first octet replaced by 0xfe — 0xfa when the guest's is 0xfe
     * already — and a macvtap the guest MAC itself.
     *
     * @param array<string, string> $byMac
     */
    private static function matched(VmDomain $domain, array $byMac): VmDomain
    {
        $taps = [];
        foreach ($domain->macs as $mac) {
            $tapMac = (str_starts_with($mac, 'fe:') ? 'fa' : 'fe') . substr($mac, 2);
            $tap = $byMac[$mac] ?? $byMac[$tapMac] ?? null;
            if ($tap !== null) {
                $taps[] = $tap;
            }
        }

        return $taps === [] ? $domain : $domain->withInterfaces($taps);
    }

    private static function memTotal(Paths $paths): int
    {
        $raw = Read::file($paths->proc('meminfo'));

        return $raw !== null && preg_match('/^MemTotal:\s+(\d+)\s*kB/m', $raw, $m) === 1 ? (int) $m[1] * 1024 : 0;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * KVM/QEMU guest recognition (plan Wave U1b; not a btop feature).
 *
 * A VM is recognised by its cgroup path and its cmdline, never by the
 * executable's name alone: Ubuntu's libvirt runs `/usr/bin/kvm`, RHEL
 * `/usr/libexec/qemu-kvm`, others `qemu-system-<arch>` or a custom
 * emulator path (prompt_kit/findings/kvm-reference.md).
 *
 * cgroup layouts (any line of /proc/[pid]/cgroup, any depth):
 *  - systemd / machined (cgroup v2, and v1's name=systemd and controller
 *    hierarchies): `machine-qemu\x2d<id>\x2d<name>.scope` — the stem is
 *    systemd-unit-escaped (`\xNN`), unescaped it reads
 *    `qemu-<id>-<name>`; pre-1.3 libvirt omitted the id
 *    (`machine-qemu\x2d<name>.scope`);
 *  - libvirt's own cgroupfs layout (v1 without systemd):
 *    `/machine/qemu-<id>-<name>.libvirt-qemu` (older: `<name>.libvirt-qemu`;
 *    a leading '_' is libvirt's escape and is dropped).
 * Processes below the scope (`libvirt/emulator`, `libvirt/vcpuN`) belong
 * to it. The VM's cgroupPath is the path up to and including that segment.
 *
 * The cmdline supplies what the cgroup cannot — guest name, uuid, vCPUs,
 * memory — and is parsed only when it is a QEMU command line: argv[0]'s
 * basename is `qemu-system-*`, `kvm` or `qemu-kvm`, or its options are
 * QEMU's — a `-uuid`, or a `-name` together with `-m` or `-smp` (a custom
 * emulator path in a libvirt scope; `find / -name x` stays a non-VM).
 * Helpers that share the scope (swtpm, virtiofsd) are tagged with the VM
 * from the scope alone.
 *
 * Without a VM scope, a qemu binary (by the basename rule) with a `-name`
 * is still a VM — non-libvirt qemu — with an empty cgroupPath (it owns no
 * cgroup to group or read stats from).
 *
 * QEMU option values follow QemuOpts: comma-separated `key=value`, the
 * first bare value is the option's implied key, and ",," is a literal
 * comma (libvirt doubles commas in guest names). The last occurrence of an
 * option wins, as in QEMU.
 *
 * Pure functions except vcpuThreads(); never throws.
 */
final class Vm
{
    /** ContainerRef::$engine for a VM. */
    public const string ENGINE = 'kvm';

    private function __construct()
    {
    }

    /**
     * systemd unit-name unescaping (systemd-escape --unescape): every
     * `\xNN` becomes byte 0xNN; anything else is kept verbatim.
     */
    public static function unescape(string $text): string
    {
        return (string) preg_replace_callback(
            '/\\\\x([0-9A-Fa-f]{2})/',
            static fn (array $m): string => chr((int) hexdec($m[1])),
            $text,
        );
    }

    /**
     * The outermost libvirt/machined VM segment of a cgroup path.
     *
     * @return array{domainId: int, name: string, path: string}|null name raw (unescaped), path = cgroup root of the VM
     */
    public static function scope(string $cgroup): ?array
    {
        $length = strlen($cgroup);
        for ($start = 0; $start < $length;) {
            $slash = strpos($cgroup, '/', $start);
            $end = $slash === false ? $length : $slash;
            $part = substr($cgroup, $start, $end - $start);
            $start = $end + 1;

            $machine = null;
            if (str_starts_with($part, 'machine-') && str_ends_with($part, '.scope')) {
                $machine = self::unescape(substr($part, 8, -6));
            } elseif (str_ends_with($part, '.libvirt-qemu') && strlen($part) > 13) {
                $machine = substr($part, 0, -13);
                if (str_starts_with($machine, '_')) {
                    $machine = substr($machine, 1);
                }
                // The pre-machine-name layout had no "qemu-" prefix at all.
                if (!str_starts_with($machine, 'qemu-')) {
                    $machine = 'qemu-' . $machine;
                }
            }
            if ($machine === null || !str_starts_with($machine, 'qemu-') || strlen($machine) <= 5) {
                continue;
            }
            // Old layouts are ambiguous: a domain literally named `qemu-foo` (id-less
            // `<name>.libvirt-qemu`) parses as `foo`, and `5-foo` as id 5 / `foo`.
            // Display prefers cmdline guest=, so only nameAgrees() can misreport.
            $rest = substr($machine, 5);
            if (preg_match('/^(\d{1,9})-(.+)$/s', $rest, $m) === 1) {
                return ['domainId' => (int) $m[1], 'name' => $m[2], 'path' => substr($cgroup, 0, $end)];
            }

            return ['domainId' => Sentinel::UNMEASURED_INT, 'name' => $rest, 'path' => substr($cgroup, 0, $end)];
        }

        return null;
    }

    /** argv[0] names a QEMU system emulator: qemu-system-*, kvm or qemu-kvm (basename). */
    public static function isQemuBinary(string $argv0): bool
    {
        $slash = strrpos($argv0, '/');
        $base = $slash === false ? $argv0 : substr($argv0, $slash + 1);

        return preg_match('/^(?:qemu-system-[A-Za-z0-9_.-]+|kvm|qemu-kvm)$/', $base) === 1;
    }

    /**
     * The VM facts in a raw (NUL-separated) /proc/[pid]/cmdline; null when
     * it is not a QEMU command line (see the class doc).
     */
    public static function fromCmdline(?string $cmdline): ?VmInfo
    {
        if ($cmdline === null || $cmdline === '') {
            return null;
        }
        $argv = explode("\0", rtrim($cmdline, "\0"));
        $options = [];
        $count = count($argv);
        for ($i = 1; $i < $count - 1; $i++) {
            $flag = $argv[$i];
            $key = match ($flag) {
                '-name', '--name' => 'name',
                '-uuid', '--uuid' => 'uuid',
                '-smp', '--smp' => 'smp',
                '-m', '--m' => 'm',
                default => null,
            };
            if ($key !== null) {
                $options[$key] = $argv[++$i]; // last occurrence wins
            }
        }
        $qemuOptions = isset($options['uuid']) || (isset($options['name']) && (isset($options['m']) || isset($options['smp'])));
        if (!self::isQemuBinary($argv[0]) && !$qemuOptions) {
            return null; // "find / -name core" is not a VM
        }
        $uuid = strtolower($options['uuid'] ?? '');

        return new VmInfo(
            guestName: isset($options['name']) ? (self::opts($options['name'], 'guest')['guest'] ?? '') : '',
            uuid: preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid) === 1 ? $uuid : Sentinel::UNAVAILABLE,
            vcpus: isset($options['smp']) ? self::smp($options['smp']) : Sentinel::UNMEASURED_INT,
            memBytes: isset($options['m']) ? self::memory($options['m']) : Sentinel::UNMEASURED_INT,
        );
    }

    /**
     * Boot vCPUs from a `-smp` value: the implied / `cpus=` count ("2,sockets=2"
     * → 2, "cpus=4" → 4); when that is absent or 0, QEMU derives it from the
     * topology, so sockets × dies × clusters × cores × threads; nothing
     * usable → UNMEASURED_INT.
     */
    public static function smp(string $value): int
    {
        $opts = self::opts($value, 'cpus');
        $cpus = $opts['cpus'] ?? '';
        if (ctype_digit($cpus) && (int) $cpus > 0 && strlen($cpus) <= 6) {
            return (int) $cpus;
        }
        $product = 1;
        $any = false;
        foreach (['sockets', 'dies', 'clusters', 'cores', 'threads'] as $key) {
            if (!isset($opts[$key])) {
                continue;
            }
            if (!ctype_digit($opts[$key]) || (int) $opts[$key] < 1 || strlen($opts[$key]) > 6) {
                return Sentinel::UNMEASURED_INT;
            }
            $product *= (int) $opts[$key];
            $any = true;
        }

        return $any && $product <= 1_000_000 ? $product : Sentinel::UNMEASURED_INT;
    }

    /**
     * Guest RAM bytes from a `-m` value (QEMU qemu_strtosz_MiB): the implied
     * / `size=` amount, a decimal number with an optional B/K/M/G/T/P/E
     * suffix (either case), MiB when there is none — "size=6291456k",
     * "2048", "4G", "1.5G,slots=2,maxmem=8G". Malformed, zero or
     * overflowing → UNMEASURED_INT.
     */
    public static function memory(string $value): int
    {
        $size = self::opts($value, 'size')['size'] ?? '';
        if (preg_match('/^(\d+(?:\.\d+)?)([BbKkMmGgTtPpEe]?)$/', $size, $m) !== 1) {
            return Sentinel::UNMEASURED_INT;
        }
        $shift = match (strtoupper($m[2])) {
            'B' => 0,
            'K' => 10,
            '', 'M' => 20,
            'G' => 30,
            'T' => 40,
            'P' => 50,
            default => 60,
        };
        $bytes = (float) $m[1] * (2 ** $shift);
        if (str_contains($m[1], '.') && $shift === 0) {
            return Sentinel::UNMEASURED_INT; // fractional bytes
        }

        return $bytes >= 1.0 && $bytes < 9.2e18 ? (int) round($bytes) : Sentinel::UNMEASURED_INT;
    }

    /**
     * libvirt's machine-name sanitising of a domain name
     * (virDomainMachineNameAppendValid): hostname characters only, runs of
     * '.'/'-' collapse to their first, a leading or trailing '.'/'-' is
     * dropped. Used to cross-check a scope name against guest=.
     */
    public static function machineName(string $name): string
    {
        $out = '';
        $skip = true;
        $length = strlen($name);
        for ($i = 0; $i < $length; $i++) {
            $c = $name[$i];
            if ($c === '.' || $c === '-') {
                if (!$skip) {
                    $out .= $c;
                }
                $skip = true;
                continue;
            }
            $skip = false;
            if (ctype_alnum($c)) {
                $out .= $c;
            }
        }

        return rtrim($out, '.-');
    }

    /**
     * The ContainerRef for a VM recognised from one cgroup path (null when
     * the path has no VM segment) — cmdline facts merged in when given.
     */
    public static function fromCgroupPath(string $cgroup, ?string $cmdline = null): ?ContainerRef
    {
        $scope = self::scope($cgroup);
        if ($scope === null) {
            return null;
        }
        $cmd = self::fromCmdline($cmdline);

        return self::ref(new VmInfo(
            $scope['domainId'],
            $scope['name'],
            $cmd->guestName ?? '',
            $cmd->uuid ?? Sentinel::UNAVAILABLE,
            $cmd->vcpus ?? Sentinel::UNMEASURED_INT,
            $cmd->memBytes ?? Sentinel::UNMEASURED_INT,
        ), $scope['path']);
    }

    /**
     * Non-libvirt qemu: a qemu binary (basename rule) with a `-name`.
     * cgroupPath is '' — the process owns no VM cgroup.
     */
    public static function fromBareCmdline(?string $cmdline): ?ContainerRef
    {
        $info = self::fromCmdline($cmdline);
        $argv0 = explode("\0", (string) $cmdline, 2)[0];
        if ($info === null || $info->guestName === '' || !self::isQemuBinary($argv0)) {
            return null;
        }

        return self::ref($info, '');
    }

    /**
     * vCPU threads of a VM process: /proc/<pid>/task/<tid>/comm
     * `CPU <n>/KVM` (QEMU `-name …,debug-threads=on`, which libvirt always
     * sets; `/TCG`, `/HVF`… accepted too), else a task cgroup ending in
     * `vcpu<n>` below a VM scope (libvirt without debug-threads). One
     * directory listing plus one or two small reads per thread — call it
     * on demand (a detail view), not every tick for every pid.
     *
     * @return array<int, int> tid → vCPU index, ordered by vCPU index
     */
    public static function vcpuThreads(Paths $paths, int $pid): array
    {
        $task = $paths->proc($pid . '/task');
        $map = [];
        foreach (Read::entries($task) as $tid) {
            if (!ctype_digit($tid)) {
                continue;
            }
            $comm = Read::line($task . '/' . $tid . '/comm');
            if ($comm !== null && preg_match('#^CPU (\d{1,6})/[A-Za-z0-9]+$#', $comm, $m) === 1) {
                $map[(int) $tid] = (int) $m[1];
                continue;
            }
            foreach (explode("\n", Read::file($task . '/' . $tid . '/cgroup') ?? '') as $line) {
                $path = explode(':', $line, 3)[2] ?? '';
                if (preg_match('#/vcpu(\d{1,6})(?:/|$)#', $path, $m) === 1 && self::scope($path) !== null) {
                    $map[(int) $tid] = (int) $m[1];
                    break;
                }
            }
        }
        asort($map);

        return $map;
    }

    private static function ref(VmInfo $info, string $path): ContainerRef
    {
        $name = $info->name();

        return new ContainerRef(self::ENGINE, $name, Cgroup::safe($name), $path, $info);
    }

    /**
     * QemuOpts parsing: "a,b=c,,d" with implied key K → [K => a, b => "c,d"].
     *
     * @return array<string, string>
     */
    private static function opts(string $value, string $implied): array
    {
        $parts = [];
        $current = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] !== ',') {
                $current .= $value[$i];
            } elseif ($i + 1 < $length && $value[$i + 1] === ',') {
                $current .= ',';
                $i++;
            } else {
                $parts[] = $current;
                $current = '';
            }
        }
        $parts[] = $current;

        $opts = [];
        foreach ($parts as $index => $part) {
            $eq = strpos($part, '=');
            if ($eq === false) {
                if ($index === 0 && $part !== '') {
                    $opts[$implied] = $part;
                }
                continue;
            }
            $opts[substr($part, 0, $eq)] = substr($part, $eq + 1);
        }

        return $opts;
    }
}

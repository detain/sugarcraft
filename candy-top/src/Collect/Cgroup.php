<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * Container detection from /proc/[pid]/cgroup.
 *
 * Mirrors aristocratos/btop Ctr::parse_cgroup (PR #1873). The path is
 * walked from the root so a nested container is attributed to the
 * outermost one (docker inside an LXC guest → the LXC guest):
 *  - LXC / Incus: `lxc.payload.<name>`, or `<name>` right below `lxc`
 *    (Proxmox);
 *  - systemd-nspawn / machined: `machine-<name>.scope`, except a
 *    libvirt guest's scope ({@see Vm::scope()}: VMs are not containers);
 *    systemd's `\xNN` escapes decoded by {@see Vm::unescape()} (btop
 *    replaces `\x2d` only). btop skips every `machine-qemu*` scope, so
 *    an nspawn machine named e.g. "qemubox" is invisible there;
 *  - OCI runtimes: `[<engine>-]<64 hex>[.scope]` — `libpod` → podman,
 *    any path containing `kubepods` → k8s, a bare id below `docker` →
 *    docker, else the prefix itself, else "container"; a `*conmon`
 *    prefix is podman's monitor process, outside the container.
 *
 * fromProcFile() also recognises KVM/QEMU guests (Vm, engine "kvm"):
 * a libvirt/machined VM segment on a line competes with a container on the
 * same line by depth — the outermost wins, as between containers — and
 * with no container or VM in any line, a qemu binary's `-name` (bare
 * qemu, given the cmdline) still tags the process. parse() itself stays
 * containers-only.
 *
 * Pure functions; never throws.
 */
final class Cgroup
{
    private function __construct()
    {
    }

    /**
     * The first line of a /proc/[pid]/cgroup file ("id:controllers:path",
     * one line on cgroup v2, one per hierarchy on v1) whose path is a
     * container or a VM.
     *
     * @param string|null $cmdline the raw (NUL-separated) /proc/[pid]/cmdline:
     *                             supplies a VM's guest name, uuid, vCPUs and
     *                             memory, and recognises non-libvirt qemu
     */
    public static function fromProcFile(?string $raw, ?string $cmdline = null): ?ContainerRef
    {
        foreach (explode("\n", $raw ?? '') as $line) {
            $parts = explode(':', $line, 3);
            if (count($parts) !== 3) {
                continue;
            }
            $container = self::parse($parts[2]);
            $vm = Vm::fromCgroupPath($parts[2], $cmdline);
            if ($container !== null && $vm !== null) {
                return strlen($vm->cgroupPath) < strlen($container->cgroupPath) ? $vm : $container;
            }
            if (($ref = $container ?? $vm) !== null) {
                return $ref;
            }
        }

        return Vm::fromBareCmdline($cmdline);
    }

    public static function parse(string $cgroup): ?ContainerRef
    {
        $prev = '';
        $length = strlen($cgroup);
        for ($start = 0; $start < $length;) {
            $slash = strpos($cgroup, '/', $start);
            $end = $slash === false ? $length : $slash;
            $part = substr($cgroup, $start, $end - $start);
            $path = substr($cgroup, 0, $end);
            $start = $end + 1;

            $scope = str_ends_with($part, '.scope');
            $stem = $scope ? substr($part, 0, -6) : $part;

            if (str_starts_with($stem, 'lxc.payload.') && strlen($stem) > 12) {
                return self::ref('lxc', substr($stem, 12), $path);
            }
            if ($prev === 'lxc' && $part !== '') {
                return self::ref('lxc', $part, $path);
            }
            // A machined scope is a VM exactly when Vm::scope() reads it as one
            // (`qemu-<id>-<name>` once unescaped), so the two classifiers can
            // never both claim, or both drop, the same segment: an nspawn
            // machine merely NAMED like qemu (`machine-qemubox.scope`) stays
            // a container, a libvirt guest (`machine-qemu\x2d1\x2dweb.scope`,
            // any escape case) is a VM.
            if ($scope && str_starts_with($stem, 'machine-') && strlen($stem) > 8 && Vm::scope($part) === null) {
                return self::ref('nspawn', Vm::unescape(substr($stem, 8)), $path);
            }
            $n = strlen($stem);
            if ($n >= 64 && ctype_xdigit(substr($stem, -64)) && ($n === 64 || $stem[$n - 65] === '-')) {
                $engine = $n === 64 ? '' : substr($stem, 0, $n - 65);
                if (!str_ends_with($engine, 'conmon')) {
                    $engine = match (true) {
                        str_contains($cgroup, 'kubepods') => 'k8s',
                        $engine === 'libpod' => 'podman',
                        $engine !== '' => $engine,
                        $prev === 'docker' => 'docker',
                        default => 'container',
                    };
                    $short = substr($stem, -64, 12);

                    return new ContainerRef(self::safe($engine), $short, $short, $path);
                }
            }

            $prev = $part;
        }

        return null;
    }

    private static function ref(string $engine, string $name, string $path): ContainerRef
    {
        return new ContainerRef($engine, $name, self::safe($name), $path);
    }

    /** Terminal-safe display text: the docker-name alphabet plus ':'; anything else → '?'. */
    public static function safe(string $text): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_.:-]/', '?', $text);
    }

    /** The cgroup v2 mount btop-shaped readers fall back to. */
    public const DEFAULT_V2_ROOT = '/sys/fs/cgroup';

    /** @var array<string, string> memoized resolver, keyed by Paths root */
    private static array $v2Roots = [];

    /**
     * Where the cgroup v2 hierarchy actually is (candy-top-057).
     *
     * The hardcoded /sys/fs/cgroup assumes a unified host. Hybrid boxes
     * (≤systemd 244, RHEL7-era `unified` option) mount cgroup2 elsewhere
     * — typically /sys/fs/cgroup/unified — and every reader of the
     * hardcoded path then silently degrades to process-sum fallback /
     * UNMEASURED quota. Resolve from /proc/mounts once per Paths root
     * (the mount table is boot-stable; re-reading it per tick buys
     * nothing), first cgroup2 row wins; absent a v2 entry the classic
     * default is returned — the same path the old literals carried.
     *
     * The discovered mount point is resolved under $paths exactly like
     * every other kernel path, so fixture trees keep working.
     */
    public static function v2Root(Paths $paths): string
    {
        if (isset(self::$v2Roots[$paths->root])) {
            return self::$v2Roots[$paths->root];
        }

        $root = $paths->path(self::DEFAULT_V2_ROOT);
        foreach (explode("\n", Read::file($paths->proc('mounts')) ?? '') as $line) {
            $cols = preg_split('/\s+/', trim($line));
            if (($cols[2] ?? '') === 'cgroup2' && ($cols[1] ?? '') !== '') {
                $root = $paths->path($cols[1]);
                break;
            }
        }

        return self::$v2Roots[$paths->root] = $root;
    }
}

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
 *  - systemd-nspawn / machined: `machine-<name>.scope`, except
 *    `machine-qemu*` (VMs are not containers); `\x2d` unescaped to '-';
 *  - OCI runtimes: `[<engine>-]<64 hex>[.scope]` — `libpod` → podman,
 *    any path containing `kubepods` → k8s, a bare id below `docker` →
 *    docker, else the prefix itself, else "container"; a `*conmon`
 *    prefix is podman's monitor process, outside the container.
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
     * container.
     */
    public static function fromProcFile(?string $raw): ?ContainerRef
    {
        foreach (explode("\n", $raw ?? '') as $line) {
            $parts = explode(':', $line, 3);
            if (count($parts) === 3 && ($ref = self::parse($parts[2])) !== null) {
                return $ref;
            }
        }

        return null;
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
            if ($scope && str_starts_with($stem, 'machine-') && strlen($stem) > 8 && !str_starts_with($stem, 'machine-qemu')) {
                return self::ref('nspawn', str_replace('\x2d', '-', substr($stem, 8)), $path);
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
    private static function safe(string $text): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_.:-]/', '?', $text);
    }
}

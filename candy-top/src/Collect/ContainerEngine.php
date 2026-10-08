<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * The container engine candy-top itself runs inside — btop
 * `detect_container()` (btop_shared.cpp), shown on the cpu box title in
 * place of the `x ctr` button (btop_draw.cpp Cpu::draw).
 *
 * btop checks three markers, in this order: `/run/.containerenv` (podman),
 * `/.dockerenv` (docker), and the first word of `/run/systemd/container`
 * (written by systemd inside nspawn / lxc / … guests). candy-top keeps
 * those and their order, and adds the cheap ones btop misses, every one a
 * file read or an env lookup — no subprocess:
 *
 *  1. `KUBERNETES_SERVICE_HOST` in the environment → "k8s" (every pod has
 *     it, whatever the runtime; checked first because a pod's runtime
 *     markers would otherwise name containerd / cri-o / docker);
 *  2. `/run/.containerenv` → "podman" (btop);
 *  3. `/.dockerenv` → "docker" (btop);
 *  4. `/run/systemd/container` → its first word (btop);
 *  5. `container=` in `/proc/1/environ` — what systemd itself reads, set
 *     by lxc, nspawn, podman, … even when the guest's init is not systemd
 *     (unreadable unless candy-top runs as the guest's root: then skipped);
 *  6. `/proc/self/cgroup` read as a host would see it — a container path
 *     ({@see Cgroup::parse()}) is visible on cgroup v1 and on v2 without a
 *     cgroup namespace (docker before 20.10, `--cgroupns=host`, k8s
 *     kubepods paths, LXC's `lxc.payload.*`);
 *  7. `/proc/self/mountinfo`'s root mount: an overlay whose layers live
 *     under `/var/lib/docker/` → "docker", `/containers/storage/` →
 *     "podman", a `containerd` snapshotter → "containerd".
 *
 * systemd's names are shortened to the ctr box's engine names
 * ("systemd-nspawn" → "nspawn", "lxc-libvirt" → "lxc"); systemd's own
 * "wsl" passes through, so a WSL distro running systemd shows "wsl"
 * (btop shows the same word there; neither probes WSL any further). The
 * result is restricted to the {@see Cgroup::safe()} alphabet and
 * {@see MAX_LENGTH} bytes, since it lands on the terminal.
 *
 * FreeBSD has no such files: its jail check is the `security.jail.jailed`
 * sysctl, read with the other host facts ({@see FreeBsd\HostDetect}).
 *
 * Called once at startup ({@see \SugarCraft\Top\HostInfo::detect()});
 * never throws.
 */
final class ContainerEngine
{
    /** The longest engine name kept (the title has no room for more). */
    public const int MAX_LENGTH = 12;

    /** A FreeBSD jail ({@see FreeBsd\HostDetect}: `security.jail.jailed` = 1); btop detects nothing on FreeBSD. */
    public const string JAIL = 'jail';

    /** systemd `container=` values → the ctr box's engine names. */
    private const array ALIASES = [
        'systemd-nspawn' => 'nspawn',
        'lxc-libvirt' => 'lxc',
    ];

    private function __construct()
    {
    }

    /**
     * @param ?Paths                     $paths the filesystem root (fixture trees in tests); null = the live host
     * @param ?array<string, string>     $env   the environment; null = getenv()
     * @return string the engine name, '' when not in a container
     */
    public static function detect(?Paths $paths = null, ?array $env = null): string
    {
        $paths ??= Paths::system();
        $env ??= getenv();
        if (($env['KUBERNETES_SERVICE_HOST'] ?? '') !== '') {
            return 'k8s';
        }
        if (file_exists($paths->path('/run/.containerenv'))) {
            return 'podman';
        }
        if (file_exists($paths->path('/.dockerenv'))) {
            return 'docker';
        }
        $systemd = self::read($paths->path('/run/systemd/container'));
        if ($systemd !== null && ($word = self::firstWord($systemd)) !== '') {
            return self::name($word);
        }
        $environ = self::read($paths->proc('1/environ'));
        if ($environ !== null && ($value = self::environValue($environ, 'container')) !== '') {
            return self::name($value);
        }
        foreach (explode("\n", self::read($paths->proc('self/cgroup')) ?? '') as $line) {
            $ref = Cgroup::parse(explode(':', $line, 3)[2] ?? '');
            if ($ref !== null) {
                return self::name($ref->engine);
            }
        }

        return self::fromMountinfo(self::read($paths->proc('self/mountinfo')));
    }

    /** The engine an overlay root mount's layer paths reveal; '' for none. */
    public static function fromMountinfo(?string $mountinfo): string
    {
        foreach (explode("\n", $mountinfo ?? '') as $line) {
            // id parent major:minor root mountpoint options [optional...] - fstype source superoptions
            $fields = explode(' ', $line);
            if (($fields[4] ?? '') !== '/') {
                continue;
            }
            $dash = array_search('-', $fields, true);
            if ($dash === false || ($fields[$dash + 1] ?? '') !== 'overlay') {
                continue;
            }
            $super = $fields[$dash + 3] ?? '';

            return match (true) {
                str_contains($super, '/var/lib/docker/') => 'docker',
                str_contains($super, '/containers/storage/') => 'podman',
                str_contains($super, 'containerd') => 'containerd',
                default => '',
            };
        }

        return '';
    }

    /** `KEY=value` from a NUL-separated environ block; '' when absent. */
    public static function environValue(string $environ, string $key): string
    {
        foreach (explode("\0", $environ) as $entry) {
            if (str_starts_with($entry, $key . '=')) {
                return substr($entry, \strlen($key) + 1);
            }
        }

        return '';
    }

    /** Display form: systemd's long names shortened, terminal-safe, capped. */
    public static function name(string $raw): string
    {
        $raw = trim($raw);

        return substr(Cgroup::safe(self::ALIASES[$raw] ?? $raw), 0, self::MAX_LENGTH);
    }

    private static function firstWord(string $text): string
    {
        return preg_split('/\s+/', trim($text), 2)[0] ?? '';
    }

    private static function read(string $file): ?string
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $raw = @file_get_contents($file, false, null, 0, 1 << 20);

        return $raw === false ? null : $raw;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\FreeBsd;

/**
 * The live FreeBSD host: sysctl(8) and the base-system tools, each run as
 * a bounded child.
 *
 * WHY shell-outs and not FFI sysctlbyname / libkvm: they need ext-ffi and
 * per-release struct layouts (kinfo_proc, devstat, xswdev). The tools are
 * in every base install, print a stable text ABI, and one `sysctl -i -e
 * <oid> <oid> ...` batches every OID a collector needs into a single
 * child per sample. `-i` skips unknown OIDs (no ZFS, no cpufreq, no
 * battery) instead of failing the whole call.
 *
 * The descriptor spec names fds 0-2 only (stdin and stderr /dev/null,
 * stdout a pipe); descriptors the PHP process itself holds open without
 * close-on-exec are still inherited by the child, as with every
 * proc_open — the child is short-lived (reaped inside run()), so that
 * window closes with it. Children receive the {@see ENV_KEYS} allowlist
 * rebuilt by {@see childEnvFor()} plus LC_ALL=C — never the parent's
 * whole environment — so decimal points are '.', and are bounded end to
 * end:
 *  - stdout is read until EOF or TIMEOUT; an overrun is SIGKILLed;
 *  - after EOF the exit is polled with proc_get_status for at most
 *    REAP_WAIT (a child can close stdout and keep running), and a child
 *    still alive then is SIGKILLed too — proc_close is only ever called
 *    on a child known to have exited, so it never blocks the frame;
 *  - a child that survives even SIGKILL (uninterruptible sleep) is
 *    abandoned rather than waited on, as in Collect\Gpu.
 * Any of those, or a non-zero exit, is reported as null. Stateless.
 */
final class LiveProbe implements Probe
{
    private const float TIMEOUT = 2.0;
    private const float REAP_WAIT = 0.5;

    /** Base-system locations, tried before PATH (a sanitised PATH may lack /sbin). */
    private const array DIRS = ['/sbin', '/bin', '/usr/sbin', '/usr/bin'];

    /**
     * The child-environment allowlist — the same vocabulary as
     * {@see \SugarCraft\Top\Collect\Process\AsyncCommand}'s (a FreeBSD base
     * tool needs a PATH for ITS helpers, HOME/XDG for caches, TERM, LANG,
     * the LC_ family and TZ to render like the user expects, and the loader
     * path; nothing else).
     * The parent of a TUI is the user's shell: proc_open without this seam
     * handed every child the whole environment, secrets included.
     */
    private const array ENV_KEYS = [
        'PATH', 'HOME', 'TERM', 'LANG', 'TZ',
        'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'XDG_CACHE_HOME',
        'LD_LIBRARY_PATH',
    ];

    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    public function sysctl(array $names): array
    {
        if ($names === []) {
            return [];
        }
        $out = $this->run(self::sysctlArgv($names));

        return $out === null ? [] : Sysctl::parse($out);
    }

    /**
     * The sysctl(8) argv: `-e` prints "name=value" (values-only is `-n`,
     * which would leave Sysctl::parse nothing to key on), `-i` skips
     * unknown OIDs. A pure seam so tests pin the flags without a host.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    public static function sysctlArgv(array $names): array
    {
        return ['sysctl', '-i', '-e', ...$names];
    }

    public function run(array $argv): ?string
    {
        if ($argv === []) {
            return null;
        }
        $binary = self::resolve($argv[0]);
        if ($binary === null) {
            return null;
        }
        $argv[0] = $binary;
        $env = self::childEnvFor(getenv());

        $pipes = [];
        try {
            $process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        } catch (\Error) {
            return null; // proc_open listed in disable_functions
        }
        if (!is_resource($process)) {
            return null;
        }

        stream_set_blocking($pipes[1], false);
        $output = '';
        $deadline = microtime(true) + self::TIMEOUT;
        while (!feof($pipes[1]) && ($left = $deadline - microtime(true)) > 0) {
            $read = [$pipes[1]];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, (int) min(200000, $left * 1e6)) > 0) {
                $output .= (string) fread($pipes[1], 65536);
            }
        }
        $timedOut = !feof($pipes[1]);
        fclose($pipes[1]);

        // proc_get_status reports the exit code only on the first call that
        // sees the child gone; proc_close afterwards returns -1, so keep it.
        $exit = null;
        $reapBy = microtime(true) + self::REAP_WAIT;
        if (!$timedOut) {
            while (($status = proc_get_status($process))['running'] && microtime(true) < $reapBy) {
                usleep(2000);
            }
            $exit = $status['running'] ? null : $status['exitcode'];
        }
        if ($exit === null) {
            proc_terminate($process, 9);
            $reapBy = microtime(true) + self::REAP_WAIT;
            while (proc_get_status($process)['running'] && microtime(true) < $reapBy) {
                usleep(10000);
            }
            if (!proc_get_status($process)['running']) {
                proc_close($process);
            }

            return null;
        }
        proc_close($process);

        return $exit === 0 ? $output : null;
    }

    /**
     * The child environment as a rebuilt allowlist, never the raw parent
     * environment: {@see ENV_KEYS} plus the LC_ family carried over, the
     * base PATH ensured, and LC_ALL pinned to C after the copy so a
     * parent locale cannot outlive it. Pure on purpose — the seam that
     * lets tests pin the rule without touching the process.
     *
     * @param array<string, string> $parentEnv the getenv() map
     *
     * @return array<string, string>
     */
    public static function childEnvFor(array $parentEnv): array
    {
        $env = [];
        foreach ($parentEnv as $key => $value) {
            if (\in_array((string) $key, self::ENV_KEYS, true) || \str_starts_with((string) $key, 'LC_')) {
                $env[(string) $key] = (string) $value;
            }
        }
        // sysctl(8) and friends resolve their own helpers; even a hardened
        // parent without PATH must leave the child able to find the base.
        $env['PATH'] ??= '/usr/bin:/bin';
        $env['LC_ALL'] = 'C';

        return $env;
    }

    public function file(string $path): ?string
    {
        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function space(string $mountpoint): ?array
    {
        $total = @disk_total_space($mountpoint);
        $free = @disk_free_space($mountpoint);

        return $total === false || $free === false ? null : [(int) $total, (int) $free];
    }

    public function monotonic(): float
    {
        return hrtime(true) / 1e9;
    }

    public function epoch(): float
    {
        return microtime(true);
    }

    private static function resolve(string $tool): ?string
    {
        if (str_contains($tool, '/')) {
            return is_file($tool) && is_executable($tool) ? $tool : null;
        }
        $path = (string) getenv('PATH');
        foreach ([...self::DIRS, ...explode(PATH_SEPARATOR, $path)] as $dir) {
            if ($dir !== '' && is_file($dir . '/' . $tool) && is_executable($dir . '/' . $tool)) {
                return $dir . '/' . $tool;
            }
        }

        return null;
    }
}

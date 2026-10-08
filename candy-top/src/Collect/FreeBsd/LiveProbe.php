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
 * window closes with it. Children run under LC_ALL=C so decimal points
 * are '.', and are bounded end to end:
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
        $out = $this->run(['sysctl', '-i', '-e', ...$names]);

        return $out === null ? [] : Sysctl::parse($out);
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
        $env = getenv();
        $env['LC_ALL'] = 'C';

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

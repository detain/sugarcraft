<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

/**
 * The private home of the ipmi reader's SDR cache (`ipmitool sdr dump`).
 *
 * The file is created by candy-top, never by ipmitool: a per-user 0700
 * directory (`candy-top-<euid>` under `$XDG_RUNTIME_DIR` when that is set,
 * else the system temp dir), refused when it is a symlink, owned by
 * someone else or open to others, then the file itself reserved
 * exclusively (`fopen x`) at 0600 BEFORE ipmitool writes into it — so
 * there is no window in which another user can pre-create, read or swap
 * it. Its removal is registered (shutdown hook) at reservation time, not
 * after a successful dump, so a dump that is killed or a candy-top that
 * dies mid-dump still cleans up on a normal exit; a crash that skips the
 * hook is swept by the next start ({@see sweep()}: files of dead pids).
 *
 * Mutable on purpose: it is a filesystem handle, not a value.
 */
final class SdrCache
{
    public const PREFIX = 'candy-top-sdr-';

    /** errno ESRCH: "no such process" from posix_kill(pid, 0). */
    private const ESRCH = 3;

    /** @var list<string> files reserved and not yet released */
    private array $files = [];

    private bool $hooked = false;

    private function __construct(
        public readonly string $dir,
    ) {
    }

    /**
     * The private directory under `$base` (default: `$XDG_RUNTIME_DIR` when
     * it is an absolute writable dir, else the system temp dir), created
     * 0700 if missing; null when it cannot be made private.
     */
    public static function open(?string $base = null): ?self
    {
        $base ??= self::defaultBase();
        if ($base === '' || !is_dir($base) || !is_writable($base)) {
            return null;
        }
        $uid = \function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid();
        $dir = rtrim($base, '/') . '/candy-top-' . $uid;
        if (!file_exists($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) {
            return null;
        }
        clearstatcache(true, $dir);
        $stat = @lstat($dir);
        if ($stat === false || is_link($dir) || ($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== $uid) {
            return null; // a symlink, a file, or another user's directory: never write there
        }
        if (($stat['mode'] & 0077) !== 0 && !@chmod($dir, 0700)) {
            return null;
        }

        return new self($dir);
    }

    private static function defaultBase(): string
    {
        $xdg = (string) getenv('XDG_RUNTIME_DIR');

        return $xdg !== '' && str_starts_with($xdg, '/') && is_dir($xdg) && is_writable($xdg) ? $xdg : sys_get_temp_dir();
    }

    /**
     * Remove the caches left by candy-tops that are gone (killed before
     * their exit hook ran). Files of live pids — another candy-top of the
     * same user — are kept. Returns how many were removed.
     */
    public function sweep(): int
    {
        $removed = 0;
        foreach (glob($this->dir . '/' . self::PREFIX . '*.cache') ?: [] as $file) {
            if (preg_match('/^' . preg_quote(self::PREFIX, '/') . '(\d+)-[0-9a-f]+\.cache$/D', basename($file), $m) !== 1) {
                continue;
            }
            $pid = (int) $m[1];
            if ($pid !== getmypid() && !self::alive($pid) && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Reserve a fresh 0600 file for one dump and register its removal;
     * null when it cannot be created exclusively.
     */
    public function reserve(): ?string
    {
        $file = $this->dir . '/' . self::PREFIX . getmypid() . '-' . bin2hex(random_bytes(4)) . '.cache';
        $old = umask(0077);
        try {
            $handle = @fopen($file, 'x');
        } finally {
            umask($old);
        }
        if ($handle === false) {
            return null;
        }
        fclose($handle);
        @chmod($file, 0600);
        $this->files[] = $file;
        if (!$this->hooked) {
            $this->hooked = true;
            register_shutdown_function($this->releaseAll(...));
        }

        return $file;
    }

    /** Remove one reserved file (a failed dump, or the reader going away). */
    public function release(string $file): void
    {
        if (\in_array($file, $this->files, true)) {
            @unlink($file);
            $this->files = array_values(array_filter($this->files, static fn (string $f): bool => $f !== $file));
        }
    }

    /** Remove every reserved file — the exit hook. */
    public function releaseAll(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    private static function alive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (\function_exists('posix_kill')) {
            // Signal 0 probes without signalling; EPERM still means alive.
            return posix_kill($pid, 0) || posix_get_last_error() !== self::ESRCH;
        }

        return is_dir('/proc/' . $pid) || !is_dir('/proc/self');
    }
}

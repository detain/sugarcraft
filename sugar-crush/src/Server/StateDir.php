<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server;

use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\PrivateDir;
use SugarCraft\Crush\Support\ProcessContainment;

/**
 * `sugarcrush serve`'s state directory (Appendix O §4.6; roadmap O-4a):
 * `~/.sugar-crush/server/`, or `SUGARCRUSH_SERVER_DIR`. Owner-private (0700,
 * verified off `lstat` by {@see PrivateDir}) because everything in it is a
 * credential or a handle on one:
 *
 * | File | What |
 * |---|---|
 * | `token` | the owner token ({@see Auth\TokenStore}), 0600 |
 * | `server.json` | the discovery record ({@see DiscoveryFile}), 0600 |
 * | `server.lock` | the singleton lock, held for the server's whole life |
 * | `control.sock` | the running server's local control socket (`serve url`) |
 * | `server.log` | a detached server's stdout + stderr, rotated at {@see LOG_ROTATE_BYTES} × {@see LOG_FILES} |
 *
 * THE SINGLETON IS AN OS LOCK, not the pidfile: `flock(LOCK_EX | LOCK_NB)` on
 * a descriptor held open for the process lifetime. The kernel drops it when
 * the holder dies however it dies, so a SIGKILLed server never leaves a lock
 * a later start has to be talked past; the discovery record's pid + start time
 * are what `status` and `stop` use to NAME the holder. Not
 * `Support\TimedFileLock`: that is a timed lock for short critical sections,
 * and this one is held for days.
 */
final class StateDir
{
    public const DISCOVERY_FILE = 'server.json';

    public const LOCK_FILE = 'server.lock';

    public const LOG_FILE = 'server.log';

    public const CONTROL_SOCKET = 'control.sock';

    /** A detached server's log is rotated past this size. */
    public const LOG_ROTATE_BYTES = 10 * 1024 * 1024;

    /** `server.log` plus this many minus one rotated generations (`.1`, `.2`). */
    public const LOG_FILES = 3;

    private const LABEL = 'server state';

    /** @var resource|null */
    private $lock = null;

    private function __construct(public readonly string $path)
    {
    }

    /**
     * The state directory at $path, created 0700 when absent.
     *
     * @throws \RuntimeException when it is a symlink, someone else's, or not 0700
     */
    public static function open(string $path): self
    {
        return new self(PrivateDir::ensure(\rtrim($path, '/'), self::LABEL));
    }

    /**
     * The state directory at $path WITHOUT creating it — for the read-only
     * verbs (`status`, `stop`, `logs`, `url`): asking whether a server runs
     * must not leave a directory behind. Null when nothing is there.
     *
     * @throws \RuntimeException when something is there but is not private
     */
    public static function existing(string $path): ?self
    {
        $path = \rtrim($path, '/');
        clearstatcache(true, $path);
        if (@\lstat($path) === false) {
            return null;
        }
        $reason = PrivateDir::refusal($path, self::LABEL);
        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }

        return new self($path);
    }

    public function discoveryPath(): string
    {
        return $this->path . '/' . self::DISCOVERY_FILE;
    }

    public function lockPath(): string
    {
        return $this->path . '/' . self::LOCK_FILE;
    }

    public function logPath(): string
    {
        return $this->path . '/' . self::LOG_FILE;
    }

    public function controlSocketPath(): string
    {
        return $this->path . '/' . self::CONTROL_SOCKET;
    }

    /**
     * Take the singleton lock and keep it until {@see releaseLock()} or this
     * process ends. False when another process holds it — a server is already
     * running from this state directory.
     *
     * The descriptor is close-on-exec, so no tool process inherits it, and
     * registered with {@see ForkedChild}, so a forked turn child closes it
     * first thing: a turn child still holding it after the server died would
     * keep a restart refused for as long as the child lived.
     *
     * @throws \RuntimeException when the lock file cannot be opened at all
     */
    public function acquireLock(): bool
    {
        if ($this->lock !== null) {
            return true;
        }

        $handle = $this->openLockFile();
        if (!\flock($handle, \LOCK_EX | \LOCK_NB)) {
            \fclose($handle);

            return false;
        }

        ProcessContainment::closeOnExec($handle);
        ForkedChild::registerServerStream($handle);
        $this->lock = $handle;

        return true;
    }

    public function holdsLock(): bool
    {
        return $this->lock !== null;
    }

    public function releaseLock(): void
    {
        if ($this->lock === null) {
            return;
        }

        ForkedChild::forgetServerStream($this->lock);
        \flock($this->lock, \LOCK_UN);
        \fclose($this->lock);
        $this->lock = null;
    }

    /**
     * Drop this process's handle on the lock WITHOUT unlocking it: the
     * process that ran `--detach` hands the lock to the daemon it forked,
     * which holds the same open file description — an `LOCK_UN` here would
     * unlock it for the daemon too.
     */
    public function forgetLock(): void
    {
        if ($this->lock === null) {
            return;
        }

        ForkedChild::forgetServerStream($this->lock);
        \fclose($this->lock);
        $this->lock = null;
    }

    /**
     * Whether SOME process holds the singleton lock right now. Probed by
     * trying it: a lock this process could take is no one's, and is let go
     * again at once.
     */
    public function lockHeldElsewhere(): bool
    {
        if ($this->lock !== null) {
            return false;
        }
        if (@\lstat($this->lockPath()) === false) {
            return false;
        }

        try {
            $handle = $this->openLockFile();
        } catch (\RuntimeException) {
            return false;
        }
        $free = \flock($handle, \LOCK_EX | \LOCK_NB);
        if ($free) {
            \flock($handle, \LOCK_UN);
        }
        \fclose($handle);

        return !$free;
    }

    /**
     * Rotate `server.log` when it has grown past $limit bytes: `.1` → `.2`,
     * the live file → `.1`, the oldest generation dropped. Returns whether it
     * rotated; the caller then re-points its stdio at a fresh file
     * ({@see \SugarCraft\Crush\Support\Daemonize::redirectStdio()}).
     */
    public function rotateLog(int $limit = self::LOG_ROTATE_BYTES): bool
    {
        $log = $this->logPath();
        clearstatcache(true, $log);
        $stat = @\lstat($log);
        if ($stat === false || ((int) $stat['mode'] & 0o170000) !== 0o100000 || (int) $stat['size'] <= $limit) {
            return false;
        }

        @\unlink($log . '.' . (self::LOG_FILES - 1));
        for ($generation = self::LOG_FILES - 2; $generation >= 1; --$generation) {
            if (@\lstat($log . '.' . $generation) !== false) {
                @\rename($log . '.' . $generation, $log . '.' . ($generation + 1));
            }
        }

        return @\rename($log, $log . '.1');
    }

    /** @return resource */
    private function openLockFile()
    {
        $path = $this->lockPath();
        $stat = @\lstat($path);
        if ($stat !== false && ((int) $stat['mode'] & 0o170000) !== 0o100000) {
            throw new \RuntimeException(\sprintf('server lock %s is not a regular file', $path));
        }

        $previous = \umask(0o077);
        try {
            $handle = @\fopen($path, 'c');
        } finally {
            \umask($previous);
        }
        if ($handle === false) {
            throw new \RuntimeException(\sprintf('cannot open the server lock %s', $path));
        }

        return $handle;
    }
}

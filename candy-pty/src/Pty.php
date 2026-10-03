<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Posix\PosixMasterPty;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\PosixSlavePty;

/**
 * @deprecated since v0.x, use `SugarCraft\Pty\Posix\PosixPtySystem` instead
 *
 * Mirrors charmbracelet/x/xpty.Open().
 *
 * Usage:
 * ```
 * $pty   = Pty::open();
 * $child = $pty->spawn(['/bin/echo', 'hello']);
 * $exit  = $child->wait();
 * $pty->close();
 * ```
 *
 * The opened {@see Master} is exposed read-only via {@see $master}.
 *
 * @see https://github.com/charmbracelet/x/tree/main/xpty
 */
final class Pty implements MasterPty
{
    public const DEFAULT_COLS = 80;
    public const DEFAULT_ROWS = 24;

    public function __construct(
        public readonly Master $master,
        private readonly PosixMasterPty $impl,
    ) {}

    /**
     * Open a PTY pair through the canonical {@see PosixPtySystem::open()}.
     *
     * This facade used to carry its own `posix_openpt + grantpt + unlockpt
     * + ptsname_r` quartet, and that copy had drifted from the canonical
     * one in two ways that matter: it never set `FD_CLOEXEC` on the master
     * (so every child spawned through it inherited the master fd, the
     * kernel's master-side refcount never reached 0 on close, and
     * `tty_hangup()`/SIGHUP never fired — one leaked master per spawn in
     * a long-lived consumer), and it never applied a winsize at open (so
     * `size()` reported the kernel's 0×0 until the first spawn). Routing
     * through the canonical path means the facade cannot diverge again:
     * cloexec, the Darwin winsize anchor and the open-time resize all
     * come from the one implementation that documents why they exist.
     *
     * @param int $cols initial column count applied at open (default 80)
     * @param int $rows initial row count applied at open (default 24)
     * @throws PtyException when any open step fails (the master fd is
     *         closed before the throw)
     */
    public static function open(int $cols = self::DEFAULT_COLS, int $rows = self::DEFAULT_ROWS): self
    {
        $pair = (new PosixPtySystem())->open($cols, $rows);
        $impl = $pair->master();

        return new self(new Master($impl->fd(), $pair->slave()->path()), $impl);
    }

    /**
     * Spawn `$cmd` on this PTY's slave with the requested geometry.
     *
     * The winsize is applied twice, deliberately. BEFORE the spawn, so a
     * child that queries its size at startup (Linux keeps the winsize
     * across slave opens) sees the requested geometry with no race; a
     * failure here throws before any child exists, so nothing leaks.
     * AFTER the spawn, mirroring {@see PosixSlavePty::spawn()}, because
     * macOS xnu zeroes the winsize when `proc_open` opens fresh slave
     * descriptors — the pre-spawn value alone can be clobbered by the
     * spawn itself. The post-spawn re-assert is best-effort: the child is
     * already running, and throwing would orphan it from the caller.
     *
     * @param list<string> $cmd
     * @param array<string,string>|null $env
     */
    public function spawn(array $cmd, ?array $env = null, int $cols = self::DEFAULT_COLS, int $rows = self::DEFAULT_ROWS, bool $controllingTerminal = false): Child
    {
        $this->impl->resize($cols, $rows);
        $child = Spawn::proc($this->master, $cmd, $env, $controllingTerminal);

        try {
            $this->impl->resize($cols, $rows);
        } catch (PtyException) {
            // Best-effort post-spawn re-assert; the pre-spawn resize above
            // already succeeded, so the geometry is right everywhere the
            // kernel does not reset it on slave open.
        }

        return $child;
    }

    public function resize(int $cols, int $rows): void
    {
        $this->impl->resize($cols, $rows);
    }

    /** @return array{cols: int, rows: int, xpix: int, ypix: int} */
    public function size(): array
    {
        return $this->impl->size();
    }

    /** @return resource */
    public function stream(): mixed
    {
        return $this->impl->stream();
    }

    public function setBlocking(bool $blocking): void
    {
        if (!@\stream_set_blocking($this->impl->stream(), $blocking)) {
            throw new PtyException(Lang::t('stream.set_blocking_failed', [
                'fd'       => $this->master->fd,
                'blocking' => $blocking ? 'true' : 'false',
            ]));
        }
    }

    public function read(int $len = 8192, ?float $timeout = null): ?string
    {
        return $this->impl->read($len, $timeout);
    }

    public function write(string $bytes): int
    {
        return $this->impl->write($bytes);
    }

    public function close(): void
    {
        $this->impl->close();
    }

    public function isClosed(): bool
    {
        return $this->impl->isClosed();
    }

    public function fd(): int
    {
        return $this->impl->fd();
    }
}

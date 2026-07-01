<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Posix\PosixMasterPty;
use SugarCraft\Pty\TermiosFactory;

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

    public static function open(): self
    {
        $libc = Libc::lib();

        $masterFd = $libc->posix_openpt(TermiosFactory::O_RDWR | TermiosFactory::oNoCtty());
        if ($masterFd < 0) {
            throw new PtyException(Lang::t('open.posix_openpt_failed', ['rc' => $masterFd]));
        }

        if ($libc->grantpt($masterFd) !== 0) {
            $libc->close($masterFd);
            throw new PtyException(Lang::t('open.grantpt_failed', ['fd' => $masterFd]));
        }

        if ($libc->unlockpt($masterFd) !== 0) {
            $libc->close($masterFd);
            throw new PtyException(Lang::t('open.unlockpt_failed', ['fd' => $masterFd]));
        }

        $slavePath = self::readPtsName($libc, $masterFd);

        $impl = new PosixMasterPty($masterFd, $slavePath);
        $master = new Master($masterFd, $slavePath);

        return new self($master, $impl);
    }

    /** Read the slave PTY path via `ptsname_r` into a 256-byte buffer. */
    private static function readPtsName(\FFI $libc, int $masterFd): string
    {
        $buf = $libc->new('char[256]');
        $rc = $libc->ptsname_r($masterFd, $buf, 256);
        if ($rc !== 0) {
            $libc->close($masterFd);
            throw new PtyException(Lang::t('open.ptsname_failed', ['fd' => $masterFd]));
        }
        return \FFI::string($buf);
    }

    /** @param list<string> $cmd @param array<string,string>|null $env */
    public function spawn(array $cmd, ?array $env = null, int $cols = self::DEFAULT_COLS, int $rows = self::DEFAULT_ROWS, bool $controllingTerminal = false): Child
    {
        $this->impl->resize($cols, $rows);
        return Spawn::proc($this->master, $cmd, $env, $controllingTerminal);
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

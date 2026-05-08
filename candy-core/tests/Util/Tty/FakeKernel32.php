<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

/**
 * Fake Kernel32 for unit testing WindowsBackend on non-Windows platforms.
 *
 * Handles are plain PHP integers (H_STDIN=10, H_STDOUT=11, H_STDERR=12),
 * avoiding all FFI pointer-handle complexity on Linux.  All other state
 * (modes, codepages, screen-buffer info) is real PHP data.
 *
 * @internal test-only
 */
final class FakeKernel32 implements \SugarCraft\Core\Util\Tty\Kernel32Interface
{
    private const H_STDIN  = 10;
    private const H_STDOUT = 11;
    private const H_STDERR = 12;

    private int|null $consoleModeStdin  = null;
    private int|null $consoleModeStdout = null;
    private int $consoleCpIn  = 437;
    private int $consoleCpOut = 437;
    /** Single snapshot (PR1/PR2 style) or null. */
    private ?array $screenBufferInfo = null;

    /**
     * Optional sequence of snapshots returned across successive calls.
     * Each call to getConsoleScreenBufferInfo() pops the next entry.
     * When exhausted, falls back to $screenBufferInfo (or null).
     *
     * @var list<?array{cols:int,rows:int}>
     */
    private array $screenBufferInfoSequence = [];

    /** @var list<array{0:int,1:int}> handle → mode */
    private array $setConsoleModeCalls = [];

    /** @var list<int> */
    private array $setConsoleCPCalls = [];

    /** @var list<int> */
    private array $setConsoleOutputCPCalls = [];

    // ─── Test lifecycle ─────────────────────────────────────────────────────

    /**
     * Reset all mutable state (call in tearDown).
     *
     * @internal test-only
     */
    public function reset(): void
    {
        $this->consoleModeStdin           = null;
        $this->consoleModeStdout          = null;
        $this->consoleCpIn                = 437;
        $this->consoleCpOut               = 437;
        $this->screenBufferInfo           = null;
        $this->screenBufferInfoSequence   = [];
        $this->setConsoleModeCalls        = [];
        $this->setConsoleCPCalls          = [];
        $this->setConsoleOutputCPCalls    = [];
    }

    // ─── Configurators ───────────────────────────────────────────────────────

    public function setConsoleModeStdin(int $mode): void
    {
        $this->consoleModeStdin = $mode;
    }

    public function setConsoleModeStdout(int $mode): void
    {
        $this->consoleModeStdout = $mode;
    }

    public function setConsoleCpIn(int $cp): void
    {
        $this->consoleCpIn = $cp;
    }

    public function setConsoleCpOut(int $cp): void
    {
        $this->consoleCpOut = $cp;
    }

    public function setScreenBufferInfo(?array $info): void
    {
        $this->screenBufferInfo = $info;
    }

    /**
     * Set a sequence of screen-buffer snapshots returned on successive
     * calls to getConsoleScreenBufferInfo().  First call returns index 0,
     * second call returns index 1, and so on.  After the sequence is
     * exhausted the value falls through to $screenBufferInfo.
     *
     * @param list<?array{cols:int,rows:int}> $sequence
     */
    public function setScreenBufferInfoSequence(array $sequence): void
    {
        $this->screenBufferInfoSequence = $sequence;
    }

    /** @internal for assertions */
    public function getSequenceConsumed(): int
    {
        return \count($this->screenBufferInfoSequence);
    }

    // ─── Queryors (for assertions) ───────────────────────────────────────────

    /** @return list<array{0:int,1:int}> */
    public function getSetConsoleModeCalls(): array
    {
        return $this->setConsoleModeCalls;
    }

    /** @return list<int> */
    public function getSetConsoleCPCalls(): array
    {
        return $this->setConsoleCPCalls;
    }

    /** @return list<int> */
    public function getSetConsoleOutputCPCalls(): array
    {
        return $this->setConsoleOutputCPCalls;
    }

    // ─── Kernel32Interface ───────────────────────────────────────────────────

    public static function self(): \SugarCraft\Core\Util\Tty\Kernel32Interface
    {
        return new self();
    }

    public function ffi(): \FFI
    {
        throw new \LogicException('Not implemented in test double');
    }

    public function getStdHandle(int $nStdHandle): int
    {
        return match ($nStdHandle) {
            self::STD_INPUT_HANDLE  => self::H_STDIN,
            self::STD_OUTPUT_HANDLE => self::H_STDOUT,
            self::STD_ERROR_HANDLE  => self::H_STDERR,
            default => throw new \InvalidArgumentException("Unknown handle: $nStdHandle"),
        };
    }

    public function stdIn(): int
    {
        return self::H_STDIN;
    }

    public function stdOut(): int
    {
        return self::H_STDOUT;
    }

    public function stdErr(): int
    {
        return self::H_STDERR;
    }

    public function getConsoleMode(int $h): int|false
    {
        if ($h === self::H_STDIN && $this->consoleModeStdin !== null) {
            return $this->consoleModeStdin;
        }
        if ($h === self::H_STDOUT && $this->consoleModeStdout !== null) {
            return $this->consoleModeStdout;
        }
        return false;
    }

    public function setConsoleMode(int $h, int $mode): bool
    {
        $this->setConsoleModeCalls[] = [$h, $mode];
        return true;
    }

    public function setConsoleCP(int $cp): bool
    {
        $this->setConsoleCPCalls[] = $cp;
        return true;
    }

    public function setConsoleOutputCP(int $cp): bool
    {
        $this->setConsoleOutputCPCalls[] = $cp;
        return true;
    }

    public function getConsoleCP(): int
    {
        return $this->consoleCpIn;
    }

    public function getConsoleOutputCP(): int
    {
        return $this->consoleCpOut;
    }

    public function getConsoleScreenBufferInfo(int $_h): ?array
    {
        if ($this->screenBufferInfoSequence !== []) {
            return array_shift($this->screenBufferInfoSequence);
        }
        return $this->screenBufferInfo;
    }

    public function createFile(
        string $_name,
        int $_dwDesiredAccess,
        int $_dwShareMode,
        int $_dwCreationDisposition = self::OPEN_EXISTING,
    ): int|false {
        return self::H_STDOUT;
    }

    public function closeHandle(int $_h): bool
    {
        return true;
    }

    public function getLastError(): int
    {
        return 0;
    }

    public function setConsoleCtrlHandler(\Closure $_handler, bool $add = true): bool
    {
        return true;
    }

    public function toWideString(string $str): \FFI\CData
    {
        $len = \mb_strlen($str, 'UTF-8');
        $w = \FFI::new("unsigned short[{$len} + 1]");
        for ($i = 0; $i < $len; $i++) {
            $w[$i] = \mb_ord(\mb_substr($str, $i, 1, 'UTF-8'), 'UTF-8');
        }
        $w[$len] = 0;
        return $w;
    }
}

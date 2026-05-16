<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Tty\PosixBackend;
use SugarCraft\Pty\Contract\Termios;

/**
 * P4.4 seam: PosixBackend accepts an optional pre-built Termios so
 * tests can swap out the libc / stty surface entirely. When null
 * (production path), enableRawMode() still resolves via
 * TermiosFactory just like before.
 */
final class PosixBackendInjectedTermiosTest extends TestCase
{
    public function testEnableRawModeUsesInjectedTermios(): void
    {
        $stub = new SpyTermios();
        $backend = new PosixBackend(\STDIN, $stub);

        $backend->enableRawMode();

        $this->assertSame(1, $stub->currentCalls, 'current() should be called to snapshot for restore');
        $this->assertSame(1, $stub->makeRawCalls, 'makeRaw() should produce the raw-mode copy');
        $this->assertSame(1, $stub->raw->applyCalls, 'apply() should fire on the raw copy');
    }

    public function testRestoreReappliesSavedSnapshot(): void
    {
        $stub = new SpyTermios();
        $backend = new PosixBackend(\STDIN, $stub);

        $backend->enableRawMode();
        $appliedAfterRaw = $stub->raw->applyCalls;

        $backend->restore();

        $this->assertSame(
            1,
            $stub->saved->applyCalls,
            'restore() must apply() the snapshot taken at enableRawMode()',
        );
        $this->assertSame(
            $appliedAfterRaw,
            $stub->raw->applyCalls,
            'restore() must NOT re-call apply() on the raw copy',
        );
    }

    public function testEnableRawModeIsIdempotent(): void
    {
        $stub = new SpyTermios();
        $backend = new PosixBackend(\STDIN, $stub);

        $backend->enableRawMode();
        $backend->enableRawMode();
        $backend->enableRawMode();

        $this->assertSame(1, $stub->makeRawCalls, 'enableRawMode must short-circuit when termios already set');
    }

    public function testRestoreWithoutPriorEnableIsNoop(): void
    {
        $stub = new SpyTermios();
        $backend = new PosixBackend(\STDIN, $stub);

        $backend->restore();

        $this->assertSame(0, $stub->currentCalls);
        $this->assertSame(0, $stub->saved->applyCalls);
    }

    public function testInjectedTermiosWorksEvenWhenStreamIsNotATty(): void
    {
        // Memory stream is not a tty — the production path returns early
        // from enableRawMode(). With an injected Termios the test seam
        // still drives the apply() so unit tests don't need a real PTY.
        $memStream = \fopen('php://memory', 'r+b');
        $this->assertIsResource($memStream);
        $stub = new SpyTermios();
        $backend = new PosixBackend($memStream, $stub);

        $backend->enableRawMode();
        $backend->restore();

        $this->assertSame(1, $stub->makeRawCalls);
        $this->assertSame(1, $stub->raw->applyCalls);
        $this->assertSame(1, $stub->saved->applyCalls);
    }
}

/**
 * In-memory Termios stub. Tracks call counts on itself + on the
 * `makeRaw()` and `current()` returns so the test can assert which
 * instance receives `apply()` at setup vs teardown.
 */
final class SpyTermios implements Termios
{
    public int $currentCalls = 0;
    public int $makeRawCalls = 0;
    public int $applyCalls = 0;
    public int $restoreCalls = 0;

    public SpyTermios $saved;
    public SpyTermios $raw;

    public function __construct(public readonly string $label = 'root')
    {
        // Self-references so the root stub can stand in for current()
        // / makeRaw() returns without separate constructor wiring;
        // overridden in the cloning helpers below.
        $this->saved = $this;
        $this->raw = $this;
    }

    public function current(): self
    {
        $this->currentCalls++;
        $snapshot = new self('snapshot');
        $this->saved = $snapshot;
        return $snapshot;
    }

    public function makeRaw(): self
    {
        $this->makeRawCalls++;
        $raw = new self('raw');
        $this->raw = $raw;
        return $raw;
    }

    public function apply(int $when = self::TCSANOW): void
    {
        $this->applyCalls++;
    }

    public function restore(): void
    {
        $this->restoreCalls++;
    }

    public function isAtty(): bool
    {
        return true;
    }
}

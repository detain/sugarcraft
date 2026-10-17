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

    /**
     * WHAT THIS ASSERTED: `$stub->saved->applyCalls === 1` -- that
     * `PosixBackend::restore()` calls `apply()` on the snapshot.
     *
     * WHAT IS TRUE NOW: it calls `restore()` on it, and the difference is not
     * cosmetic. `apply()` and `restore()` are the same syscall for
     * `PosixTermios` and are NOT the same operation for `SttyTermios`, whose
     * `apply()` opens with `if (!$this->raw) { return; }` -- and a `current()`
     * snapshot is never raw. So the old spelling put the terminal back on the
     * FFI backend and did nothing at all on the `stty` fallback. MEASURED on a
     * real pty; the table is in `PosixBackend::restore()`'s doc-block, and
     * `PosixBackendTest::testRawModeWithSttyFallbackOnRealPty()` is the pin
     * that reads the device rather than a spy.
     *
     * WHY THIS TEST STILL EARNS ITS PLACE: the seam it guards is WHICH
     * INSTANCE is acted on at teardown -- the snapshot, never the raw copy --
     * and that is a `PosixBackend` decision no pty test can isolate. Both
     * halves are still asserted; only the verb moved.
     */
    public function testRestoreReplaysTheSavedSnapshot(): void
    {
        $stub = new SpyTermios();
        $backend = new PosixBackend(\STDIN, $stub);

        $backend->enableRawMode();
        $appliedAfterRaw = $stub->raw->applyCalls;

        $backend->restore();

        $this->assertSame(
            1,
            $stub->saved->restoreCalls,
            'restore() must restore() the snapshot taken at enableRawMode() - apply() on a snapshot '
            . 'is a no-op under the stty backend',
        );
        $this->assertSame(
            0,
            $stub->saved->applyCalls,
            'restore() must not fall back to apply() on the snapshot',
        );
        $this->assertSame(
            $appliedAfterRaw,
            $stub->raw->applyCalls,
            'restore() must NOT re-call apply() on the raw copy',
        );
        $this->assertSame(
            0,
            $stub->raw->restoreCalls,
            'restore() must not reach the raw copy at all',
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

    /**
     * A hung-up terminal (tmux kill-session → SIGHUP, pty master gone) makes
     * the restore tcsetattr fail. Teardown must not throw — Program::run()
     * would never return its model — and the snapshot must be dropped so
     * __destruct() does not throw a second time at shutdown.
     */
    public function testRestoreToleratesAHungUpTerminal(): void
    {
        $stub = new SpyTermios();
        $stub->hungUp = true;
        $backend = new PosixBackend(\STDIN, $stub);
        $backend->enableRawMode();

        $backend->restore();
        $this->assertSame(1, $stub->saved->restoreCalls, 'the restore is still attempted');

        $backend->restore();
        $backend->__destruct();
        $this->assertSame(1, $stub->saved->restoreCalls, 'the failed snapshot is not retried');
    }

    /** A restore failure on a terminal that is still there is a real error. */
    public function testRestoreFailureOnALiveTerminalStillThrows(): void
    {
        $stub = new SpyTermios();
        $stub->failRestore = true;
        $backend = new PosixBackend(\STDIN, $stub);
        $backend->enableRawMode();

        try {
            $backend->restore();
            $this->fail('a live tty restore failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tcsetattr failed', $e->getMessage());
        }
        $backend->__destruct();
        $this->assertSame(1, $stub->saved->restoreCalls, 'dropped anyway: the destructor does not throw it again');
    }

    public function testRestoreWithoutPriorEnableIsNoop(): void
    {
        $stub = new SpyTermios();
        $backend = new PosixBackend(\STDIN, $stub);

        $backend->restore();

        $this->assertSame(0, $stub->currentCalls);
        $this->assertSame(0, $stub->saved->applyCalls);
        $this->assertSame(0, $stub->saved->restoreCalls);
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
        $this->assertSame(1, $stub->saved->restoreCalls);
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

    /** The tty hung up: restore() fails and isAtty() says no (tcgetattr EIO). */
    public bool $hungUp = false;

    /** restore() fails while the tty is still there. */
    public bool $failRestore = false;

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
        $snapshot->hungUp = $this->hungUp;
        $snapshot->failRestore = $this->failRestore;
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
        if ($this->hungUp || $this->failRestore) {
            throw new \RuntimeException('tcsetattr failed on fd 0 with when=0');
        }
    }

    public function isAtty(): bool
    {
        return !$this->hungUp;
    }
}

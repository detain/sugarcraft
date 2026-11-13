<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests\Driver;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Driver\SignalResizeDriver;
use SugarCraft\Input\Event\ResizeEvent;

/**
 * Tests for SignalResizeDriver — SIGWINCH signal-based resize detection.
 */
final class SignalResizeDriverTest extends TestCase
{
    public function testImplementsInputDriver(): void
    {
        $driver = new SignalResizeDriver();

        $this->assertInstanceOf(\SugarCraft\Input\InputDriver::class, $driver);
    }

    public function testReadReturnsNullWhenNoSignal(): void
    {
        $driver = new SignalResizeDriver();
        $result = $driver->read();

        $this->assertNull($result);
    }

    public function testReadReturnsResizeEventAfterRealSignal(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('SIGWINCH round-trip needs pcntl and posix');
        }

        $driver = new SignalResizeDriver();
        $this->assertTrue($driver->arm());

        $this->assertTrue(posix_kill(posix_getpid(), SIGWINCH));
        pcntl_signal_dispatch();

        $event = $driver->read();

        $this->assertInstanceOf(ResizeEvent::class, $event);
        $this->assertGreaterThan(0, $event->cols);
        $this->assertGreaterThan(0, $event->rows);

        $driver->disarm();
    }

    public function testConstructorDoesNotThrow(): void
    {
        $driver = new SignalResizeDriver();

        $this->assertInstanceOf(SignalResizeDriver::class, $driver);
    }

    /**
     * A3b: the ctor used to flip pcntl_async_signals(true), install a SIGWINCH
     * disposition, and shell out to tput twice. Construction must now be a
     * pure store — verified by before/after identity so the pin holds
     * regardless of what earlier tests left armed in this process.
     */
    public function testConstructionAloneTouchesNoGlobalState(): void
    {
        if (!function_exists('pcntl_signal_get_handler')) {
            $this->markTestSkipped('global-state pin needs pcntl_signal_get_handler');
        }

        $calls = 0;
        $runner = static function (string $command) use (&$calls): string {
            $calls++;
            return "80\n";
        };

        $asyncBefore = pcntl_async_signals();
        $handlerBefore = pcntl_signal_get_handler(SIGWINCH);

        new SignalResizeDriver($runner);

        $this->assertSame(0, $calls, 'construction must not shell out to tput');
        $this->assertSame($asyncBefore, pcntl_async_signals());
        $this->assertSame($handlerBefore, pcntl_signal_get_handler(SIGWINCH));
    }

    /**
     * Silence the first arm of the probe ladder so the tput seam is reached.
     *
     * @return callable(): array{cols:int, rows:int}
     */
    private static function ttySilent(): callable
    {
        return static fn (): array => ['cols' => 0, 'rows' => 0];
    }

    public function testArmIsWhatInstallsTheHandler(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->markTestSkipped('arm() installation test needs pcntl');
        }

        $calls = 0;
        $driver = new SignalResizeDriver(static function (string $command) use (&$calls): string {
            $calls++;
            return "80\n";
        }, self::ttySilent());

        $driver->arm();

        $this->assertTrue(pcntl_async_signals(), 'arm() must enable async signals');
        $handler = pcntl_signal_get_handler(SIGWINCH);
        $this->assertNotSame(0, $handler, 'arm() must replace the default disposition');
        $this->assertIsCallable($handler);
        $this->assertSame(2, $calls, 'arm() probes cols + lines once');
        // read() without a signal still returns null for this instance.
        $this->assertNull($driver->read());
    }

    /**
     * When pcntl_signal is not available (Windows, some CI environments),
     * read() must return null without throwing.
     */
    public function testReadReturnsNullWithoutPcntl(): void
    {
        // If pcntl_signal doesn't exist, the driver gracefully returns null
        if (function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl_signal available; this test is for non-pcntl environments');
        }

        $driver = new SignalResizeDriver();
        $result = $driver->read();

        $this->assertNull($result);
    }

    /**
     * The SIGWINCH flag is per-instance: ONE signal must arm EVERY live driver,
     * and one driver's read() must not consume another's event.
     * (Regression: the flag was a private static, so the first read() stole the
     * resize from every other instance and dimensions refreshed only on the
     * last-registered driver.)
     */
    public function testSigwinchFansOutToEveryLiveDriver(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('SIGWINCH fan-out test needs pcntl and posix');
        }

        $callsA = 0;
        $driverA = new SignalResizeDriver(static function (string $command) use (&$callsA): string {
            $callsA++;
            return str_contains($command, 'lines') ? "30\n" : "100\n";
        }, self::ttySilent());
        $callsB = 0;
        $driverB = new SignalResizeDriver(static function (string $command) use (&$callsB): string {
            $callsB++;
            return str_contains($command, 'lines') ? "50\n" : "200\n";
        }, self::ttySilent());

        // Construction is side-effect free; arm() is what joins the fan-out
        // registry and probes cols + lines exactly once per driver.
        $driverA->arm();
        $driverB->arm();
        $this->assertSame(2, $callsA);
        $this->assertSame(2, $callsB);

        // Deliver a real SIGWINCH to this process (arm() installed
        // pcntl_async_signals; the dispatch call flushes any pending signal).
        $this->assertTrue(posix_kill(posix_getpid(), SIGWINCH));
        pcntl_signal_dispatch();

        $eventA = $driverA->read();
        $this->assertInstanceOf(ResizeEvent::class, $eventA);
        $this->assertSame(100, $eventA->cols);
        $this->assertSame(30, $eventA->rows);

        $eventB = $driverB->read();
        $this->assertInstanceOf(
            ResizeEvent::class,
            $eventB,
            'consuming driver A\'s flag must not starve driver B',
        );
        $this->assertSame(200, $eventB->cols);
        $this->assertSame(50, $eventB->rows);

        // Each flag is consumed exactly once, per instance.
        $this->assertNull($driverA->read());
        $this->assertNull($driverB->read());

        // Each instance refreshed its OWN dimensions lazily, in its own
        // read() — the handler only sets the flag (m2). Values land the
        // same either way; the count below now proves the deferral.
        $this->assertSame(4, $callsA);
        $this->assertSame(4, $callsB);
    }

    /**
     * m2: the signal handler must be a bare flag write — no tput forks in
     * async-signal context. The probe pair runs when read() delivers the
     * event, and a coalesced storm still costs exactly one pair.
     */
    public function testDimensionsRefreshInReadNotInSignalContext(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('SIGWINCH timing pin needs pcntl and posix');
        }

        $calls = 0;
        $driver = new SignalResizeDriver(static function (string $command) use (&$calls): string {
            $calls++;
            return "80\n";
        }, self::ttySilent());

        $this->assertTrue($driver->arm(), 'arm() must report the installation (M8)');
        $this->assertSame(2, $calls, 'arm() probes cols + lines once');

        $this->assertTrue(posix_kill(posix_getpid(), SIGWINCH));
        pcntl_signal_dispatch();
        $this->assertSame(2, $calls, 'the handler must not fork tput from signal context (m2)');

        $event = $driver->read();
        $this->assertInstanceOf(ResizeEvent::class, $event);
        $this->assertSame(4, $calls, 'read() refreshes once per delivered event (m2)');

        $this->assertNull($driver->read());
        $this->assertSame(4, $calls, 'an idle read() must not probe again');

        $driver->disarm();
    }

    /**
     * SI-09 pin: when the fork-free tty leg answers, neither an exported
     * (stale) size nor a tput fork may reach the dimensions — the kernel's
     * TIOCGWINSZ answer is the whole ladder.
     */
    public function testTtyLegAnsweringMeansNoForkAndNoStaleEnv(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('fork-free pin needs pcntl and posix');
        }

        $calls = 0;
        $driver = new SignalResizeDriver(
            static function (string $command) use (&$calls): string {
                $calls++;
                return "80\n";
            },
            static fn (): array => ['cols' => 137, 'rows' => 37]
        );

        putenv('COLUMNS=99');
        putenv('LINES=11');
        try {
            $this->assertTrue($driver->arm());
            $this->assertSame(0, $calls, 'a live terminal size needs no tput fork');

            $this->assertTrue(posix_kill(posix_getpid(), SIGWINCH));
            pcntl_signal_dispatch();

            $event = $driver->read();
            $this->assertInstanceOf(ResizeEvent::class, $event);
            $this->assertSame(137, $event->cols);
            $this->assertSame(37, $event->rows);
            $this->assertSame(0, $calls, 'the resize path must still never fork');
        } finally {
            putenv('COLUMNS=');
            putenv('LINES=');
            $driver->disarm();
        }
    }

    /**
     * SI-09 pin: a host the tty probe cannot answer (no controlling
     * terminal) silently descends to the tput last resort — arm() never
     * dies on the probe's own failure.
     */
    public function testTtyProbeThrowingFallsBackToTput(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('fallback pin needs pcntl and posix');
        }

        $driver = new SignalResizeDriver(
            static fn (string $command): string => str_contains($command, 'lines') ? "40\n" : "120\n",
            static function (): array {
                throw new \RuntimeException('no terminal to ask');
            }
        );

        $this->assertTrue($driver->arm(), 'a throwing tty probe must not break arm()');

        $this->assertTrue(posix_kill(posix_getpid(), SIGWINCH));
        pcntl_signal_dispatch();

        $event = $driver->read();
        $this->assertInstanceOf(ResizeEvent::class, $event);
        $this->assertSame(120, $event->cols);
        $this->assertSame(40, $event->rows, 'the tput last resort answers when the tty probe cannot');

        $driver->disarm();
    }

    /**
     * m3: disarm() hands the SIGWINCH disposition back to whoever held it
     * before the driver's first arm() — the process-global mutation is no
     * longer one-way. State is reset via reflection so this test is order-
     * independent of other suites' drivers in the same process.
     */
    public function testDisarmHandsTheSignalDispositionBack(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_signal_get_handler')) {
            $this->markTestSkipped('disposition-restore pin needs pcntl');
        }

        self::resetSigwinchStatics();

        $marker = static function (int $signal): void {
        };
        pcntl_signal(SIGWINCH, $marker);
        $this->assertSame($marker, pcntl_signal_get_handler(SIGWINCH));

        $driver = new SignalResizeDriver(static fn (string $command): string => "80\n", self::ttySilent());
        $this->assertTrue($driver->arm());
        $this->assertNotSame(
            $marker,
            pcntl_signal_get_handler(SIGWINCH),
            'arm() takes the one disposition (documented, not silent)'
        );

        $this->assertTrue($driver->disarm());
        $this->assertSame(
            $marker,
            pcntl_signal_get_handler(SIGWINCH),
            'disarming the last driver restores the pre-arm handler (m3)'
        );

        // Re-arming after a full hand-back works and re-owns the signal.
        $this->assertTrue($driver->arm());
        $this->assertNotSame($marker, pcntl_signal_get_handler(SIGWINCH));

        // Disarming a driver that never registered is a harmless no-op.
        $neverArmed = new SignalResizeDriver(static fn (string $command): string => "80\n", self::ttySilent());
        $this->assertTrue($neverArmed->disarm());

        // Hand the process back clean for any later test.
        $driver->disarm();
        pcntl_signal(SIGWINCH, SIG_DFL);
        self::resetSigwinchStatics();
    }

    private static function resetSigwinchStatics(): void
    {
        $class = new \ReflectionClass(SignalResizeDriver::class);

        $listeners = $class->getProperty('sigwinchListeners');
        $listeners->setValue(null, null);

        $previous = $class->getProperty('sigwinchPrevious');
        $previous->setValue(null, ['captured' => false, 'handler' => 0]);
    }
}

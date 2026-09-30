<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests\Driver;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Driver\SignalResizeDriver;
use SugarCraft\Input\Event\ResizeEvent;
use SugarCraft\Input\Event;

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

    public function testReadReturnsEventOrNull(): void
    {
        $driver = new SignalResizeDriver();
        $result = $driver->read();

        // Must be null, ResizeEvent, or Event based on signal state
        $this->assertTrue(
            $result === null
            || $result instanceof ResizeEvent
            || $result instanceof Event,
            'read() must return null, ResizeEvent, or Event'
        );
    }

    public function testConstructorDoesNotThrow(): void
    {
        $driver = new SignalResizeDriver();

        $this->assertInstanceOf(SignalResizeDriver::class, $driver);
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
        });
        $callsB = 0;
        $driverB = new SignalResizeDriver(static function (string $command) use (&$callsB): string {
            $callsB++;
            return str_contains($command, 'lines') ? "50\n" : "200\n";
        });

        // Each constructor probes cols + lines exactly once.
        $this->assertSame(2, $callsA);
        $this->assertSame(2, $callsB);

        // Deliver a real SIGWINCH to this process (constructors armed
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

        // Each instance refreshed its OWN dimensions when the fan-out fired.
        $this->assertSame(4, $callsA);
        $this->assertSame(4, $callsB);
    }
}

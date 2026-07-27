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
}

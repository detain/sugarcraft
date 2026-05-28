<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Input\Event\KeyEvent;

/**
 * Tests for StreamInputDriver.
 *
 * Uses a pair of piped streams so we can simulate terminal input.
 */
final class StreamInputDriverTest extends TestCase
{
    public function testReadSingleKey(): void
    {
        [$r, $w] = $this->createPipe();

        // Write a single key
        fwrite($w, 'a');
        fclose($w);

        $driver = new StreamInputDriver($r);
        $event = $driver->read();

        $this->assertInstanceOf(KeyEvent::class, $event);
        $this->assertSame('a', $event->key);
    }

    public function testReadEscapeSequence(): void
    {
        [$r, $w] = $this->createPipe();

        fwrite($w, "\x1b[C"); // ArrowRight
        fclose($w);

        $driver = new StreamInputDriver($r);
        $event = $driver->read();

        $this->assertInstanceOf(KeyEvent::class, $event);
        $this->assertSame('ArrowRight', $event->key);
    }

    public function testReadMultipleEvents(): void
    {
        [$r, $w] = $this->createPipe();

        fwrite($w, "abc");
        fclose($w);

        $driver = new StreamInputDriver($r);

        // First read returns first character
        $event1 = $driver->read();
        $this->assertInstanceOf(KeyEvent::class, $event1);
        $this->assertSame('a', $event1->key);

        // Subsequent reads get remaining characters
        $event2 = $driver->read();
        $this->assertInstanceOf(KeyEvent::class, $event2);
        $this->assertSame('b', $event2->key);

        $event3 = $driver->read();
        $this->assertInstanceOf(KeyEvent::class, $event3);
        $this->assertSame('c', $event3->key);

        // EOF
        $event4 = $driver->read();
        $this->assertNull($event4);
    }

    public function testReadReturnsNullOnEof(): void
    {
        [$r, $w] = $this->createPipe();
        fclose($w);

        $driver = new StreamInputDriver($r);
        $event = $driver->read();

        // After reading all data and hitting EOF, next read returns null
        $this->assertNull($event);
    }

    public function testReadReturnsNullOnEmptyNonBlockingStream(): void
    {
        [$r, $w] = $this->createPipe();
        fclose($w);

        $driver = new StreamInputDriver($r);

        // Calling read() on an exhausted stream should return null
        $this->assertNull($driver->read());
    }

    public function testReadWithPartialSequenceThenEmpty(): void
    {
        [$r, $w] = $this->createPipe();

        // Write just CSI '[' prefix (incomplete sequence)
        // This will be stored as remainder when decode can't complete it
        fwrite($w, "\x1b[");
        fclose($w);

        $driver = new StreamInputDriver($r);

        // First read processes the partial \x1b[ - EscapeDecoder may or may not
        // have a partial remainder depending on implementation
        $event = $driver->read();

        // If decode returned [] on the partial, we get null here.
        // If decode processed \x1b as Escape key, we get a KeyEvent.
        // Either outcome is valid - the important thing is the method completes.
        $this->assertNull($event);
    }

    public function testReadWithBareEscapeCharacter(): void
    {
        [$r, $w] = $this->createPipe();

        // Write just the escape character - this is a valid plain Escape key
        fwrite($w, "\x1b");
        fclose($w);

        $driver = new StreamInputDriver($r);

        $event = $driver->read();

        // Escape character should produce a KeyEvent with key='Escape'
        $this->assertInstanceOf(KeyEvent::class, $event);
        $this->assertSame('Escape', $event->key);
    }

    /**
     * Create a non-blocking pipe pair for testing.
     *
     * @return array{0: resource, 1: resource}
     */
    private function createPipe(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) {
            $this->fail('Failed to create pipe pair');
        }

        // Set non-blocking mode so reads don't hang
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);

        return $pair;
    }
}

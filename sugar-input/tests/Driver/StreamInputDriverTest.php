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
    /**
     * Stream ends handed out by createPipe(); tearDown closes whatever a
     * test body forgot, so the suite leaks no fds.
     *
     * @var list<resource>
     */
    private array $openStreams = [];

    protected function tearDown(): void
    {
        foreach ($this->openStreams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->openStreams = [];
    }

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

    /**
     * The real law for a parked partial: an incomplete CSI stays in the
     * decoder's remainder — read() returns null without an EOF panic, and
     * nothing phantom surfaces at the caller.
     */
    public function testReadWithPartialSequenceThenEmpty(): void
    {
        [$r, $w] = $this->createPipe();

        // Write just CSI '[' prefix (incomplete sequence)
        fwrite($w, "\x1b[");
        fclose($w);

        $driver = new StreamInputDriver($r);

        $this->assertNull($driver->read(), 'the parked partial must never surface as a phantom event');
        $this->assertTrue($driver->hasPending(), 'the incomplete CSI stays pending — a continuation byte could still complete it');
        $this->assertSame("\x1b[", $driver->escapeDecoder()->remainder());
        $this->assertNull($driver->read(), 'EOF with a parked partial returns null, never an EOF panic');
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
     * A3b: the driver takes over a blocking caller stream for its lifetime and
     * must hand the blocking flag back when destroyed — not leave the caller's
     * resource silently non-blocking forever.
     */
    public function testDestructRestoresBlockingFlag(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);
        [$r, $w] = $pair;

        $this->assertTrue(stream_get_meta_data($r)['blocked']);

        $driver = new StreamInputDriver($r);
        $this->assertFalse(stream_get_meta_data($r)['blocked'], 'ctor must clear blocking for its reads');

        unset($driver);
        $this->assertTrue(stream_get_meta_data($r)['blocked'], 'destruct must restore the caller flag we found');

        fclose($r);
        fclose($w);
    }

    public function testDestructLeavesAlreadyNonBlockingStreamAlone(): void
    {
        [$r, $w] = $this->createPipe(); // already non-blocking

        $driver = new StreamInputDriver($r);
        unset($driver);

        $this->assertFalse(stream_get_meta_data($r)['blocked'], 'a stream we did not flip must not be flipped back');

        fclose($r);
        fclose($w);
    }

    /**
     * n4: the constructor is the boundary — a non-resource is a usage error
     * refused loudly, never a read() that limps along on garbage.
     */
    public function testRejectsNonResourceStream(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('StreamInputDriver needs a readable stream resource, got string.');

        new StreamInputDriver('not a resource');
    }

    /**
     * M1/M3: the decoder seam + EOF/idle distinction. A burst of mixed event
     * sizes drains strictly in order (the cursor never reindexes already-issued
     * events), then the closed peer turns the null into a provable EOF.
     */
    public function testDecoderSeamEofAndOrderedDrain(): void
    {
        [$r, $w] = $this->createPipe();

        fwrite($w, "AB\x1b[A");
        fclose($w);

        $driver = new StreamInputDriver($r);

        $a = $driver->read();
        $this->assertInstanceOf(KeyEvent::class, $a);
        $this->assertSame('A', $a->key);

        $b = $driver->read();
        $this->assertInstanceOf(KeyEvent::class, $b);
        $this->assertSame('B', $b->key);

        $arrow = $driver->read();
        $this->assertInstanceOf(KeyEvent::class, $arrow);
        $this->assertSame('ArrowUp', $arrow->key);

        // Buffered events handed out, stream at EOF: read() answers null and
        // isEof() is now the truth the null alone could not carry.
        $this->assertNull($driver->read());
        $this->assertTrue($driver->isEof());
        $this->assertFalse($driver->hasPending());

        // M1 seam: hosts reach the decoder for reset()/counters/flush.
        $decoder = $driver->escapeDecoder();
        $this->assertSame($decoder, $driver->escapeDecoder());
        $decoder->reset();
    }

    /**
     * M3: a live stream with a half-arrived sequence is idle-with-pending,
     * never EOF — the law the class docblock teaches.
     */
    public function testPartialSequenceIsPendingNotEof(): void
    {
        [$r, $w] = $this->createPipe();

        fwrite($w, "\x1b["); // head of a CSI sequence, final byte missing

        $driver = new StreamInputDriver($r);
        $this->assertNull($driver->read());
        $this->assertTrue($driver->hasPending());
        $this->assertFalse($driver->isEof());

        // Completing the sequence makes the held remainder decode live.
        fwrite($w, "C");
        $right = $driver->read();
        $this->assertInstanceOf(KeyEvent::class, $right);
        $this->assertSame('ArrowRight', $right->key);

        fclose($w);
    }

    /**
     * M1: options given to the driver actually reach its decoder — a host that
     * disables mouse protocol must see the SGR frame refused at the gate
     * (counted drop), not a MouseEvent on the wire.
     */
    public function testOptionsThreadThroughToDecoder(): void
    {
        [$r, $w] = $this->createPipe();

        $options = new \SugarCraft\Input\EscapeDecoderOptions(enableMouse: false);
        $driver = new StreamInputDriver($r, $options);

        fwrite($w, "\x1b[<0;10;10M");
        fclose($w);

        $first = $driver->read();
        $this->assertNull($first, 'mouse frames must not decode while enableMouse is off');
        $this->assertGreaterThanOrEqual(1, $driver->escapeDecoder()->droppedUnknownCount());
    }

    public function testSelectErrorAndIdleAreDistinguishableBranches(): void
    {
        // SI-04: the POSIX leg must split stream_select's `false` (hard
        // select error — surfaced) from its `0` (idle — ''), and the Windows
        // leg must bypass stream_select for a direct fread. A real select
        // error is not injectable on Linux (PHP 8 raises TypeError on dead
        // resources before select ever answers), so the branch shape is
        // pinned from the genuine method source.
        $ref = new \ReflectionMethod(StreamInputDriver::class, 'readNonBlocking');
        $src = implode('', array_slice(
            file($ref->getFileName()),
            $ref->getStartLine() - 1,
            $ref->getEndLine() - $ref->getStartLine() + 1,
        ));

        $this->assertStringContainsString('if ($changed === false) {', $src, 'false arm must be its own branch');
        $this->assertStringContainsString('throw new \\RuntimeException', $src, 'the false arm must surface, not launder into idle');
        $this->assertStringNotContainsString('$changed === false || $changed === 0', $src);
        $this->assertStringContainsString("PHP_OS_FAMILY === 'Windows'", $src, 'Windows must skip stream_select entirely');
        $this->assertLessThan(
            strpos($src, '$read = [$this->stream];'),
            strpos($src, "PHP_OS_FAMILY === 'Windows'"),
            'the Windows direct-read arm must precede the select arm',
        );

        // Idle really still reads as idle (no throw) on a live quiet stream.
        [$r, $w] = $this->createPipe();
        $driver = new StreamInputDriver($r);
        $this->assertNull($driver->read());
        fclose($w);
        fclose($r);
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

        $this->openStreams[] = $pair[0];
        $this->openStreams[] = $pair[1];

        return $pair;
    }
}

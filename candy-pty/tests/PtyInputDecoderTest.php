<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use SugarCraft\Pty\Input\PtyInputDecoder;
use SugarCraft\Pty\Contract\MasterPty;
use PHPUnit\Framework\TestCase;

final class PtyInputDecoderTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    public function testReadEventsReturnsEmptyOnTimeout(): void
    {
        $master = new FakePtyForInputDecoder('');
        $decoder = new PtyInputDecoder($master);

        $events = $decoder->readEvents(0.001);
        $this->assertSame([], $events);
    }

    public function testReadEventsReturnsEmptyOnEof(): void
    {
        $master = new FakePtyForInputDecoder('', eof: true);
        $decoder = new PtyInputDecoder($master);

        $events = $decoder->readEvents(0.001);
        $this->assertSame([], $events);
    }

    public function testRemainderReturnsDecoderRemainder(): void
    {
        $master = new FakePtyForInputDecoder('');
        $decoder = new PtyInputDecoder($master);

        $remainder = $decoder->remainder();
        $this->assertSame('', $remainder);
    }

    public function testResetClearsDecoderState(): void
    {
        $master = new FakePtyForInputDecoder('');
        $decoder = new PtyInputDecoder($master);

        $decoder->reset();
        $this->assertSame('', $decoder->remainder());
    }

    public function testReadEventsBlockingReturnsEmptyOnTimeout(): void
    {
        $master = new FakePtyForInputDecoder('');
        $decoder = new PtyInputDecoder($master);

        $events = $decoder->readEventsBlocking(0.001);
        $this->assertSame([], $events);
    }

    public function testReadEventsBlockingReturnsEventsWhenAvailable(): void
    {
        $master = new FakePtyForInputDecoder("a");
        $decoder = new PtyInputDecoder($master);

        $events = $decoder->readEventsBlocking(0.01);
        $this->assertNotSame([], $events);
    }

    public function testReadEventsDecodesEscapeKeyFromSingleEscapeByte(): void
    {
        $master = new FakePtyForInputDecoder("\x1b");
        $decoder = new PtyInputDecoder($master);

        $events = $decoder->readEvents(0.001);
        $this->assertNotSame([], $events);
        $this->assertCount(1, $events);
        $this->assertSame('Escape', $events[0]->key);
    }
}

final class FakePtyForInputDecoder implements MasterPty
{
    public function __construct(
        private string $initial = '',
        private bool $eof = false,
    ) {}

    public function read(int $len = 8192, ?float $timeout = null): ?string
    {
        if ($this->initial !== '') {
            $out = $this->initial;
            $this->initial = '';
            return $out;
        }
        if ($this->eof) {
            return '';
        }
        return null;
    }

    public function write(string $bytes): int
    {
        return \strlen($bytes);
    }

    public function resize(int $cols, int $rows): void {}

    public function size(): array
    {
        return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0];
    }

    public function stream(): mixed
    {
        return null;
    }

    public function close(): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function fd(): int
    {
        return -1;  // sentinel invalid fd for test fixture
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Format;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\AsciinemaFormat;

/**
 * @covers \SugarCraft\Vcr\Format\AsciinemaFormat
 */
final class AsciinemaFormatTest extends TestCase
{
    private AsciinemaFormat $format;

    protected function setUp(): void
    {
        $this->format = new AsciinemaFormat();
    }

    public function testReadValidV3Cast(): void
    {
        $path = $this->writeCastFile([
            '{"version": 3, "term": {"width": 80, "height": 24}, "timestamp": 1700000000}',
            '[0.0, "o", "hello world\r\n"]',
            '[0.5, "i", "q"]',
            '[0.6, "x", 0]',
        ]);

        try {
            $cassette = $this->format->read($path);

            $this->assertSame(1, $cassette->header->version);
            $this->assertSame(80, $cassette->header->cols);
            $this->assertSame(24, $cassette->header->rows);
            $this->assertSame('asciinema/v3', $cassette->header->runtime);
            $this->assertSame(3, $cassette->eventCount());

            $events = $cassette->events;
            $this->assertSame(EventKind::Output, $events[0]->kind);
            $this->assertSame('hello world' . "\r\n", $events[0]->payload['b']);
            $this->assertSame(0.0, $events[0]->t);

            $this->assertSame(EventKind::Input, $events[1]->kind);
            $this->assertSame('q', $events[1]->payload['b']);
            $this->assertEqualsWithDelta(0.5, $events[1]->t, 0.001);

            $this->assertSame(EventKind::Quit, $events[2]->kind);
            $this->assertEqualsWithDelta(1.1, $events[2]->t, 0.001);
        } finally {
            @unlink($path);
        }
    }

    public function testReadCumulativeTimestamps(): void
    {
        // asciinema v3 uses relative timestamps (intervals)
        $path = $this->writeCastFile([
            '{"version": 3, "term": {"width": 80, "height": 24}}',
            '[0.1, "o", "a"]',
            '[0.2, "o", "b"]',  // 0.2s after previous, so absolute t = 0.3
            '[0.3, "o", "c"]',  // 0.3s after previous, so absolute t = 0.6
        ]);

        try {
            $cassette = $this->format->read($path);
            $events = $cassette->events;

            $this->assertEqualsWithDelta(0.1, $events[0]->t, 0.001);
            $this->assertEqualsWithDelta(0.3, $events[1]->t, 0.001); // 0.1 + 0.2
            $this->assertEqualsWithDelta(0.6, $events[2]->t, 0.001); // 0.3 + 0.3
        } finally {
            @unlink($path);
        }
    }

    public function testInvalidVersionThrows(): void
    {
        $path = $this->writeCastFile([
            '{"version": 2, "term": {"width": 80, "height": 24}}',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('asciinema format version 2 not supported');

        try {
            $this->format->read($path);
        } finally {
            @unlink($path);
        }
    }

    public function testEmptyFileThrows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asciinema-');
        file_put_contents($path, '');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty');

        try {
            $this->format->read($path);
        } finally {
            @unlink($path);
        }
    }

    public function testInvalidJsonThrows(): void
    {
        $path = $this->writeCastFile([
            'not valid json',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->format->read($path);
        } finally {
            @unlink($path);
        }
    }

    public function testUnknownEventTypeIsSkipped(): void
    {
        $path = $this->writeCastFile([
            '{"version": 3, "term": {"width": 80, "height": 24}}',
            '[0.0, "o", "hello"]',
            '[0.1, "z", "unknown"]', // Should be skipped
            '[0.2, "x", 0]',
        ]);

        try {
            $cassette = $this->format->read($path);

            $this->assertSame(2, $cassette->eventCount());
            $this->assertSame(EventKind::Output, $cassette->events[0]->kind);
            $this->assertSame(EventKind::Quit, $cassette->events[1]->kind);
        } finally {
            @unlink($path);
        }
    }

    public function testHeaderTimestampBecomesCreatedAt(): void
    {
        $path = $this->writeCastFile([
            '{"version": 3, "term": {"width": 80, "height": 24}, "timestamp": 1700000000}',
            '[0.0, "x", 0]',
        ]);

        try {
            $cassette = $this->format->read($path);

            $this->assertSame('2023-11-14T22:13:20Z', $cassette->header->createdAt);
        } finally {
            @unlink($path);
        }
    }

    public function testResizeEventInAsciinema(): void
    {
        // asciinema doesn't have resize events, but we should handle
        // the case where there are no output events
        $path = $this->writeCastFile([
            '{"version": 3, "term": {"width": 120, "height": 40}}',
            '[0.0, "x", 0]',
        ]);

        try {
            $cassette = $this->format->read($path);

            $this->assertSame(120, $cassette->header->cols);
            $this->assertSame(40, $cassette->header->rows);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Write a temporary cast file with the given lines.
     *
     * @param list<string> $lines
     */
    private function writeCastFile(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'asciinema-');
        $this->assertNotFalse($path);
        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }
}

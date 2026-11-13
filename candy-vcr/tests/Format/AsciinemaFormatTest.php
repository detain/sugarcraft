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

    /**
     * Cast v2 — the shape asciinema itself writes — must import. Pinning the
     * old refusal here would document a promise the README cannot keep, so
     * this test now asserts the acceptance and the top-level dimensions.
     */
    public function testReadRealWorldV2CastWithTopLevelDims(): void
    {
        $path = $this->writeCastFile([
            '{"version": 2, "width": 100, "height": 30, "timestamp": 1700000000, "idle_time_limit": 1.5, "title": "demo"}',
            '[0.0, "o", "$ "]',
            '[1.25, "i", "ls\r"]',
            '[0.5, "r", "ignored-by-design"]',
            '[2.0, "x", 0]',
        ]);

        try {
            $cassette = $this->format->read($path);

            $this->assertSame(100, $cassette->header->cols);
            $this->assertSame(30, $cassette->header->rows);
            $this->assertSame('asciinema/v2', $cassette->header->runtime);
            $this->assertSame('2023-11-14T22:13:20Z', $cassette->header->createdAt);

            // The unknown `r` arm is skipped; the three known kinds survive.
            $this->assertSame(3, $cassette->eventCount());
            $this->assertSame(EventKind::Output, $cassette->events[0]->kind);
            $this->assertSame(EventKind::Input, $cassette->events[1]->kind);
            $this->assertSame(EventKind::Quit, $cassette->events[2]->kind);
            $this->assertEqualsWithDelta(1.25, $cassette->events[1]->t, 0.001);
        } finally {
            @unlink($path);
        }
    }

    public function testUnsupportedVersionThrows(): void
    {
        $path = $this->writeCastFile([
            '{"version": 1, "width": 80, "height": 24}',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('asciinema format version 1 not supported (only v2 and v3)');

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

    public function testSkippedRowTimeSurvivesIntoFollowingEvents(): void
    {
        // VCR-S33 (finding 063): 'r'/'m'/unknown rows emit nothing, but
        // their offsets are wall time the session actually consumed —
        // the following events must land at their true tape position,
        // not at the clock of the last EMITTED row.
        $path = $this->writeCastFile([
            '{"version": 2, "width": 80, "height": 24}',
            '[1.0, "o", "a"]',
            '[2.0, "r", "100x30"]', // resize: no event, time counts
            '[0.5, "o", "b"]',
            '[4.0, "m", "checkpoint"]', // marker: no event, time counts
            '[1.0, "x", 0]',
        ]);

        try {
            $cassette = $this->format->read($path);

            $this->assertSame(3, $cassette->eventCount());
            $this->assertEqualsWithDelta(1.0, $cassette->events[0]->t, 0.001, 'o a');
            // 1.0 + 2.0 (skipped r) + 0.5 — the pre-fix reader dropped the
            // skipped delta here and pinned b at 1.5.
            $this->assertEqualsWithDelta(3.5, $cassette->events[1]->t, 0.001, 'o b keeps its position');
            // 3.5 + 4.0 (skipped m) + 1.0 — pre-fix: 2.5.
            $this->assertEqualsWithDelta(8.5, $cassette->events[2]->t, 0.001, 'quit after marker');
        } finally {
            @unlink($path);
        }
    }

    public function testZeroOffsetRowsAfterSkipsKeepTruthyTime(): void
    {
        // A skipped row must not zero the following clock: two immediate
        // same-instant rows after a resize keep the last truthy t.
        $path = $this->writeCastFile([
            '{"version": 2, "width": 80, "height": 24}',
            '[2.0, "o", "a"]',
            '[0.0, "r", "100x30"]',
            '[0.0, "o", "b"]',
        ]);

        try {
            $cassette = $this->format->read($path);

            $this->assertEqualsWithDelta(2.0, $cassette->events[1]->t, 0.001, 'following t stays truthy');
        } finally {
            @unlink($path);
        }
    }

    public function testThrowingLoadDoesNotLeakFileHandles(): void
    {
        // VCR-S33 (finding 036): a mid-file throw used to strand the open
        // handle — the fclose sat after the loop and the header/field doors
        // threw past it. A failed-load hammer must keep the fd census flat.
        if (!is_dir('/proc/self/fd')) {
            $this->markTestSkipped('fd census requires /proc/self/fd (Linux)');
        }

        $path = $this->writeCastFile([
            '{"version": 2, "width": 80, "height": 24}',
            '[[1], "o", "x"]', // valid header, array time offset — Scalars door throws
        ]);

        try {
            $baseline = count(scandir('/proc/self/fd'));
            $attempts = 0;
            for ($i = 0; $i < 20; $i++) {
                try {
                    $this->format->read($path);
                    $this->fail('expected the poison line to throw');
                } catch (\InvalidArgumentException) {
                    $attempts++;
                }
            }
            $this->assertSame(20, $attempts);
            $after = count(scandir('/proc/self/fd'));

            $this->assertLessThanOrEqual($baseline + 1, $after, 'failed loads must not stack descriptors');
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
